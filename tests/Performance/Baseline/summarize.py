#!/usr/bin/env python3
"""X1 performance baseline: medians from measure.py's raw runs.

    python -I tests/Performance/Baseline/summarize.py <out-dir>

Reads <out-dir>/raw-runs.jsonl and writes summary.json and summary.md next
to it. Every metric keeps its run count, minimum and maximum beside the
median; failed runs are counted, never dropped silently. Priming visits
(condition "warm-priming") only fill the cache and are not summarised.
"""

import json
import statistics
import sys
from pathlib import Path

CONDITIONS = ["cold", "first-visit", "warm"]

# (key, label, unit) in report order. Times are page-clock milliseconds.
METRICS = [
    ("doc_server_ms", "Document: server time", "ms"),
    ("doc_db_ms", "Document: time in SQL", "ms"),
    ("doc_queries", "Document: SQL queries", "n"),
    ("doc_raw_kb", "Document: HTML size (uncompressed)", "KB"),
    ("doc_wire_kb", "Document: transferred (gzip)", "KB"),
    ("ttfb", "Time to first byte", "ms"),
    ("fcp", "First contentful paint", "ms"),
    ("content_visible", "Flow content rendered (behind overlay)", "ms"),
    ("overlay_hidden", "Loading overlay gone", "ms"),
    ("first_useful", "First useful content", "ms"),
    ("nav_ready", "Navigation-ready (livewire:initialized)", "ms"),
    ("settled", "Settled (load-phase Livewire requests done)", "ms"),
    ("load", "Load event", "ms"),
    ("lcp", "Largest contentful paint", "ms"),
    ("page_requests", "Requests, whole load", "n"),
    ("page_wire_kb", "Transferred, whole load", "KB"),
    ("third_party_wire_kb", "  of which third-party CDNs", "KB"),
    ("avatar_requests", "Avatar requests (each a PHP request)", "n"),
    ("avatar_403", "  of which refused (403)", "n"),
    ("avatar_wire_kb", "  avatar bytes transferred", "KB"),
    ("avatar_server_ms_sum", "  avatar server time, summed", "ms"),
    ("avatar_queries_sum", "  avatar SQL queries, summed", "n"),
    ("load_lw_requests", "Livewire requests during load", "n"),
    ("load_lw_server_ms", "  their server time, summed", "ms"),
    ("load_lw_queries", "  their SQL queries, summed", "n"),
    ("load_lw_wire_kb", "  their response size (gzip)", "KB"),
    ("ix_ms", "Interaction: action to result", "ms"),
    ("ix_debounce_ms", "  of which search debounce", "ms"),
    ("ix_server_ms", "  interaction server time", "ms"),
    ("ix_queries", "  interaction SQL queries", "n"),
    ("ix_raw_kb", "  interaction response (uncompressed)", "KB"),
    ("ix_wire_kb", "  interaction response (gzip)", "KB"),
    ("cls", "Cumulative layout shift", ""),
    ("dom_nodes", "DOM elements", "n"),
]


def kb(b):
    return None if b is None else b / 1024


def flatten(run):
    page = run.get("page", {})
    marks = page.get("marks", {})
    net = run.get("network", {})
    doc = net.get("document") or {}
    kinds = net.get("by_kind", {})
    avatar = kinds.get("avatar", {})
    lw = [c for c in net.get("livewire_calls", []) if c.get("server_ms") is not None]
    ix = run.get("interaction") or {}
    ix_calls = [c for c in ix.get("livewire_calls", []) if c.get("server_ms") is not None]

    m = {
        "doc_server_ms": doc.get("server_ms"),
        "doc_db_ms": doc.get("db_ms"),
        "doc_queries": doc.get("queries"),
        "doc_raw_kb": kb(doc.get("raw_bytes")),
        "doc_wire_kb": kb(doc.get("wire_bytes")),
        "ttfb": page.get("nav", {}).get("ttfb"),
        "fcp": page.get("fcp"),
        "content_visible": marks.get("content_visible"),
        "overlay_hidden": marks.get("overlay_hidden"),
        "first_useful": marks.get("first_useful"),
        "nav_ready": marks.get("livewire_initialized"),
        "settled": marks.get("settled"),
        "load": marks.get("load") if run.get("load_reached") else None,
        "lcp": page.get("lcp"),
        "page_requests": net.get("requests"),
        "page_wire_kb": kb(net.get("wire_bytes")),
        "third_party_wire_kb": kb(kinds.get("third_party", {}).get("wire_bytes")),
        "avatar_requests": avatar.get("count", 0),
        "avatar_403": avatar.get("statuses", {}).get("403", 0),
        "avatar_wire_kb": kb(avatar.get("wire_bytes", 0)),
        "avatar_server_ms_sum": avatar.get("server_ms_sum"),
        "avatar_queries_sum": avatar.get("queries_sum"),
        "load_lw_requests": len(net.get("livewire_calls", [])),
        "load_lw_server_ms": sum(c["server_ms"] for c in lw) if lw else None,
        "load_lw_queries": sum(c.get("queries") or 0 for c in lw) if lw else None,
        "load_lw_wire_kb": kb(sum(c.get("wire_bytes") or 0 for c in lw)) if lw else None,
        "cls": page.get("cls"),
        "dom_nodes": page.get("dom_nodes"),
    }
    if ix:
        m["ix_ms"] = ix.get("input_to_results_ms") or ix.get("click_to_modal_visible_ms") or ix.get("action_to_last_response_ms")
        m["ix_debounce_ms"] = ix.get("debounce_wait_ms")
        m["ix_server_ms"] = sum(c["server_ms"] for c in ix_calls) if ix_calls else None
        m["ix_queries"] = sum(c.get("queries") or 0 for c in ix_calls) if ix_calls else None
        m["ix_raw_kb"] = kb(sum(c.get("raw_bytes") or 0 for c in ix_calls)) if ix_calls else None
        m["ix_wire_kb"] = kb(sum(c.get("wire_bytes") or 0 for c in ix_calls)) if ix_calls else None
    return m


def stats(values):
    vals = [v for v in values if v is not None]
    if not vals:
        return None
    return {"n": len(vals), "median": statistics.median(vals), "min": min(vals), "max": max(vals)}


def fmt(s, unit):
    if s is None:
        return "–"
    digits = 2 if unit == "" else (1 if unit == "KB" and s["median"] < 100 else 0)
    f = lambda v: f"{v:,.{digits}f}"
    if s["min"] == s["max"]:
        return f"{f(s['median'])}"
    return f"{f(s['median'])} <sub>({f(s['min'])}–{f(s['max'])})</sub>"


def main():
    out = Path(sys.argv[1])
    runs = [json.loads(l) for l in (out / "raw-runs.jsonl").read_text(encoding="utf-8").splitlines() if l.strip()]
    env = json.loads((out / "environment.json").read_text(encoding="utf-8"))

    summary = {"environment": env, "flows": {}, "failures": []}
    for r in runs:
        if not r.get("ok"):
            summary["failures"].append({k: r.get(k) for k in ("profile", "flow", "condition", "run", "error")})

    flows = list(dict.fromkeys(r["flow"] for r in runs))
    profiles = list(dict.fromkeys(r["profile"] for r in runs))
    md = ["# X1 performance baseline: medians", "",
          f"Raw runs: `raw-runs.jsonl` ({len(runs)} records, {len(summary['failures'])} failed). "
          "Each cell is the median, with (min–max) when runs differ. Times are milliseconds on the page's own clock "
          "from navigation start; server figures come from the router's Server-Timing headers.", ""]

    for flow in flows:
        summary["flows"][flow] = {}
        cols = []
        for profile in profiles:
            for cond in CONDITIONS:
                sel = [r for r in runs if r["flow"] == flow and r["profile"] == profile and r["condition"] == cond and r.get("ok")]
                if not sel:
                    continue
                flat = [flatten(r) for r in sel]
                summary["flows"][flow].setdefault(profile, {})[cond] = {
                    key: stats([f.get(key) for f in flat]) for key, _, _ in METRICS
                } | {"runs": len(sel)}
                cols.append((profile, cond))

        md += [f"## {flow}", "", "| Metric | " + " | ".join(f"{p} · {c} (n={summary['flows'][flow][p][c]['runs']})" for p, c in cols) + " |",
               "|---|" + "---|" * len(cols)]
        for key, label, unit in METRICS:
            cells = [summary["flows"][flow][p][c].get(key) for p, c in cols]
            if all(c is None for c in cells):
                continue
            md.append(f"| {label}{f' ({unit})' if unit and unit != 'n' else ''} | " + " | ".join(fmt(c, unit) for c in cells) + " |")
        md.append("")

    if summary["failures"]:
        md += ["## Failed runs", ""] + [f"- {f['profile']}/{f['flow']} {f['condition']} #{f['run']}: {f['error']}" for f in summary["failures"]] + [""]

    (out / "summary.json").write_text(json.dumps(summary, indent=2), encoding="utf-8")
    (out / "summary.md").write_text("\n".join(md), encoding="utf-8")
    print(out / "summary.md")


if __name__ == "__main__":
    main()
