'use strict';
/**
 * Read-only look at a device: its serial number, how many punches it holds, from when to when.
 * Shows counts and dates only (no names). Changes nothing on the device.
 *
 *   node probe.js 192.168.1.201 [port]
 */
const { ZkClient } = require('./zk.cjs');

(async () => {
  const host = process.argv[2];
  const port = Number(process.argv[3] || 4370);

  if (!host) { console.error('Usage: node probe.js <device-ip> [port]'); process.exit(2); }

  const zk = new ZkClient(host, port);
  try {
    await zk.connect();
    console.log('connected');
    console.log('serial number :', await zk.serial());
    const counts = await zk.counts();
    console.log('users / punches the device says it holds :', counts.users, '/', counts.records);

    const punches = await zk.attendance();
    console.log('punches read  :', punches.length);

    if (punches.length) {
      const times = punches.map((p) => p.at).sort();
      const day = new Date(Date.now() - 24 * 3600 * 1000).toISOString().slice(0, 10);
      console.log('oldest punch  :', times[0]);
      console.log('newest punch  :', times[times.length - 1]);
      console.log('distinct device numbers :', new Set(punches.map((p) => p.pin)).size);
      console.log('punches since', day, ':', times.filter((t) => t.slice(0, 10) >= day).length);
    }
  } catch (e) {
    console.error('FAILED:', e.message);
    process.exitCode = 1;
  } finally {
    await zk.close();
  }
})();
