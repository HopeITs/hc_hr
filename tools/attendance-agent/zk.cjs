'use strict';
/**
 * A small, read-only client for ZKTeco time-clock devices (TCP, port 4370), with no dependencies.
 *
 * It only ever READS the attendance log and the device's serial number. It never clears the log,
 * never changes a setting, never disables the device. The device's own records are untouched.
 *
 * Protocol in short: every packet is  50 50 82 7D | length (u32) | command (u16) | checksum (u16) |
 * session (u16) | reply counter (u16) | data. The attendance log is asked for with a "prepare buffer"
 * command, then read in chunks, each answered by PREPARE_DATA, DATA… and ACK_OK frames.
 */
const net = require('net');

const CMD = {
  CONNECT: 1000, EXIT: 1001, AUTH: 1102, OPTIONS_RRQ: 11, ATTLOG_RRQ: 13, GET_FREE_SIZES: 50,
  ACK_OK: 2000, ACK_ERROR: 2001, ACK_DATA: 2002, ACK_UNAUTH: 2005,
  PREPARE_DATA: 1500, DATA: 1501, FREE_DATA: 1502, PREPARE_BUFFER: 1503, READ_BUFFER: 1504,
};
const USHRT_MAX = 65535;
const PREFIX = Buffer.from([0x50, 0x50, 0x82, 0x7d]);
const MAX_CHUNK = 0xffc0;

/**
 * The packet checksum: the one's complement of the sum of the 16-bit words (an odd last byte counts alone),
 * with any carry added back in. Some open-source clients use a formula that is off by one for certain
 * packets; devices of this family (firmware 6.x) check it strictly and then ignore the packet.
 */
function checksum(buf) {
  let sum = 0;
  for (let i = 0; i + 1 < buf.length; i += 2) sum += buf.readUInt16LE(i);
  if (buf.length % 2) sum += buf[buf.length - 1];
  while (sum > 0xffff) sum = (sum & 0xffff) + (sum >>> 16);
  return ~sum & 0xffff;
}

/** The answer to the device's "who are you?" (communication key 0 unless one is set), as the official program sends it. */
function makeCommKey(key, session, ticks = 50) {
  let k = 0;
  for (let i = 0; i < 32; i++) k = ((k << 1) | ((key >>> i) & 1)) >>> 0;
  k = (k + session) >>> 0;
  const b = Buffer.alloc(4);
  b.writeUInt32LE(k);
  b[0] ^= 0x5a; b[1] ^= 0x4b; b[2] ^= 0x53; b[3] ^= 0x4f;
  const swapped = Buffer.from([b[2], b[3], b[0], b[1]]);
  const t = ticks & 0xff;
  return Buffer.from([swapped[0] ^ t, swapped[1] ^ t, t, swapped[3] ^ t]);
}

/** The device packs a time into one number: seconds, minutes, hours, days (31 a month), months (12 a year). */
function decodeTime(t) {
  const second = t % 60; t = Math.floor(t / 60);
  const minute = t % 60; t = Math.floor(t / 60);
  const hour = t % 24; t = Math.floor(t / 24);
  const day = (t % 31) + 1; t = Math.floor(t / 31);
  const month = (t % 12) + 1; t = Math.floor(t / 12);
  const year = t + 2000;
  const p = (n) => String(n).padStart(2, '0');
  return `${year}-${p(month)}-${p(day)} ${p(hour)}:${p(minute)}:${p(second)}`;
}

/** Where, inside a 40-byte record, the time sits: the offset at which the first records decode to sane dates. */
function detectTimeOffset(body, size) {
  const sample = Math.min(20, Math.floor(body.length / size));
  for (const offset of [27, 28]) {
    let ok = sample > 0;
    for (let i = 0; i < sample && ok; i++) {
      const year = Number(decodeTime(body.readUInt32LE(i * size + offset)).slice(0, 4));
      ok = year >= 2010 && year <= 2100;
    }
    if (ok) return offset;
  }
  throw new Error('Could not tell where the time is inside a record on this device.');
}

const cString = (buf) => {
  const end = buf.indexOf(0);
  return buf.subarray(0, end === -1 ? buf.length : end).toString('utf8').trim();
};

class ZkClient {
  constructor(host, port = 4370, timeoutMs = 10000, commKey = 0) {
    this.commKey = commKey >>> 0;
    this.host = host;
    this.port = port;
    this.timeoutMs = timeoutMs;
    this.sessionId = 0;
    this.replyId = 0;
    this.buffer = Buffer.alloc(0);
    this.waiters = [];
    this.socket = null;
    this.error = null;
  }

  connect() {
    return new Promise((resolve, reject) => {
      const socket = net.createConnection({ host: this.host, port: this.port });
      this.socket = socket;
      socket.setTimeout(this.timeoutMs);
      socket.once('connect', async () => {
        try {
          const reply = await this.request(CMD.CONNECT);
          if (reply.cmd !== CMD.ACK_OK && reply.cmd !== CMD.ACK_UNAUTH) throw new Error(`Unexpected answer to connect: ${reply.cmd}`);
          this.sessionId = reply.session;
          if (reply.cmd === CMD.ACK_UNAUTH) {
            const auth = await this.request(CMD.AUTH, makeCommKey(this.commKey, this.sessionId));
            if (auth.cmd !== CMD.ACK_OK) throw new Error('The device refused the communication key (Comm Key).');
          }
          resolve(this);
        } catch (e) { reject(e); }
      });
      socket.on('data', (chunk) => { this.buffer = Buffer.concat([this.buffer, chunk]); this.flush(); });
      socket.on('error', (e) => { this.error = e; this.flush(); reject(e); });
      socket.on('timeout', () => { this.error = new Error('The device did not answer in time.'); socket.destroy(); this.flush(); });
      socket.on('close', () => { this.error = this.error || new Error('The connection closed.'); this.flush(); });
    });
  }

  /** Hand complete frames to whoever is waiting. */
  flush() {
    while (this.waiters.length) {
      const frame = this.takeFrame();
      if (frame) { this.waiters.shift().resolve(frame); continue; }
      if (this.error) { this.waiters.shift().reject(this.error); continue; }
      break;
    }
  }

  takeFrame() {
    const b = this.buffer;
    if (b.length < 8) return null;
    if (!b.subarray(0, 4).equals(PREFIX)) throw new Error('Not a ZKTeco frame (bad header).');
    const len = b.readUInt32LE(4);
    if (b.length < 8 + len) return null;
    const payload = b.subarray(8, 8 + len);
    this.buffer = b.subarray(8 + len);
    return { cmd: payload.readUInt16LE(0), session: payload.readUInt16LE(4), reply: payload.readUInt16LE(6), data: payload.subarray(8) };
  }

  nextFrame() {
    return new Promise((resolve, reject) => {
      this.waiters.push({ resolve, reject });
      try { this.flush(); } catch (e) { this.waiters.pop(); reject(e); }
    });
  }

  send(cmd, data = Buffer.alloc(0)) {
    const body = Buffer.alloc(8 + data.length);
    body.writeUInt16LE(cmd, 0);
    body.writeUInt16LE(0, 2);
    body.writeUInt16LE(this.sessionId, 4);
    body.writeUInt16LE(this.replyId, 6);
    data.copy(body, 8);
    body.writeUInt16LE(checksum(body), 2);
    this.replyId += 1;
    if (this.replyId >= USHRT_MAX) this.replyId -= USHRT_MAX;
    const head = Buffer.alloc(8);
    PREFIX.copy(head, 0);
    head.writeUInt32LE(body.length, 4);
    this.socket.write(Buffer.concat([head, body]));
  }

  async request(cmd, data) { this.send(cmd, data); return this.nextFrame(); }

  async serial() {
    const r = await this.request(CMD.OPTIONS_RRQ, Buffer.from('~SerialNumber\0'));
    const text = cString(r.data);
    return text.includes('=') ? text.split('=').slice(1).join('=') : text;
  }

  async counts() {
    const r = await this.request(CMD.GET_FREE_SIZES);
    if (r.data.length < 36) throw new Error('Unexpected answer to the sizes request.');
    return { users: r.data.readInt32LE(16), records: r.data.readInt32LE(32) };
  }

  /** One chunk of the prepared buffer: PREPARE_DATA, then DATA frame(s), then ACK_OK. */
  async readChunk(start, size) {
    const q = Buffer.alloc(8);
    q.writeInt32LE(start, 0);
    q.writeInt32LE(size, 4);
    this.send(CMD.READ_BUFFER, q);

    const parts = [];
    let first = await this.nextFrame();
    if (first.cmd === CMD.DATA) {
      parts.push(first.data);
    } else if (first.cmd === CMD.PREPARE_DATA) {
      const want = first.data.readUInt32LE(0);
      let have = 0;
      while (have < want) {
        const f = await this.nextFrame();
        if (f.cmd === CMD.DATA) { parts.push(f.data); have += f.data.length; }
        else if (f.cmd === CMD.ACK_OK) break;
        else throw new Error(`Unexpected frame while reading: ${f.cmd}`);
      }
      if (have >= want) { const ack = await this.nextFrame(); if (ack.cmd !== CMD.ACK_OK) throw new Error(`Expected ACK_OK, got ${ack.cmd}`); }
    } else {
      throw new Error(`Unexpected answer to a read: ${first.cmd}`);
    }
    return Buffer.concat(parts);
  }

  async readBuffer(command) {
    const prep = Buffer.alloc(11);
    prep.writeInt8(1, 0);
    prep.writeInt16LE(command, 1);
    prep.writeInt32LE(0, 3);
    prep.writeInt32LE(0, 7);
    const r = await this.request(CMD.PREPARE_BUFFER, prep);

    if (r.cmd === CMD.DATA) return r.data;
    if (r.cmd !== CMD.ACK_OK) throw new Error(`The device refused to prepare the log: ${r.cmd}`);

    const size = r.data.readUInt32LE(1);
    const parts = [];
    for (let start = 0; start < size; start += MAX_CHUNK) parts.push(await this.readChunk(start, Math.min(MAX_CHUNK, size - start)));
    await this.request(CMD.FREE_DATA).catch(() => {});
    return Buffer.concat(parts);
  }

  /** Every punch the device holds: [{ pin, at: 'YYYY-MM-DD HH:MM:SS', status, punch }]. Read only. */
  async attendance() {
    const { records } = await this.counts();
    if (!records) return [];
    const raw = await this.readBuffer(CMD.ATTLOG_RRQ);
    const total = raw.readUInt32LE(0);
    const body = raw.subarray(4, 4 + total);
    const size = Math.floor(body.length / records);
    const out = [];
    const timeAt = size === 40 ? detectTimeOffset(body, size) : 0;

    for (let i = 0; i + size <= body.length; i += size) {
      const r = body.subarray(i, i + size);
      if (size === 40) {
        // uid(2) device number(24) status(1) then the time (4 bytes). Firmware differs on where the time
        // starts (27 on a ZMM720 with 6.60, 28 on others), so it is found once from the first records.
        out.push({ pin: cString(r.subarray(2, 26)), status: r[26], punch: timeAt === 27 ? r[31] : r[27], at: decodeTime(r.readUInt32LE(timeAt)) });
      } else if (size === 16) {
        out.push({ pin: String(r.readUInt32LE(0)), at: decodeTime(r.readUInt32LE(4)), status: r[8], punch: r[9] });
      } else if (size === 8) {
        out.push({ pin: String(r.readUInt16LE(0)), status: r[2], at: decodeTime(r.readUInt32LE(3)), punch: r[7] });
      } else {
        throw new Error(`Unknown record size: ${size}`);
      }
    }
    return out;
  }

  async close() {
    try { if (this.socket && !this.socket.destroyed) { this.send(CMD.EXIT); } } catch (e) { /* closing anyway */ }
    if (this.socket) this.socket.destroy();
  }
}

module.exports = { ZkClient, decodeTime, checksum, makeCommKey, CMD };
