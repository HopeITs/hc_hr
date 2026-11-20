#!/usr/bin/env node
'use strict';
/**
 * Attendance agent: reads the punches of a ZKTeco device on THIS network (read only) and sends the new ones to
 * the HR website with the device's sync key. One run = one sync; schedule it every 10 minutes (README.md).
 * No dependencies: node 18+ only.
 *
 *   node agent.cjs [--config path/to/config.json] [--full] [--dry-run]
 *
 *   --full     send everything the device holds (default: only what is newer than the website already has,
 *              less a safety window; resending is harmless, the website keeps each punch once)
 *   --dry-run  read the device and the website's state, send nothing
 *
 * The device keeps its own log, so when the website or the network is down nothing is lost: the next run
 * sends what is missing.
 */

const fs = require('fs');
const path = require('path');
const { ZkClient } = require('./zk.cjs');

const args = process.argv.slice(2);
const flag = (name) => args.includes(name);
const option = (name) => { const i = args.indexOf(name); return i >= 0 ? args[i + 1] : undefined; };

const configPath = path.resolve(option('--config') || path.join(__dirname, 'config.json'));
const logPath = path.join(path.dirname(configPath), 'agent.log');

function log(message) {
  const line = `${new Date().toISOString().replace('T', ' ').slice(0, 19)}  ${message}`;
  console.log(line);
  try {
    // Keep the log small: when it passes 1 MB, start a fresh one.
    if (fs.existsSync(logPath) && fs.statSync(logPath).size > 1_000_000) fs.renameSync(logPath, `${logPath}.old`);
    fs.appendFileSync(logPath, `${line}\n`);
  } catch { /* the log is a convenience */ }
}

function fail(message) {
  log(`ERROR: ${message}`);
  process.exit(1);
}

function loadConfig() {
  let raw;
  try { raw = JSON.parse(fs.readFileSync(configPath, 'utf8')); } catch (e) { fail(`cannot read ${configPath}: ${e.message}`); }

  const cfg = {
    host: raw.device?.host,
    port: Number(raw.device?.port || 4370),
    commKey: Number(raw.device?.commKey || 0),
    timeoutMs: Number(raw.device?.timeoutSeconds || 15) * 1000,
    url: String(raw.server?.url || '').replace(/\/+$/, ''),
    key: raw.server?.key,
    safetyHours: Number(raw.safetyHours ?? 48),
  };

  if (!cfg.host) fail('config: device.host is missing');
  if (!cfg.url || !/^https?:\/\//.test(cfg.url)) fail('config: server.url must start with http:// or https://');
  if (!cfg.key || !String(cfg.key).startsWith('zk_')) fail('config: server.key must be the sync key made on the Devices page (zk_…)');

  return cfg;
}

async function call(cfg, method, route, body) {
  const response = await fetch(`${cfg.url}/api/attendance/agent/${route}`, {
    method,
    headers: { Authorization: `Bearer ${cfg.key}`, Accept: 'application/json', 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
    signal: AbortSignal.timeout(60_000),
  });

  let json = null;
  try { json = await response.json(); } catch { /* not JSON */ }

  if (!response.ok) {
    const why = json?.error || `HTTP ${response.status}`;
    throw new Error(`${route}: ${why}${json?.details ? ` ${JSON.stringify(json.details)}` : ''}`);
  }

  return json;
}

/** 'YYYY-MM-DD HH:MM:SS' (device local time) minus hours, as the same format. Pure arithmetic: no time zone. */
function minusHours(stamp, hours) {
  const m = /^(\d{4})-(\d\d)-(\d\d) (\d\d):(\d\d):(\d\d)$/.exec(stamp);
  if (!m) return null;
  const t = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]) - hours * 3600_000);
  return t.toISOString().replace('T', ' ').slice(0, 19);
}

async function main() {
  const cfg = loadConfig();
  const started = Date.now();

  let state;
  try {
    state = await call(cfg, 'GET', 'state');
  } catch (e) {
    fail(`the website refused or did not answer: ${e.message}`);
  }

  const client = new ZkClient(cfg.host, cfg.port, cfg.timeoutMs, cfg.commKey);
  let records;
  let serial;

  try {
    await client.connect();
    serial = await client.serial();
    records = await client.attendance();
  } catch (e) {
    fail(`cannot read the device at ${cfg.host}:${cfg.port}: ${e.message}`);
  } finally {
    await client.close().catch(() => {});
  }

  if (serial && state.serial && serial !== state.serial) {
    fail(`this device is ${serial} but the key belongs to ${state.serial}; nothing sent`);
  }

  // Only what is newer than the website holds, less a safety window for punches that arrived late.
  const since = flag('--full') || !state.last_punch_at ? null : minusHours(state.last_punch_at, cfg.safetyHours);
  const fresh = records.filter((r) => since === null || r.at >= since);

  log(`device ${serial || '?'}: ${records.length} punches held, ${fresh.length} to send${since ? ` (since ${since})` : ' (all)'}`);

  if (flag('--dry-run')) { log('dry run: nothing sent'); return; }

  const batch = Math.min(Number(state.max_batch) || 2000, 2000);
  let added = 0, locked = 0, rejected = 0;

  for (let i = 0; i < fresh.length; i += batch) {
    const part = fresh.slice(i, i + batch).map((r) => ({ pin: String(r.pin), at: r.at }));
    let result;

    try {
      result = await call(cfg, 'POST', 'punches', { serial, punches: part });
    } catch (e) {
      fail(`sending failed after ${added} new punches: ${e.message} (the next run will send the rest)`);
    }

    added += result.new; locked += result.locked; rejected += result.rejected;
  }

  if (fresh.length === 0) await call(cfg, 'POST', 'punches', { serial, punches: [] }).catch(() => {}); // a heartbeat

  log(`sent ${fresh.length}: ${added} new, ${locked} in locked months, ${rejected} rejected (${((Date.now() - started) / 1000).toFixed(1)}s)`);
}

main().catch((e) => fail(e.stack || e.message));
