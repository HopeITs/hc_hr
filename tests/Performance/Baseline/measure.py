#!/usr/bin/env python3
"""X1 performance baseline: browser measurements for four flows.

    python -I tests/Performance/Baseline/measure.py --perf-home <PERF_HOME> --out <dir>

Starts and stops its own instrumented server (router.php) against the
synthetic database built by build-fixtures.php, so build that first. Needs
Python Playwright with its bundled Chromium (`pip install playwright`,
`python -m playwright install chromium`).

Conditions, per flow and profile:
  cold         server restarted, compiled views and app cache cleared, new
               browser context (empty HTTP cache). First visit after a deploy.
  first-visit  server warm, new browser context. A user's first visit.
  warm         server warm, same browser context after one unrecorded
               priming visit. A repeat visit.

Times measured inside the page come from performance.now() (the page's own
clock), never from this script's wall clock. Server time and query counts
come from the router's Server-Timing / X-Perf-* headers.
"""

import argparse
import datetime
import json
import os
import platform
import socket
import sqlite3
import statistics
import subprocess
import sys
import threading
import time
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from playwright.sync_api import sync_playwright

REPO = Path(__file__).resolve().parents[3]
HOST, PORT = "127.0.0.1", 8390
WORKERS = 6
BASE = f"http://{HOST}:{PORT}"

# Chrome DevTools' "Slow 4G" preset (formerly "Fast 3G"), written out so the
# profile does not depend on whatever a future DevTools calls by that name.
SLOW_4G = {
    "offline": False,
    "latency": 562.5,              # ms added per request (150 ms x 3.75)
    "downloadThroughput": 180000,  # bytes/s = 1.44 Mbit/s (1.6 Mbit/s x 0.9)
    "uploadThroughput": 84375,     # bytes/s = 675 kbit/s (750 kbit/s x 0.9)
}

PROFILES = {
    "desktop": {
        "context": {"viewport": {"width": 1440, "height": 900}, "device_scale_factor": 1},
        "network": None,  # loopback, unthrottled
        "cpu": 1,
        "load_cap_ms": 120_000,
    },
    "phone": {
        "context": {
            "viewport": {"width": 375, "height": 812},
            "device_scale_factor": 3,
            "is_mobile": True,
            "has_touch": True,
            "user_agent": "Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 "
                          "(KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36",
        },
        "network": SLOW_4G,
        "cpu": 4,  # Lighthouse's mobile CPU slowdown
        "load_cap_ms": 420_000,
    },
}

# Each flow: who, where, what "useful" means, and an optional interaction.
FLOWS = {
    "dashboard": {
        "persona": "hr",
        "path": "/",
        "content": '[data-tour="dashboard.team"]',
        "ready": None,
        "interaction": None,
    },
    "employee_search": {
        "persona": "hr",
        "path": "/employees",
        "content": 'tr[data-tour="table.row"]',
        "ready": None,
        "interaction": "search",
    },
    "contract_create": {
        "persona": "hr",
        "path": "/contracts/create",
        "content": '[x-data^="contractComposer"]',
        # The composer is usable once its city's options have arrived.
        "ready": """(() => {
            const root = document.querySelector('[x-data^="contractComposer"]');
            if (!root || !window.Alpine) return false;
            const d = window.Alpine.$data(root);
            return d && d.options && d.options.employees.length > 0 && !d.loading;
        })()""",
        "interaction": "pick_employee",
    },
    "leave_form": {
        "persona": "staff",
        "path": "/leaves",
        "content": '[data-tour="leaves.request"]',
        "ready": None,
        "interaction": "open_leave_form",
    },
}

# Installed before any page script runs. Records marks on the page clock and
# tracks Livewire requests (Livewire uses fetch) for interaction timing.
INIT_JS = r"""
(() => {
  const X = window.__x1 = { marks: {}, lw: { inflight: 0, done: [] }, lcp: null, cls: 0 };
  const mark = (k, t) => { if (!(k in X.marks)) X.marks[k] = t ?? performance.now(); };
  X.mark = mark;
  const CONTENT = __CONTENT__;
  const READY = __READY__;

  document.addEventListener('DOMContentLoaded', () => mark('dcl'));
  document.addEventListener('livewire:init', () => mark('livewire_init'));
  document.addEventListener('livewire:initialized', () => mark('livewire_initialized'));
  window.addEventListener('load', () => mark('load'));

  try {
    new PerformanceObserver((list) => { for (const e of list.getEntries()) X.lcp = e.startTime; })
      .observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((list) => { for (const e of list.getEntries()) if (!e.hadRecentInput) X.cls += e.value; })
      .observe({ type: 'layout-shift', buffered: true });
  } catch (e) {}

  const origFetch = window.fetch;
  window.fetch = function (input, init) {
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    if (!url.includes('/livewire')) return origFetch.apply(this, arguments);
    const start = performance.now();
    let names = null;
    try {
      // Names only (component, updated properties, called methods), no values.
      names = JSON.parse(init.body).components.map(c => ({
        component: (JSON.parse(c.snapshot).memo || {}).name,
        updates: Object.keys(c.updates || {}).sort(),
        calls: (c.calls || []).map(call => call.method),
      }));
    } catch (e) {}
    X.lw.inflight++;
    const finish = () => { X.lw.inflight--; X.lw.done.push({ start, end: performance.now(), names }); };
    return origFetch.apply(this, arguments).then(async (res) => {
      // Count the request as finished once its body has been read, which is
      // what Livewire waits for before morphing.
      try { await res.clone().text(); } catch (e) {}
      finish();
      return res;
    }, (err) => { finish(); throw err; });
  };

  const visible = (el) => {
    if (!el) return false;
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
  };

  const poll = () => {
    const overlay = document.getElementById('page-gif-overlay');
    if (overlay && overlay.style.opacity === '0') mark('overlay_fade_start');
    if (overlay && overlay.style.display === 'none') mark('overlay_hidden');
    if (CONTENT && visible(document.querySelector(CONTENT))) mark('content_visible');
    if (READY) { try { if (eval(READY)) mark('flow_ready'); } catch (e) {} }
    const m = X.marks;
    const done = 'overlay_hidden' in m && 'content_visible' in m && (!READY || 'flow_ready' in m);
    if (done) {
      mark('first_useful', Math.max(m.overlay_hidden, m.content_visible, READY ? m.flow_ready : 0));
    } else {
      requestAnimationFrame(poll);
    }
  };
  requestAnimationFrame(poll);
})();
"""

COLLECT_JS = r"""
(() => {
  const nav = performance.getEntriesByType('navigation')[0] || {};
  const paint = Object.fromEntries(performance.getEntriesByType('paint').map(p => [p.name, p.startTime]));
  return {
    nav: {
      ttfb: nav.responseStart, response_end: nav.responseEnd,
      dcl: nav.domContentLoadedEventEnd, load: nav.loadEventEnd || null,
      transfer_size: nav.transferSize, decoded_body_size: nav.decodedBodySize,
    },
    fcp: paint['first-contentful-paint'] ?? null,
    lcp: window.__x1.lcp, cls: window.__x1.cls,
    marks: window.__x1.marks,
    dom_nodes: document.getElementsByTagName('*').length,
    images: document.images.length,
  };
})()
"""


def port_open(port=None):
    with socket.socket() as s:
        s.settimeout(0.3)
        return s.connect_ex((HOST, port or PORT)) == 0


class Proxy:
    """Front door on PORT, spreading requests over WORKERS php -S processes.

    php -S serves one request at a time. Behind a single one, the ~190
    avatar requests on the dashboard would queue in front of the page's own
    scripts, an artefact of the harness rather than of the app. Production's
    Apache serves requests in parallel; six workers match the six connections
    a browser opens per host over HTTP/1.1. Each request goes to the least
    busy worker. Bodies are passed through unchanged (already compressed by
    router.php where .htaccess would compress).
    """

    HOP = {"connection", "keep-alive", "proxy-connection", "transfer-encoding", "content-length", "te", "upgrade"}

    def __init__(self, ports):
        self.ports = ports
        self.busy = {p: 0 for p in ports}
        self.free = threading.Condition()
        self.errors = []
        proxy = self

        class Handler(BaseHTTPRequestHandler):
            protocol_version = "HTTP/1.1"

            def log_message(self, *args):
                pass

            def forward(self):
                length = int(self.headers.get("Content-Length") or 0)
                body = self.rfile.read(length) if length else None
                port = proxy.acquire()
                try:
                    conn = http.client.HTTPConnection(HOST, port, timeout=600)
                    headers = {k: v for k, v in self.headers.items() if k.lower() not in Proxy.HOP}
                    conn.request(self.command, self.path, body=body, headers=headers)
                    res = conn.getresponse()
                    data = res.read()
                    conn.close()
                except OSError as e:
                    # A worker killed by a cold restart mid-request. Reported to
                    # the browser as a 502, which the run records as failed.
                    proxy.errors.append(f"{self.command} {self.path[:80]}: {e}")
                    self.send_error(502)
                    return
                finally:
                    proxy.release(port)
                self.send_response_only(res.status, res.reason)
                for k, v in res.getheaders():
                    if k.lower() not in Proxy.HOP:
                        self.send_header(k, v)
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                if self.command != "HEAD":
                    try:
                        self.wfile.write(data)
                    except OSError:
                        pass  # the browser closed the page mid-response

            do_GET = do_POST = do_HEAD = do_PUT = do_PATCH = do_DELETE = forward

        self.httpd = ThreadingHTTPServer((HOST, PORT), Handler)
        self.httpd.daemon_threads = True
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def acquire(self):
        # php -S on Windows resets connections queued behind a busy request,
        # so each worker only ever holds one; the rest wait here instead.
        with self.free:
            self.free.wait_for(lambda: any(n == 0 for n in self.busy.values()))
            port = next(p for p in self.ports if self.busy[p] == 0)
            self.busy[port] = 1
            return port

    def release(self, port):
        with self.free:
            self.busy[port] = 0
            self.free.notify()

    def close(self):
        self.httpd.shutdown()
        self.httpd.server_close()


class Server:
    def __init__(self, perf_home: Path):
        self.perf_home = perf_home
        self.procs = []
        self.ports = [PORT + 1 + i for i in range(WORKERS)]
        self.proxy = None

    def start(self):
        for port in [PORT] + self.ports if self.proxy is None else self.ports:
            if port_open(port):
                sys.exit(f"Port {port} is already in use; stop that server first.")
        env = dict(os.environ, PERF_HOME=str(self.perf_home).replace("\\", "/"), PERF_PORT=str(PORT))
        log = open(self.perf_home / "server.log", "ab")
        for port in self.ports:
            self.procs.append(subprocess.Popen(
                ["php", "-d", "opcache.enable_cli=1", "-d", "opcache.validate_timestamps=0",
                 "-S", f"{HOST}:{port}", "-t", "public", "tests/Performance/Baseline/router.php"],
                cwd=REPO, env=env, stdout=log, stderr=subprocess.STDOUT,
            ))
        for _ in range(100):
            if all(port_open(p) for p in self.ports):
                break
            time.sleep(0.1)
        else:
            sys.exit("Workers did not start; see server.log")
        if self.proxy is None:
            self.proxy = Proxy(self.ports)

    def stop(self, proxy=False):
        for proc in self.procs:
            proc.kill()
            proc.wait()
        self.procs = []
        for _ in range(50):
            if not any(port_open(p) for p in self.ports):
                break
            time.sleep(0.1)
        if proxy and self.proxy:
            self.proxy.close()
            self.proxy = None

    def clear_caches(self):
        """Compiled Blade views and the application cache (database store)."""
        for f in (self.perf_home / "storage/framework/views").glob("*.php"):
            f.unlink()
        db = sqlite3.connect(self.perf_home / "perf.sqlite")
        db.execute("delete from cache")
        db.execute("delete from cache_locks")
        db.commit()
        db.close()


class Network:
    """Per-page request log from the DevTools protocol (wire bytes, headers)."""

    def __init__(self, cdp):
        self.req = {}
        cdp.on("Network.requestWillBeSent", self.sent)
        cdp.on("Network.responseReceived", self.received)
        cdp.on("Network.loadingFinished", self.finished)
        cdp.on("Network.loadingFailed", lambda e: self.req.get(e["requestId"], {}).update(failed=e.get("errorText", "failed")))
        cdp.on("Network.requestServedFromCache", lambda e: self.req.get(e["requestId"], {}).update(cache="memory/disk"))

    def sent(self, e):
        r = e["request"]
        self.req[e["requestId"]] = {
            "url": r["url"].replace(BASE, ""), "method": r["method"], "type": e.get("type"),
            "t_sent": e["timestamp"], "cache": None,
        }
        if "/livewire" in r["url"] and r.get("postData"):
            self.req[e["requestId"]]["livewire"] = livewire_names(r["postData"])

    def received(self, e):
        rec = self.req.get(e["requestId"])
        if rec is None:
            return
        res = e["response"]
        h = {k.lower(): v for k, v in res.get("headers", {}).items()}
        rec.update(status=res["status"], mime=res.get("mimeType"), t_response=e["timestamp"])
        if res.get("fromDiskCache") or res.get("fromServiceWorker"):
            rec["cache"] = "disk"
        if "server-timing" in h:
            parts = dict(p.strip().split(";dur=") for p in h["server-timing"].split(",") if ";dur=" in p)
            rec["server_ms"] = float(parts.get("app", 0))
            rec["db_ms"] = float(parts.get("db", 0))
        if "x-perf-queries" in h:
            rec["queries"] = int(h["x-perf-queries"])
        if "x-perf-raw-bytes" in h:
            rec["raw_bytes"] = int(h["x-perf-raw-bytes"])

    def finished(self, e):
        rec = self.req.get(e["requestId"])
        if rec is not None:
            rec["wire_bytes"] = int(e.get("encodedDataLength", 0))
            rec["t_done"] = e["timestamp"]

    @staticmethod
    def kind(url):
        if url.startswith("http"):
            return "third_party"
        if url.startswith("/livewire") and "/update" in url:
            return "livewire"
        if url.startswith("/employee-photo/"):
            return "avatar"
        if url.startswith("/build/"):
            return "build"
        if url.startswith("/livewire"):
            return "livewire_js"
        return "other"

    def summary(self, document_path, since=None):
        rows = [r for r in self.req.values() if since is None or r["t_sent"] >= since]
        out = {"requests": len(rows), "wire_bytes": sum(r.get("wire_bytes", 0) for r in rows), "by_kind": {}}
        for r in rows:
            k = "document" if r["type"] == "Document" else self.kind(r["url"])
            b = out["by_kind"].setdefault(k, {"count": 0, "wire_bytes": 0, "server_ms": [], "queries": [], "from_cache": 0, "failed": 0, "statuses": {}})
            b["count"] += 1
            b["failed"] += 1 if r.get("failed") else 0
            st = str(r.get("status", "none"))
            b["statuses"][st] = b["statuses"].get(st, 0) + 1
            b["wire_bytes"] += r.get("wire_bytes", 0)
            if r.get("cache"):
                b["from_cache"] += 1
            if "server_ms" in r:
                b["server_ms"].append(r["server_ms"])
            if "queries" in r:
                b["queries"].append(r["queries"])
        for b in out["by_kind"].values():
            ms, q = b.pop("server_ms"), b.pop("queries")
            b["server_ms_sum"] = round(sum(ms), 1)
            b["server_ms_median"] = round(statistics.median(ms), 1) if ms else None
            b["queries_sum"] = sum(q)
        docs = [r for r in rows if r["type"] == "Document"]
        if docs:
            d = docs[-1]
            out["document"] = {k: d.get(k) for k in ("url", "status", "server_ms", "db_ms", "queries", "raw_bytes", "wire_bytes")}
        out["livewire_calls"] = [
            {k: r.get(k) for k in ("livewire", "server_ms", "db_ms", "queries", "raw_bytes", "wire_bytes", "status", "failed")}
            | {"network_ms": round((r["t_done"] - r["t_sent"]) * 1000, 1) if "t_done" in r else None}
            for r in rows if self.kind(r["url"]) == "livewire"
        ]
        return out


def livewire_names(post_data):
    """Component names, updated property names and called methods of a
    Livewire request: enough to attribute it, with no values kept."""
    try:
        payload = json.loads(post_data)
        out = []
        for c in payload.get("components", []):
            snap = json.loads(c.get("snapshot") or "{}")
            out.append({
                "component": snap.get("memo", {}).get("name"),
                "updates": sorted((c.get("updates") or {}).keys()),
                "calls": [call.get("method") for call in c.get("calls", [])],
            })
        return out
    except Exception:
        return "unparsed"


def login(browser, profile, persona):
    ctx = browser.new_context(base_url=BASE, locale="en-US", **profile["context"])
    page = ctx.new_page()
    page.goto("/login")
    # Submitting before Alpine binds the form falls back to a native GET.
    page.wait_for_function("window.Livewire && window.Alpine && document.querySelector('form')._x_dataStack")
    page.fill("#email", persona["email"])
    page.fill("#password", persona["password"])
    page.click("form button:not([data-password-toggle])")
    page.wait_for_url(lambda u: "/login" not in u, timeout=60_000)
    state = ctx.storage_state()
    ctx.close()
    return state


def interact(page, flow, manifest):
    """Runs the flow's interaction; returns in-page timings (ms, page clock)."""
    kind = flow["interaction"]
    if kind is None:
        return None

    page.evaluate("window.__x1.lw.done = []")

    if kind == "search":
        term = manifest["search"]["term"]
        expected = manifest["search"]["expected_matches_in_hr_scope"]
        box = page.locator('input[type=search][wire\\:model\\.live\\.debounce\\.500ms="globalSearch"]').first
        box.scroll_into_view_if_needed()
        page.evaluate("""([term, expected]) => {
            const X = window.__x1; X.ix = {};
            const box = document.querySelector('input[type=search][wire\\\\:model\\\\.live\\\\.debounce\\\\.500ms="globalSearch"]');
            box.addEventListener('input', () => { X.ix.input = performance.now(); }, { once: true });
            const check = () => {
                const rows = [...document.querySelectorAll('tr[data-tour="table.row"]')];
                if (X.ix.input && rows.length === expected && rows.every(r => r.textContent.includes(term))) {
                    X.ix.rendered = performance.now(); return;
                }
                requestAnimationFrame(check);
            };
            requestAnimationFrame(check);
        }""", [term, expected])
        box.fill(term)
        page.wait_for_function("window.__x1.ix.rendered", timeout=120_000)
        ix = page.evaluate("window.__x1.ix")
        lw = page.evaluate("window.__x1.lw.done")
        after = [c for c in lw if c["start"] >= ix["input"]]
        first = min(after, key=lambda c: c["start"], default=None)
        return {
            "kind": "search", "term": term, "expected_rows": expected,
            "input_to_results_ms": round(ix["rendered"] - ix["input"], 1),
            "debounce_wait_ms": round(first["start"] - ix["input"], 1) if first else None,
            "request_ms": round(first["end"] - first["start"], 1) if first else None,
            "render_after_response_ms": round(ix["rendered"] - first["end"], 1) if first else None,
            "livewire_requests": len(after),
            "livewire_names": [c.get("names") for c in after],
        }

    if kind == "pick_employee":
        employee_id = str(manifest["contract_pick"]["employee_id"])
        page.evaluate("""(id) => {
            const X = window.__x1; X.ix = {};
            const root = document.querySelector('[x-data^="contractComposer"]');
            const d = window.Alpine.$data(root);
            X.ix.start = performance.now();
            d.selected.employee = id;
        }""", employee_id)
        return settle(page, "pick_employee")

    if kind == "open_leave_form":
        page.evaluate("""() => {
            const X = window.__x1; X.ix = {};
            document.querySelector('[data-tour="leaves.request"]')
                .addEventListener('click', () => { X.ix.start = performance.now(); }, { once: true, capture: true });
            const check = () => {
                const m = document.getElementById('showModal');
                if (X.ix.start && m && getComputedStyle(m).display !== 'none' && document.getElementById('leave_balance_id')) {
                    X.ix.visible = performance.now(); return;
                }
                requestAnimationFrame(check);
            };
            requestAnimationFrame(check);
        }""")
        page.click('[data-tour="leaves.request"]')
        page.wait_for_function("window.__x1.ix.visible", timeout=120_000)
        result = settle(page, "open_leave_form")
        ix = page.evaluate("window.__x1.ix")
        result["click_to_modal_visible_ms"] = round(ix["visible"] - ix["start"], 1)
        return result

    raise ValueError(kind)


def settle(page, kind):
    """Waits until every Livewire request started by the interaction has
    finished and none has followed within 300 ms, then reads the timings."""
    page.wait_for_function("""() => {
        const X = window.__x1, done = X.lw.done.filter(c => c.start >= X.ix.start);
        if (X.lw.inflight > 0 || done.length === 0) return false;
        const last = Math.max(...done.map(c => c.end));
        if (performance.now() - last < 300) return false;
        X.ix.settled = last; return true;
    }""", timeout=120_000, polling=50)
    ix = page.evaluate("window.__x1.ix")
    calls = page.evaluate("window.__x1.lw.done.filter(c => c.start >= window.__x1.ix.start)")
    names = [c.get("names") for c in calls]
    return {
        "kind": kind,
        "action_to_last_response_ms": round(ix["settled"] - ix["start"], 1),
        "livewire_requests": len(calls),
        "livewire_names": names,
    }


def measure_once(context, profile, flow_name, manifest):
    flow = FLOWS[flow_name]
    page = context.new_page()
    cdp = context.new_cdp_session(page)
    net = Network(cdp)
    cdp.send("Network.enable")
    if profile["network"]:
        cdp.send("Network.emulateNetworkConditions", profile["network"])
    if profile["cpu"] > 1:
        cdp.send("Emulation.setCPUThrottlingRate", {"rate": profile["cpu"]})

    page.add_init_script(
        INIT_JS.replace("__CONTENT__", json.dumps(flow["content"]))
               .replace("__READY__", json.dumps(flow["ready"]))
    )

    result = {"url": flow["path"]}
    page.goto(flow["path"], wait_until="commit", timeout=180_000)
    page.wait_for_function("window.__x1 && window.__x1.marks.first_useful !== undefined", timeout=180_000, polling=50)

    try:
        page.wait_for_function("window.__x1.marks.load !== undefined", timeout=profile["load_cap_ms"], polling=200)
        result["load_reached"] = True
    except Exception:
        result["load_reached"] = False

    # Livewire requests the page fires on its own after load (lazy or deferred
    # components) are part of loading it: wait until none has run for 500 ms.
    page.wait_for_function("""() => {
        const X = window.__x1;
        if (X.lw.inflight > 0) return false;
        const last = X.lw.done.length ? Math.max(...X.lw.done.map(c => c.end)) : 0;
        if (performance.now() - Math.max(last, X.marks.load || 0) < 500) return false;
        X.marks.settled = Math.max(last, X.marks.livewire_initialized || 0, X.marks.first_useful || 0);
        X.marks.load_phase_livewire_requests = X.lw.done.length;
        return true;
    }""", timeout=180_000, polling=50)
    result["page"] = page.evaluate(COLLECT_JS)
    result["page"]["livewire_in_page"] = page.evaluate(
        "window.__x1.lw.done.map(c => ({ ms: Math.round(c.end - c.start), names: c.names }))")
    result["network"] = net.summary(flow["path"])

    if flow["interaction"]:
        cdp_mark = max((r.get("t_done") or r["t_sent"]) for r in net.req.values())
        result["interaction"] = interact(page, flow, manifest)
        result["interaction_network"] = net.summary(flow["path"], since=cdp_mark)
        # Keep only the interaction's own Livewire calls.
        result["interaction"]["livewire_calls"] = result["interaction_network"]["livewire_calls"]

    if profile["cpu"] > 1:
        cdp.send("Emulation.setCPUThrottlingRate", {"rate": 1})
    page.close()
    return result


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    ap = argparse.ArgumentParser()
    ap.add_argument("--perf-home", required=True)
    ap.add_argument("--out", required=True)
    ap.add_argument("--profiles", default="desktop,phone")
    ap.add_argument("--flows", default=",".join(FLOWS))
    ap.add_argument("--cold", type=int, default=3)
    ap.add_argument("--first-visit", type=int, default=3)
    ap.add_argument("--warm", type=int, default=5)
    args = ap.parse_args()

    perf_home = Path(args.perf_home)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    manifest = json.loads((perf_home / "fixture-manifest.json").read_text(encoding="utf-8"))
    raw_path = out / "raw-runs.jsonl"
    server = Server(perf_home)

    def log(msg):
        print(f"[{datetime.datetime.now():%H:%M:%S}] {msg}", flush=True)

    def write(rec):
        with raw_path.open("a", encoding="utf-8") as f:
            f.write(json.dumps(rec) + "\n")

    with sync_playwright() as pw:
        browser = pw.chromium.launch()
        php = subprocess.run(["php", "-r", "echo PHP_VERSION;"], capture_output=True, text=True).stdout
        env = {
            "started_at": datetime.datetime.now().astimezone().isoformat(timespec="seconds"),
            "git_sha": subprocess.run(["git", "rev-parse", "HEAD"], cwd=REPO, capture_output=True, text=True).stdout.strip(),
            "git_dirty_paths": subprocess.run(["git", "status", "--porcelain"], cwd=REPO, capture_output=True, text=True).stdout.splitlines(),
            "browser": f"Chromium {browser.version} (Playwright, headless)",
            "php": php, "os": platform.platform(), "cpu": platform.processor(), "python": platform.python_version(),
            "server": f"{WORKERS} x php -S + router.php behind a threaded proxy on {BASE}; opcache.enable_cli=1, validate_timestamps=0; SQLite WAL, busy_timeout 10 s",
            "profiles": PROFILES, "flows": {k: {kk: v[kk] for kk in ("persona", "path", "content", "interaction")} for k, v in FLOWS.items()},
            "runs": {"cold": args.cold, "first_visit": args.first_visit, "warm": args.warm},
            "fixture": {k: manifest[k] for k in ("built_at", "seed", "counts", "search", "contract_pick")},
        }
        (out / "environment.json").write_text(json.dumps(env, indent=2), encoding="utf-8")

        server.start()
        try:
            run_all(args, browser, server, manifest, log, write)
        finally:
            server.stop(proxy=True)
            browser.close()
    log("done: " + str(raw_path))


def run_all(args, browser, server, manifest, log, write):
    states = {name: {p: login(browser, PROFILES[p], manifest["personas"][name]) for p in args.profiles.split(",")}
              for name in ("hr", "staff")}
    log("logged in")

    for profile_name in args.profiles.split(","):
        profile = PROFILES[profile_name]
        for flow_name in args.flows.split(","):
            state = states[FLOWS[flow_name]["persona"]][profile_name]
            tag = f"{profile_name}/{flow_name}"

            def record(condition, n, ctx):
                t = time.time()
                try:
                    res = measure_once(ctx, profile, flow_name, manifest)
                    res["ok"] = True
                except Exception as e:  # recorded, never silently dropped
                    res = {"ok": False, "error": f"{type(e).__name__}: {e}"[:500]}
                res["proxy_errors"] = list(server.proxy.errors)
                server.proxy.errors.clear()
                res.update(profile=profile_name, flow=flow_name, condition=condition, run=n,
                           at=datetime.datetime.now().astimezone().isoformat(timespec="seconds"),
                           harness_wall_s=round(time.time() - t, 1))
                write(res)
                fu = res.get("page", {}).get("marks", {}).get("first_useful")
                log(f"{tag} {condition} #{n}: ok={res['ok']} first_useful={fu and round(fu)} ms")

            for n in range(1, args.cold + 1):
                server.stop()
                server.clear_caches()
                server.start()
                # Requests the previous (closed) page still had in flight die
                # with the old workers; they are not part of this run.
                server.proxy.errors.clear()
                ctx = browser.new_context(base_url=BASE, locale="en-US", storage_state=state, **profile["context"])
                record("cold", n, ctx)
                ctx.close()

            for n in range(1, args.first_visit + 1):
                ctx = browser.new_context(base_url=BASE, locale="en-US", storage_state=state, **profile["context"])
                record("first-visit", n, ctx)
                ctx.close()

            ctx = browser.new_context(base_url=BASE, locale="en-US", storage_state=state, **profile["context"])
            record("warm-priming", 0, ctx)
            for n in range(1, args.warm + 1):
                record("warm", n, ctx)
            ctx.close()


if __name__ == "__main__":
    main()
