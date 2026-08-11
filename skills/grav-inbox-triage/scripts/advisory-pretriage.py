#!/usr/bin/env python3
"""
Advisory pre-triage gatherer for the Grav org.

Does the MECHANICAL half of security-advisory triage so the weekly review session
is spent on judgement instead of archaeology. For every advisory sitting in
`triage` or `draft`, it:

  1. pulls the full record (reporter, severity, CVSS, summary, description),
  2. matches it against the known bug-family register (the pre-decided families
     from SKILL.md — a match is usually the whole answer),
  3. mechanically hunts for an already-shipped fix in the local checkouts,
  4. guesses which repo actually owns the code,
  5. writes a markdown digest plus a JSON sidecar.

It deliberately does NOT assign a disposition or write reply text. Those are
judgement calls and they stay with the human plus the triage skill — this script
only assembles evidence, so a wrong guess here is visible rather than laundered
into a recommendation.

Usage:
    ./advisory-pretriage.py                       # default org + repo list
    ./advisory-pretriage.py --out ~/triage        # choose output directory
    ./advisory-pretriage.py --repos grav,grav-plugin-api
    ./advisory-pretriage.py --state triage,draft,published

Requires: `gh` authenticated, and the repos checked out under --checkouts.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ORG = "getgrav"

# Advisories are filed on core almost regardless of where the code lives, so the
# fetch list is short. Extra repos can be added with --repos.
DEFAULT_REPOS = [
    "grav",
    "grav-plugin-api",
    "grav-plugin-admin2",
    "grav-plugin-login",
    "grav-plugin-form",
    "grav-plugin-flex-objects",
    "grav-plugin-email",
]

# ---------------------------------------------------------------------------
# Known bug-family register. Keep in sync with SKILL.md — that document is the
# authority on what each disposition means; this is only the pattern matcher.
# ---------------------------------------------------------------------------
FAMILIES = [
    {
        "id": "detectxss",
        "label": "detectXss denylist bypass",
        "default": "A (not a vulnerability)",
        "note": (
            "detectXss is an advisory heuristic denylist, explicitly not a security "
            "boundary. Bypasses are expected by design and do not get advisories. "
            "Only in scope if it shows content rendering UNESCAPED at an output sink."
        ),
        "patterns": [
            r"detectxss", r"detect_xss", r"xss_enabled", r"xss filter bypass",
            r"dangerous_tags", r"invalid_protocols", r"on_events",
        ],
    },
    {
        "id": "scope-cap",
        "label": "API-key scope-cap bypass",
        "default": "B (fix quietly) — unless a scoped key demonstrably escapes its scope in a shipped release",
        "note": (
            "One root cause: an authorization path reading isSuperAdmin() or a bare "
            "permission instead of going through the scope cap. A single chokepoint "
            "plus the ScopeCapChokepointTest guard now covers this in grav-plugin-api."
        ),
        "patterns": [
            r"scope[ _-]?cap", r"api[ _-]?key[ _-]?scope", r"issuperadmin",
            r"requiresuper", r"api_key_scopes", r"scoped key",
        ],
    },
    {
        "id": "host-header",
        "label": "Host-header link poisoning",
        "default": "C only if the flow is reachable unauthenticated, otherwise B",
        "note": "Magic-login / reset / activation links built from an untrusted Host header.",
        "patterns": [
            r"host header", r"x-forwarded-host", r"link poison",
            r"password reset link", r"magic link", r"activation link",
        ],
    },
    {
        "id": "timing",
        "label": "Non-constant-time comparison",
        "default": "B (fix quietly)",
        "note": (
            "Needs a demonstrated practical timing oracle over a network to be C, "
            "which essentially never survives scrutiny in PHP."
        ),
        "patterns": [
            r"constant[ -]?time", r"timing attack", r"hash_equals",
            r"non-constant", r"timing side[ -]?channel",
        ],
    },
    {
        "id": "admin-traversal",
        "label": "Path traversal behind admin rights",
        "default": "A or B — the actor was already trusted with the filesystem",
        "note": (
            "If reaching it needs the admin file manager or backup config, the caller "
            "already had filesystem access as part of their role."
        ),
        "patterns": [
            r"path traversal", r"directory traversal", r"\.\./", r"getlevellisting",
            r"arbitrary file read", r"arbitrary file write",
        ],
    },
    {
        "id": "enumeration",
        "label": "Account / email enumeration",
        "default": "A (not a vulnerability)",
        "note": "Documented, intentional default behaviour.",
        "patterns": [
            r"user enumeration", r"username enumeration", r"email enumeration",
            r"account enumeration", r"user existence",
        ],
    },
]

# Tokens worth grepping the codebase for, harvested from the advisory text.
#
# Deliberately strict: an earlier version matched any word followed by "(" and
# happily harvested prose like "generally(" and "entirely(", which then produced
# a page of meaningless "identifier ABSENT" noise in every digest. A real PHP
# identifier here is either explicitly qualified (`Foo::bar(`, `->bar(`) or
# visibly camelCase/snake_case.
QUALIFIED_IDENT_RE = re.compile(r"(?:::|->)\s*([A-Za-z_][A-Za-z0-9_]{2,})\s*\(")
SHAPED_IDENT_RE = re.compile(r"\b([a-z][a-zA-Z0-9]*(?:[A-Z][a-zA-Z0-9]*)+|[a-z][a-z0-9]*(?:_[a-z0-9]+)+)\s*\(")
CLASS_IDENT_RE = re.compile(r"\b([A-Z][a-z0-9]+(?:[A-Z][a-zA-Z0-9]*)+)\b")
PATH_RE = re.compile(r"\b((?:system|classes|user|plugins|admin)[\w/.-]*\.(?:php|yaml|twig|js))\b")
PLUGIN_PATH_RE = re.compile(r"user/plugins/([a-z0-9-]+)/")

# Source-path prefixes that identify the owning repo. Advisories are filed on
# core regardless of where the code lives, and re-homing them by hand is one of
# the bigger recurring time sinks in triage.
PATH_OWNERS = [
    (re.compile(r"\bclasses/Api/"), "grav-plugin-api"),
    (re.compile(r"\bsystem/src/Grav/"), "grav"),
    (re.compile(r"\badmin-next/"), "grav-plugin-admin2"),
]

NOISE_IDENTS = {
    "function", "foreach", "return", "public", "static", "private", "string",
    "array", "isset", "empty", "count", "printf", "sprintf", "include",
    "require", "extends", "implements", "protected", "namespace", "example",
    "attacker", "payload", "request", "response", "version", "install",
    "isString", "isArray", "inArray",
}


def run(cmd: list[str], cwd: str | None = None, timeout: int = 60) -> tuple[int, str]:
    try:
        p = subprocess.run(
            cmd, cwd=cwd, capture_output=True, text=True, timeout=timeout,
        )
        return p.returncode, (p.stdout or "") + (p.stderr or "")
    except (subprocess.TimeoutExpired, FileNotFoundError, OSError) as exc:
        return 1, f"{type(exc).__name__}: {exc}"


def fetch_advisories(repo: str, states: set[str]) -> list[dict]:
    """List advisories for one repo, filtered to the states we care about."""
    code, out = run([
        "gh", "api", f"/repos/{ORG}/{repo}/security-advisories",
        "--paginate", "--slurp",
    ], timeout=120)
    if code != 0:
        print(f"  ! {repo}: gh failed ({out.strip()[:120]})", file=sys.stderr)
        return []
    try:
        pages = json.loads(out)
    except json.JSONDecodeError:
        print(f"  ! {repo}: could not parse gh output", file=sys.stderr)
        return []

    items: list[dict] = []
    for page in pages:
        if isinstance(page, list):
            items.extend(page)
        elif isinstance(page, dict):
            items.append(page)

    kept = [a for a in items if a.get("state") in states]
    for a in kept:
        a["_repo"] = repo
    return kept


def match_families(summary: str, description: str) -> list[dict]:
    """
    Score a family match by confidence.

    Matching the whole blob equally was actively misleading: reporters paste code
    and cross-reference prior GHSAs in their PoC, so a stored-XSS report that
    merely *mentioned* requireSuper() in a snippet was tagged as a scope-cap
    bypass. A family named in the SUMMARY is a strong signal; one stray pattern
    buried in a quoted PoC is not.
    """
    sum_low, desc_low = summary.lower(), description.lower()
    hits = []
    for fam in FAMILIES:
        in_summary = [p for p in fam["patterns"] if re.search(p, sum_low)]
        in_desc = [p for p in fam["patterns"] if re.search(p, desc_low)]
        if in_summary:
            confidence, matched = "high", in_summary
        elif len(in_desc) >= 2:
            confidence, matched = "medium", in_desc
        elif in_desc:
            confidence, matched = "low", in_desc
        else:
            continue
        hits.append({
            "id": fam["id"],
            "label": fam["label"],
            "default": fam["default"],
            "note": fam["note"],
            "confidence": confidence,
            "matched": ", ".join(matched[:3]),
        })
    order = {"high": 0, "medium": 1, "low": 2}
    hits.sort(key=lambda h: order[h["confidence"]])
    return hits


def harvest_tokens(text: str) -> tuple[list[str], list[str]]:
    idents: set[str] = set()
    for rx in (QUALIFIED_IDENT_RE, SHAPED_IDENT_RE, CLASS_IDENT_RE):
        for m in rx.finditer(text):
            tok = m.group(1)
            if tok.lower() not in {n.lower() for n in NOISE_IDENTS} and len(tok) > 3:
                idents.add(tok)
    paths = {m.group(1) for m in PATH_RE.finditer(text)}
    return sorted(idents)[:8], sorted(paths)[:8]


def guess_owning_repo(text: str, filed_on: str) -> tuple[str, str]:
    """
    Advisories land on core even when the code lives elsewhere.

    Checks an explicit `user/plugins/<name>/` path first, then falls back to
    source-tree prefixes — `classes/Api/...` is the api plugin, not core, and that
    single case accounts for a large share of the mis-filed queue.
    """
    plugins = set(PLUGIN_PATH_RE.findall(text))
    if plugins:
        plugin = sorted(plugins)[0]
        candidate = plugin if plugin.startswith("grav-") else f"grav-plugin-{plugin}"
        if candidate != filed_on:
            return candidate, f"description references user/plugins/{plugin}/"
        return filed_on, ""

    for rx, repo in PATH_OWNERS:
        if rx.search(text) and repo != filed_on:
            return repo, f"description references `{rx.pattern.strip(chr(92) + 'b')}` paths, which live in {repo}"

    return filed_on, ""


def check_already_fixed(checkouts: Path, repo: str, ghsa: str, idents: list[str],
                        paths: list[str]) -> dict:
    """Hunt for evidence the issue is already patched on develop."""
    repo_dir = checkouts / repo
    result: dict = {"repo_path": str(repo_dir), "checked": False, "evidence": []}
    if not repo_dir.is_dir():
        result["error"] = "not checked out locally"
        return result
    result["checked"] = True

    # 1. Strongest signal: the GHSA id cited in a commit message or in code.
    code, out = run(["git", "log", "--oneline", "-20", f"--grep={ghsa}", "--all"], cwd=str(repo_dir))
    if code == 0 and out.strip():
        result["evidence"].append({
            "kind": "commit references GHSA id",
            "strength": "strong",
            "detail": out.strip()[:600],
        })

    code, out = run(["git", "grep", "-n", "--", ghsa], cwd=str(repo_dir))
    if code == 0 and out.strip():
        result["evidence"].append({
            "kind": "GHSA id cited in tracked source (usually the fix comment)",
            "strength": "strong",
            "detail": out.strip()[:600],
        })

    # 2. Recent commits touching the files the report names.
    for path in paths[:4]:
        base = os.path.basename(path)
        code, out = run(
            ["git", "log", "--oneline", "-5", "--since=6 months ago", "--", f"*{base}"],
            cwd=str(repo_dir),
        )
        if code == 0 and out.strip():
            result["evidence"].append({
                "kind": f"recent commits touching {base}",
                "strength": "weak",
                "detail": out.strip()[:400],
            })

    # 3. Do the identifiers the report names still exist at all?
    for ident in idents[:5]:
        code, out = run(["git", "grep", "-c", "--", ident], cwd=str(repo_dir))
        present = code == 0 and out.strip() != ""
        result["evidence"].append({
            "kind": f"identifier `{ident}` {'present' if present else 'ABSENT'} on current branch",
            "strength": "weak",
            "detail": "" if present else "renamed or removed — the report may predate a refactor",
        })

    code, out = run(["git", "describe", "--tags", "--abbrev=0"], cwd=str(repo_dir))
    result["latest_tag"] = out.strip() if code == 0 else "unknown"
    code, out = run(["git", "rev-parse", "--abbrev-ref", "HEAD"], cwd=str(repo_dir))
    result["branch"] = out.strip() if code == 0 else "unknown"

    return result


def build(adv: dict, checkouts: Path) -> dict:
    ghsa = adv.get("ghsa_id", "")
    repo = adv.get("_repo", "")
    summary = adv.get("summary") or ""
    description = adv.get("description") or ""
    text = f"{summary}\n{description}"

    idents, paths = harvest_tokens(text)
    owning, rehome_reason = guess_owning_repo(text, repo)

    return {
        "ghsa_id": ghsa,
        "filed_on": repo,
        "state": adv.get("state"),
        "severity": adv.get("severity"),
        "cvss": (adv.get("cvss") or {}).get("score"),
        "cvss_vector": (adv.get("cvss") or {}).get("vector_string"),
        "created_at": adv.get("created_at"),
        "url": adv.get("html_url"),
        "reporters": [c.get("login") for c in (adv.get("credits") or []) if c.get("login")],
        "summary": summary,
        "description": description,
        "families": match_families(summary, description),
        "identifiers": idents,
        "paths": paths,
        "owning_repo": owning,
        "rehome_reason": rehome_reason,
        "already_fixed": check_already_fixed(checkouts, owning, ghsa, idents, paths),
    }


def render(rows: list[dict], states: set[str]) -> str:
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    out: list[str] = []
    out.append(f"# Advisory pre-triage digest — {now}")
    out.append("")
    out.append(
        f"{len(rows)} advisor{'y' if len(rows) == 1 else 'ies'} in state "
        f"`{'`/`'.join(sorted(states))}`."
    )
    out.append("")
    out.append(
        "> Mechanical evidence only. Dispositions and reply text are decided in the "
        "weekly review using the `grav-inbox-triage` skill — nothing below is a "
        "recommendation, and the family column is a *candidate* match to confirm, "
        "not a verdict."
    )
    out.append("")

    familied = [r for r in rows if any(f["confidence"] in ("high", "medium") for f in r["families"])]
    if familied:
        out.append(f"**{len(familied)} of {len(rows)} match a known bug family** and should close fast.")
        out.append("")

    out.append("| GHSA | Filed on | Sev | Family (candidate) | Already fixed? | Re-home? |")
    out.append("| --- | --- | --- | --- | --- | --- |")
    for r in rows:
        strong_fams = [f for f in r["families"] if f["confidence"] in ("high", "medium")]
        fam = ", ".join(f'{f["label"]} ({f["confidence"]})' for f in strong_fams) or "—"
        strong = [e for e in r["already_fixed"].get("evidence", []) if e["strength"] == "strong"]
        fixed = "**likely — strong evidence**" if strong else "no strong signal"
        if not r["already_fixed"].get("checked"):
            fixed = f"_{r['already_fixed'].get('error', 'unchecked')}_"
        rehome = r["owning_repo"] if r["rehome_reason"] else "—"
        out.append(
            f"| [{r['ghsa_id']}]({r['url']}) | {r['filed_on']} | {r['severity']} "
            f"| {fam} | {fixed} | {rehome} |"
        )
    out.append("")

    for r in rows:
        out.append("---")
        out.append("")
        out.append(f"## [{r['ghsa_id']}]({r['url']}) — {r['severity']} — {r['state']}")
        out.append("")
        out.append(f"*{r['summary']}*")
        out.append("")
        reporters = ", ".join(r["reporters"]) or "(none credited yet)"
        out.append(f"- **Filed on:** `{r['filed_on']}` · **Created:** {r['created_at']}")
        out.append(f"- **Reporter (for credit):** {reporters}")
        if r["cvss_vector"]:
            out.append(f"- **Reporter CVSS:** {r['cvss']} `{r['cvss_vector']}` (advisory only — rate by the SECURITY.md rubric)")

        if r["rehome_reason"]:
            out.append(f"- **Re-home candidate:** `{r['owning_repo']}` — {r['rehome_reason']}")

        if r["families"]:
            out.append("")
            out.append("**Candidate family match — confirm before using:**")
            for f in r["families"]:
                out.append("")
                out.append(f"- **{f['label']}** — confidence **{f['confidence']}** (matched `{f['matched']}`)")
                if f["confidence"] == "low":
                    out.append("  - _Low confidence: a single pattern hit somewhere in the description, often just a quoted PoC or a cross-reference. Verify before relying on it._")
                out.append(f"  - Register default: {f['default']}")
                out.append(f"  - {f['note']}")

        af = r["already_fixed"]
        out.append("")
        out.append(f"**Already-fixed check** (`{af.get('repo_path')}`, branch `{af.get('branch', '?')}`, latest tag `{af.get('latest_tag', '?')}`):")
        out.append("")
        if not af.get("checked"):
            out.append(f"- _Not checked: {af.get('error')}_")
        elif not af.get("evidence"):
            out.append("- No signals found.")
        else:
            for e in af["evidence"]:
                marker = "**STRONG**" if e["strength"] == "strong" else "weak"
                out.append(f"- [{marker}] {e['kind']}")
                if e["detail"]:
                    out.append("")
                    out.append("  ```")
                    for line in e["detail"].splitlines()[:8]:
                        out.append(f"  {line}")
                    out.append("  ```")

        if r["identifiers"] or r["paths"]:
            out.append("")
            out.append(
                f"**Harvested tokens** — idents: `{'`, `'.join(r['identifiers']) or '—'}` · "
                f"paths: `{'`, `'.join(r['paths']) or '—'}`"
            )
        out.append("")

    out.append("---")
    out.append("")
    out.append(
        "Next: run the `grav-inbox-triage` skill against this digest to assign "
        "dispositions (A / B / C) and draft the closes."
    )
    out.append("")
    return "\n".join(out)


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--repos", default=",".join(DEFAULT_REPOS),
                    help="comma-separated repo names under the org")
    ap.add_argument("--state", default="triage,draft",
                    help="comma-separated advisory states to include")
    ap.add_argument("--checkouts", default=str(Path.home() / "Projects" / "grav"),
                    help="directory holding the local repo checkouts")
    ap.add_argument("--out", default=str(Path.home() / "Projects" / "grav" / ".advisory-triage"),
                    help="output directory for the digest")
    args = ap.parse_args()

    states = {s.strip() for s in args.state.split(",") if s.strip()}
    repos = [r.strip() for r in args.repos.split(",") if r.strip()]
    checkouts = Path(args.checkouts).expanduser()
    outdir = Path(args.out).expanduser()
    outdir.mkdir(parents=True, exist_ok=True)

    print(f"Fetching advisories in {sorted(states)} across {len(repos)} repos...", file=sys.stderr)
    advisories: list[dict] = []
    for repo in repos:
        found = fetch_advisories(repo, states)
        if found:
            print(f"  {repo}: {len(found)}", file=sys.stderr)
        advisories.extend(found)

    if not advisories:
        print("Nothing in the queue.", file=sys.stderr)

    advisories.sort(key=lambda a: a.get("created_at") or "")
    rows = []
    for adv in advisories:
        print(f"  analysing {adv.get('ghsa_id')}...", file=sys.stderr)
        rows.append(build(adv, checkouts))

    stamp = datetime.now(timezone.utc).strftime("%Y-%m-%d")
    md_path = outdir / f"pretriage-{stamp}.md"
    json_path = outdir / f"pretriage-{stamp}.json"
    latest = outdir / "latest.md"

    md = render(rows, states)
    md_path.write_text(md)
    json_path.write_text(json.dumps(rows, indent=2))
    latest.write_text(md)

    familied = sum(1 for r in rows if any(f["confidence"] in ("high", "medium") for f in r["families"]))
    fixed = sum(
        1 for r in rows
        if any(e["strength"] == "strong" for e in r["already_fixed"].get("evidence", []))
    )
    print(
        f"\n{len(rows)} advisories · {familied} match a known family · "
        f"{fixed} show strong already-fixed evidence",
        file=sys.stderr,
    )
    print(f"Digest: {md_path}", file=sys.stderr)
    print(md_path)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
