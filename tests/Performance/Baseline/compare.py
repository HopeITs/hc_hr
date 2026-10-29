"""X1-I before/after comparison of two measure.py runs.

Medians of the first N ok runs per (profile, flow, condition); cold runs are
skipped. Usage:

    python -I compare.py BEFORE/raw-runs.jsonl AFTER/raw-runs.jsonl \
        [--runs 3] [--json out.json] [--md out.md]
"""
import argparse
import json
import statistics
from collections import defaultdict

METRICS = [
    # key, label, unit
    ("first_useful", "First useful content", "ms"),
    ("nav_ready", "Navigation-ready", "ms"),
    ("settled", "Settled", "ms"),
    ("doc_server_ms", "Document server time", "ms"),
    ("doc_queries", "Document SQL queries", ""),
    ("lw_load_queries", "Queries in Livewire calls during load", ""),
    ("wire_kb", "Bytes on the wire", "KB"),
    ("requests", "Requests", ""),
    ("avatars", "Photo requests", ""),
    ("avatar_403", "Photo 403s", ""),
    ("cls_x1000", "CLS x1000", ""),
    ("ix_ms", "Interaction", "ms"),
    ("ix_queries", "Interaction queries", ""),
]


def load(path, runs):
    cells = defaultdict(list)
    for line in open(path, encoding="utf-8"):
        r = json.loads(line)
        if r["ok"] and r["condition"] in ("first-visit", "warm"):
            cells[(r["profile"], r["flow"], r["condition"])].append(r)
    return {k: sorted(v, key=lambda r: r["run"])[:runs] for k, v in cells.items()}


def med(vals):
    vals = [v for v in vals if v is not None]
    return round(statistics.median(vals)) if vals else None


def metrics(runs):
    m = lambda f: med([f(r) for r in runs])
    avatar = lambda r: r["network"]["by_kind"].get("avatar", {})
    lw = lambda r: r["network"].get("livewire_calls") or []
    ix = lambda r: r.get("interaction") or {}
    ix_calls = lambda r: ix(r).get("livewire_calls") or []
    return {
        "n": len(runs),
        "first_useful": m(lambda r: r["page"]["marks"].get("first_useful")),
        "nav_ready": m(lambda r: r["page"]["marks"].get("livewire_initialized")),
        "settled": m(lambda r: r["page"]["marks"].get("settled")),
        "doc_server_ms": m(lambda r: r["network"]["document"].get("server_ms")),
        "doc_queries": m(lambda r: r["network"]["document"].get("queries")),
        "lw_load_queries": m(lambda r: sum((c.get("queries") or 0) for c in lw(r))),
        "wire_kb": m(lambda r: r["network"]["wire_bytes"] / 1024),
        "requests": m(lambda r: r["network"]["requests"]),
        "avatars": m(lambda r: avatar(r).get("count", 0)),
        "avatar_403": m(lambda r: avatar(r).get("statuses", {}).get("403", 0)),
        "cls_x1000": m(lambda r: (r["page"].get("cls") or 0) * 1000),
        "ix_ms": m(lambda r: ix(r).get("input_to_results_ms") or ix(r).get("click_to_modal_visible_ms")
                   or ix(r).get("action_to_last_response_ms")),
        "ix_queries": m(lambda r: sum((c.get("queries") or 0) for c in ix_calls(r)) if ix(r) else None),
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("before")
    ap.add_argument("after")
    ap.add_argument("--runs", type=int, default=3)
    ap.add_argument("--json")
    ap.add_argument("--md")
    a = ap.parse_args()

    before, after = load(a.before, a.runs), load(a.after, a.runs)
    out, md = {}, []
    for k in sorted(set(before) | set(after)):
        b, f = metrics(before.get(k, [])), metrics(after.get(k, []))
        out["/".join(k)] = {"before": b, "after": f}
        md.append(f"\n**{' / '.join(k)}** (n = {b['n']} / {f['n']})\n")
        md.append("| Metric | Before | After |\n|---|---|---|")
        for key, label, unit in METRICS:
            if b[key] is None and f[key] is None:
                continue
            u = f" {unit}" if unit else ""
            md.append(f"| {label} | {b[key]}{u} | {f[key]}{u} |")
    if a.json:
        json.dump(out, open(a.json, "w", encoding="utf-8"), indent=1)
    text = "\n".join(md) + "\n"
    if a.md:
        open(a.md, "w", encoding="utf-8").write(text)
    else:
        print(text)


if __name__ == "__main__":
    main()
