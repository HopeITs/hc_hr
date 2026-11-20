# Attendance agent

Reads a ZKTeco device on the **same network as this computer** (read only, never writes to the device) and sends
its new punches to the HR website every few minutes. One agent per center / device. No dependencies: Node 18+.

## Set up (once per device)

1. On the website: **Attendance → Devices**, row of the device → **Create a key**. Copy it now; it is shown once.
2. On a computer in that center (always on, same network as the device): copy this folder, then
   `copy config.example.json config.json` and fill in the device's IP (the device shows it under Comm. → Ethernet),
   the Comm Key if one is set (else 0), and the key.
3. Try it without sending: `node agent.cjs --dry-run`. Then for real: `node agent.cjs` (the first run sends the
   device's whole log; later runs only what is new).
4. Schedule it every 10 minutes (Windows, in an administrator prompt; adjust the paths):

```
schtasks /Create /TN "HR attendance agent" /SC MINUTE /MO 10 /RU SYSTEM /TR "\"C:\Program Files\nodejs\node.exe\" \"C:\hr-agent\agent.cjs\""
```

The result of each run is in `agent.log` next to `config.json`, and the Devices page shows "Last sync".

## Good to know

- Resending is harmless: the website keeps each punch once (device number + minute), and by default the agent
  re-sends the last 48 hours (`safetyHours`) in case punches arrived late.
- If the website or the network is down, nothing is lost: the device keeps its log and the next run catches up.
- A month HR has locked takes no new punches (they are counted as "locked" in the log).
- Revoking or replacing the key on the Devices page stops the agent at once (`invalid_key`).
- `node probe.cjs <ip>` shows what a device holds (counts, oldest/newest punch), without names.
