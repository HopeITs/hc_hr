# Time-clock devices that push their punches (ZKTeco ADMS)

Each center's ZKTeco device has **Menu → Comm. → Cloud Server Setting** with *Server Mode: ADMS*. In that
mode the device calls a server by itself: it says hello with its serial number, is told where to start,
and posts its attendance log (everything it holds the first time, then each new punch). No report to
download and no visit to the center.

The system side is `routes/iclock.php` → `IclockController` → `App\Services\Attendance\DevicePush`, and the
page **Attendance → Devices** (`attendance.devices`, `attendance.manage`).

## Setting up a device

On the device (Comm. → Cloud Server Setting):

| Field | Value |
|---|---|
| Server Mode | ADMS |
| Enable Domain Name | ON |
| Server Address | the system's domain, e.g. `hr.example.org` (no `https://`) |
| Server Port | `443` |
| HTTPS | ON |
| Enable Proxy Server | OFF |

The device needs a network with internet. Within a minute it appears under **Devices waiting to be added**
(its serial number, the address it called from). HR chooses its center and adds it. From then on it sends
everything it holds, then each punch as it happens.

Until a device is added, the system answers it `403 UNREGISTERED`. The device **keeps its own records** and
tries again, so nothing is lost while it waits.

## What the receiver does

- `GET /iclock/cdata?SN=…` hello: answers with the options, including `ATTLOGStamp=` (the position the device
  last reported, or `None` the first time, which means "send everything").
- `POST /iclock/cdata?SN=…&table=ATTLOG&Stamp=…` the punches, one per line (`PIN<tab>time<tab>…`). Answered
  `OK:<lines>`. Other tables (operation log, users, photos) are acknowledged and ignored.
- `GET /iclock/getrequest`, `POST /iclock/devicecmd` are acknowledged (the system sends no commands).
- A punch is stored once (device number + minute is unique), so a retry or a second hello never doubles it.
  A punch in a **locked month** is skipped, and a new punch in an **approved month** withdraws the approval:
  the same `AttendanceImporter::store()` routine an uploaded report uses.
- The PIN on the device is the employee's number (it is the employee's ID, or a number HR linked by hand).
  A number that is nobody's yet is kept and shows under the upload page's "unlinked numbers".
- No session, no cookies, no login: the routes use the `api` group and the `iclock` rate limit. A device is
  believed by its **registered serial number only**; an unknown serial is rate-limited and remembered.

## Before it can work on the real host

1. **The host's WebGuard.** `CLAUDE.md` notes that the shared host challenges addresses that are not on its
   whitelist (412/429). Centers' internet addresses are not the office's and may change, so a device would be
   challenged and never get through. `/iclock/*` must be exempt from that challenge on the server (done by
   hand in the server's `public/.htaccess`, like the rest of the whitelist). Without this, nothing arrives.
2. **A valid HTTPS certificate** on the domain (the device's HTTPS option is ON by default). If a device model
   will not talk TLS to the host, turn HTTPS OFF on it and use port 80, but only if the host allows plain HTTP
   for `/iclock/*`.
3. `php artisan migrate` (adds `attendance_devices`, `attendance_device_sightings`, `device_id` columns).

## Trying it before the host is ready

From a computer on the same network as a device:

```bash
php artisan serve --host=0.0.0.0 --port=8081
```

Allow port 8081 through the computer's firewall, then on the device set Server Address to the computer's
address (e.g. `192.168.1.67`), Port `8081`, HTTPS **OFF**, Enable Domain Name OFF. The device appears on the
Devices page. Change the device back when done.

## The LAN agent (when the device cannot push)

A device that never calls the cloud server (or a center whose network blocks it) is read by a small program on a
computer in the same network: `tools/attendance-agent` (see its README). On **Attendance → Devices** make a sync key
for the device (shown once), put it in the agent's `config.json`, and schedule `node agent.cjs` every 10 minutes.
The agent calls `GET /api/attendance/agent/state` (where the website's punches reach) and
`POST /api/attendance/agent/punches` (batches of up to 2000), with `Authorization: Bearer zk_…`. The same rules
apply as for a push: once per device number + minute, locked months skipped, approvals withdrawn.

## Not here

- Names of the people on the device are not read yet (they would let the system suggest who each number is).
- A device with no internet at its center cannot push. For that center, use the device's USB export with the
  file upload page.
