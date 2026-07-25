---
name: grav-inbox-triage
description: >-
  Use when Andy asks to clean out / triage / process his GitHub notifications inbox for the Grav org (getgrav/*), or says "do my inbox", "triage my notifications", "review my github inbox". Covers the full workflow: pull the inbox via `gh` and group it (security advisories, bug issues, PRs, other); fan out read-only agents to validate each item against the real checked-out repos under ~/Projects/grav; for security advisories — map the notification to its GHSA record, CONFIRM it against code, CHECK IF ALREADY FIXED on develop/tags (most reports are against an old snapshot), rate severity by Grav's own SECURITY.md trust-boundary rubric (NOT the reporter's CVSS), and confirm the correct fix repo (advisories filed on getgrav/grav core very often belong to grav-plugin-api or another plugin); for bug issues — reproduce via code + chrome/computer-use, fix on the repo that actually owns the code, and draft a short non-technical reply; keep ONE living review artifact as Andy's control panel; and only pull public triggers (comment/close/PR/publish, mark notifications done) after Andy signs off. Trigger on inbox/notification cleanup for the Grav org.
---

# Grav GitHub inbox triage

Andy runs this to clear his GitHub notifications inbox for the Grav org with your help. The job is to turn a pile of notifications into **one living review document** he can act from — accept, close, comment, edit — plus prepared fixes and drafted replies. You do the investigation and preparation autonomously; **he pulls the public triggers.**

The single most valuable move in this whole workflow, learned the hard way: **before proposing any fix, check whether the issue is already fixed on `develop`.** Reporters routinely test against an old released tag (e.g. 2.0.1) and file against bugs that were patched weeks earlier. In the batch this skill was built from, **4 of 5 security advisories were already fixed and released** — the correct action was a credit-and-close reply, not a code change.

## Ground rules

- **Read-only until sign-off.** Investigate, validate, draft fixes on local branches, and write the doc freely. Do **not** comment on issues/advisories, close anything, open public PRs, publish/edit advisories, or mark notifications read/done until Andy explicitly approves. These are public actions on the getgrav org and several are hard to reverse.
- **Security-advisory disclosure risk.** An advisory still in `triage` (unpublished) is a private 0-day. Never open a public PR, push a public branch, or write a public comment that reveals it. Prepare the fix locally and let Andy route it through the advisory's private fork.
- **One doc, keep updating.** Build a single artifact (or markdown file) and update it in place as items resolve. Report its URL/path back so Andy can track progress from the doc alone. Don't spawn a new doc per item.
- **Repos are already checked out** under `~/Projects/grav/<repo>` on `develop`: `grav` (core), `grav-plugin-api`, `grav-plugin-admin2`, `grav-plugin-login`, `grav-plugin-flex-objects`, `grav-plugin-form`, and the admin-next **source** at `grav-admin-next`. Validate against these, not from memory. (Sites under `~/workspace/*` are runnable reeve installs at `https://<name>.test`, not git repos.)

## Method

### 1. Pull and group the inbox

**`gh api notifications` returns UNREAD ONLY. Always pass `all=true`.** Andy reads items in the UI without actioning them — the default call silently hides every read item, and read ≠ handled. In the batch this warning was added from, the default call returned 6 items and hid 4, *including an assigned security advisory* — the single most important thing in the inbox.

```bash
gh api "notifications?all=true&per_page=100" --paginate > notifications_all.json
jq -r '.[] | [.id,(.unread|tostring),.reason,.subject.type,.repository.full_name,.subject.title] | @tsv' notifications_all.json
```

**Reconcile the count against Andy's UI before triaging.** `all=true` also returns items he already marked **Done** (the REST API has no Done concept), so it over-returns badly — 102 rows against a real inbox of 10. Sort by `updated_at` desc: his live inbox is the top-N prefix, and everything below the cutoff is Done. Ask him for the count shown in his UI ("1-N of N"), or have him screenshot it, then take exactly that prefix. **State the item list back to him and get confirmation before investigating** — triaging the wrong set wastes the whole run, and silently including Done items re-litigates work he's already closed out.

**There is no `!= done` filter — don't offer to build one; the top-N prefix IS the filter.** Andy will reasonably ask "can't you just filter out the Done ones?" The honest answer is no: the payload carries `unread` (bool) and `reason`, but **nothing that marks an item Done** — Done is a GitHub-UI-only archive state the REST API never exposes. So once he gives you the UI count N, take exactly the top-N by `updated_at` and treat everything below as handled:

```bash
# after Andy confirms his UI shows "1-N of N", N is the only knob:
N=16
jq --argjson n "$N" -r 'sort_by(.updated_at) | reverse | .[0:$n]
  | to_entries[] | [(.key+1|tostring), .id, .reason, .subject.type,
    (.repository.full_name|sub("getgrav/";"")), .subject.title] | @tsv' notifications_all.json
```

**When Andy caps the count, respect it — including for assigned advisories that fall just below the cutoff.** The "never silently drop an `assign` advisory" rule (below) is about *visibility*, not *override*: **surface** any assigned advisory sitting below the line and name it, but if he says the live inbox is those N items and everything else is handled, an older assigned advisory below the cutoff is **handled**, not a hidden emergency. Point it out once, take his answer, don't re-litigate. (In the run this note came from, two `assign` advisories from three days earlier sat below a 16-item cutoff — already actioned; forcing them back in would have re-opened closed work.)

Group by `subject.type` and repo: **RepositoryAdvisory** (security), **Issue** (bug/feature/question), **PullRequest**, and everything else (CI, releases, discussions, mentions). `reason` matters: `assign` = assigned to Andy (his to action); `comment`/`subscribed` = new activity on something he follows. **Surface advisories at the top regardless of read state, and name any assigned one that falls below the cutoff so Andy can confirm it's handled** — an in-scope `assign` RepositoryAdvisory outranks every issue in the list.

### 2. Fetch full content

- **Advisories:** `gh api repos/getgrav/grav/security-advisories --paginate` then match the notification `subject.title` to a `ghsa_id`. Pull the full record: `gh api repos/getgrav/grav/security-advisories/<GHSA-ID>` — `.description` has the report, `.state` (`triage`/`published`/`closed`), `.severity`, `.cvss`, `.credits[].login`, `.html_url`. **Advisory comment threads are NOT exposed via the REST API** — a `reason: comment` advisory has new discussion you can't read programmatically; flag it in the doc for Andy to read in the GitHub UI (someone may already have replied "this is fixed").
- **Issues/PRs:** `gh issue view <n> -R <repo> --json number,title,body,state,author,createdAt,comments` (or `gh pr view`).

### 3. Fan out read-only validation agents (one per item, in parallel)

Give each agent: the item's full text (write it to a scratch file), the repo path, and a strict **read-only, no-commit/push/PR, do-not-modify-tracked-files** instruction. Require a structured return. For **security advisories**, the agent must answer, in order:

1. **Is the described code present as claimed?** Quote `file:line`.
2. **Is it ALREADY FIXED on `develop`?** `git -C <repo> log -S'<marker>'`, `git grep <GHSA-id>`, `git tag --contains <commit>`. If fixed, name the commit and the released tag(s). *Do this before anything else — it usually decides the outcome.*
3. **Severity by Grav's SECURITY.md rubric (see below), not the reporter's CVSS.**
4. **Is the fix repo correct?** Advisories are filed on `getgrav/grav` but the vulnerable code is frequently in a plugin — if the report's paths are `user/plugins/api/...` the fix belongs in `grav-plugin-api`, and the advisory should be re-homed.
5. **Minimal correct fix as a unified diff** (drafted, not applied), matching house code style.
6. **Draft non-technical reply to the reporter** (credit them; if fixed, name the version).

Independently spot-check the agents' "already fixed" claims yourself with `git show`/`git tag --contains` before you write them into the doc — these determine whether Andy replies "fixed in X" vs. ships code.

### 4. Grav severity rubric (SECURITY.md — trust boundary, not CVSS)

Grav rates by **whether the actor escapes the trust scope of their role**, and explicitly overrides the CVSS the GitHub form computes. Read `~/Projects/grav/grav/SECURITY.md` each run in case it moved, but the shape is:

- **CRITICAL** — *unauthenticated* attacker gets RCE / data exfil / admin-equivalent control. No account.
- **HIGH** — cross-trust-boundary: a lower-privilege actor (or anon against a stored payload) runs code, exfiltrates data, or acts inside a higher-privilege session. E.g. stored XSS firing in a super-admin session; publisher→admin escalation.
- **MODERATE** — an authenticated user acts outside their role's documented scope, but impact stays in their own session / same-tier.
- **LOW** — an admin/super does something nefarious *within already-granted capabilities* → usually **wontfix / by design** (giving someone admin keys means trusting them).

Consequences to state in the doc:
- A **publisher running Twig in their own pages**, or an **admin using CLI/config**, is the role, not a vuln. Reports that are really "admin can do admin things" get downgraded to LOW / wontfix.
- **1.7 backport only** if exploitable **without** a publisher- or admin-level account. Anything needing publisher/admin → **2.0 only**.
- When your rubric rating differs from the reporter's (common — CVSS inflates), that's a **"severity needs tweaking" item Andy must see**: state reporter's rating, your rubric rating, and the one-line reason. Don't silently re-rate.

### 5. Bug issues

- **Reproduce, don't assume.** Trace the code path; where it's a UI behavior, drive it with the chrome MCP or computer-use against the reeve site (`https://<name>.test`, admin at `/admin`) and capture what you see. `Admin::enablePages()` if you need the pages object.
- **Fix on the repo that owns the code, which is often not where the issue was filed.** admin-next UI bugs are filed on `grav-plugin-admin2` but the **source** is in `grav-admin-next`; after editing there, `npm run build:plugin` regenerates the bundle that ships in `grav-plugin-admin2`. Blueprint/form bugs can live in core `grav`, `grav-plugin-api`, `grav-plugin-flex-objects`, or admin2 — trace before you pick.
- **Outcomes per issue** (Andy's stated dispositions):
  - *Fixed* → PR on the owning repo, short non-technical reply summarizing the fix, close.
  - *Underspecified* (can't reproduce/validate) → comment asking for the specific missing detail (version, blueprint, exact steps). Don't close.
  - *Not reproducible* (tried, doesn't repro) → comment with what you tried and close.
  - *Doesn't fit a bucket* (feature request, upstream dep, design question) → don't act; give Andy a status line in the doc.

### 6. The living review document (the deliverable)

One artifact, Andy's control panel. **Publish it as an Artifact (HTML page), not just a scratch markdown file** — an md file is only a fallback when the Artifact tool is unavailable. The artifact is the thing Andy reviews and, critically, **copies his responses out of** — with several advisories in one sweep it is far easier to scan and paste from a rendered page than to scroll back through terminal output. Report its URL.

**The artifact is the copy/paste surface — this is the whole point of it (hard rule):**

- **Every piece of text Andy will paste somewhere lives IN the artifact, verbatim, in its own copyable block** — every advisory reply, issue comment, and PR comment. Give each its own `<pre>`/code block (a copy button is a plus). Do **not** leave a drafted response only in the terminal chat, and do **not** make him reconstruct it from prose. If you drafted it, it goes in the artifact.
- **The terminal reply summarizes and points to the artifact; it does not reproduce every response.** State what you did and what's awaiting his call, link the artifact, and let the responses live there. (A single quick reply inline is fine; the full set belongs in the artifact.)
- If you realize you told Andy a reply was "drafted" but it isn't visibly in the artifact, that's the bug this rule exists to prevent — put it in the artifact.

**Structure:**

- **Hyperlink every issue and advisory** so Andy can jump straight to it to review/confirm — this is a hard requirement, not a nicety. Make each identifier a link: issues → `https://github.com/<repo>/issues/<n>`, PRs → `.../pull/<n>`, repository security advisories → `https://github.com/getgrav/grav/security/advisories/<GHSA-ID>` (or use the advisory's `.html_url` from the API). Link the ID in the at-a-glance table, in each card header, and in the backlog table. Also link the PRs you open.
- **Top:** date, inbox counts, and a one-line-per-item status table (item → group → verdict → severity(rubric) → action needed → your recommendation).
- **Per security advisory:** GHSA id + link, state, reporter (for credit), **already-fixed verdict + commit/tag**, **severity: reporter vs rubric**, **correct fix repo / re-home note**, the **paste-ready reply in a copyable block**, and — only if unfixed — the proposed patch. Flag `reason: comment` advisories as "unread thread — check UI."
- **Per security advisory, an explicit "advisory changes" list** — the metadata edits to the GHSA record itself, separate from the code fix, each marked done vs. awaiting-you: **severity** (reporter → rubric value, and the enum is `medium` not `moderate`); **CVSS** (whether cleared or re-set, and why — note GitHub recomputes the severity label from a supplied vector, so a `PR:H` vector that still scores High will fight a Moderate label); **affected version range** and **patched version** (usually set at publish/release, not now); and any **description/framing clarification** (e.g. the reporter's "a logged-in user" when it actually needs an admin/config-admin — the exact overstatement that inflates severity). This list is a required part of every advisory card, not optional.
- **Per bug issue:** repro result, owning repo, proposed patch / PR link, **paste-ready reply in a copyable block**, disposition.
- **Needs-your-decision section:** severity disputes, repo/re-home changes, disclosure-timing calls, product choices in a fix, and any operational problems surfaced (e.g. a bounced `security@getgrav.org` breaking the SECURITY.md disclosure path is its own issue, not part of a code fix).
- Keep replies paste-ready. If a reply contains a markdown table, wrap the whole reply in a fenced code block so the terminal renderer doesn't mangle it.

### 7. Execute after sign-off

Once Andy approves specific items: open the PRs (respecting disclosure — private advisory fork for unpublished ones), post the drafted comments (copied from the artifact), close what's handled, apply advisory housekeeping (re-home mis-filed ones, set patched-version metadata, publish/close). **Then** mark those notifications done:

Apply the per-advisory **"advisory changes" list** from the artifact to the GHSA record — this is separate from landing the code fix and easy to forget. The severity enum is `medium`/`low`/`high`/`critical` (there is no `moderate`), and setting a `cvss_vector_string` makes GitHub recompute the severity label, so to hold a rubric rating that differs from the CVSS score, set severity alone and leave the vector cleared:

```bash
gh api -X PATCH repos/getgrav/grav/security-advisories/<GHSA-ID> -f severity='medium'
```

Version/affected metadata and publish are usually release-gated — set the affected range and patched version when the fix tag exists, and leave an assigned-but-unpublished advisory in the inbox as the live reminder rather than marking it done. **Then** mark the fully-handled notifications done:

```bash
gh api -X PATCH notifications/threads/<thread-id>   # mark one thread read/done
```

Mark a notification done only when its item is fully handled (or consciously deferred with Andy's ack). Report the doc URL/path and a summary of what was actioned vs. still awaiting his call.

## House conventions that bite

- **The shell is zsh, and it will silently corrupt your git/grep loops in three distinct ways.** Every one of these produced a *confident false result* in the run this list was written from, not an obvious error:
  1. **No word-splitting on unquoted variables.** `for spec in "repo 123"; do set -- $spec; done` leaves `$1` as the whole string and `$2` empty, so every `gh` call fails. Use a function with explicit args (`fetch() { repo="$1"; num="$2"; ... }` called as `fetch getgrav/grav-plugin-api 17`), or split with `${spec%% *}` / `${spec##* }`.
  2. **`:c` and friends are history modifiers, even inside double quotes.** `git show "$t:classes/Api/Foo.php"` becomes `git show "1.0.9lasses/Api/Foo.php"` → fatal → the piped `grep -c` counts 0 from empty input → **your loop reports the shipped release is clean when it is not.** Always brace: `git show "${t}:${f}"`.
  3. **Unquoted globs in flags.** `grep -rn "x" --include=*.yaml .` dies with `no matches found: --include=*.yaml` before grep runs. Quote it: `--include='*.yaml'`, or prefer `git grep -n "x" -- '*.yaml'`.
- **Any loop whose result is "0 hits / not affected / already clean" is guilty until proven innocent.** That is exactly the answer these bugs fabricate, and it is the answer that makes you close an advisory or skip a fix. Re-run one iteration *outside* the loop, un-piped, and read the raw output before believing a negative.
- **Verify batch fetches actually succeeded — check exit status and byte counts, never a stale `ls`.** A failed `gh` call writes a ~29-byte error into the output file and keeps going. Print `OK/FAIL` per item from the real exit code and eyeball the sizes; a directory listing from before the loop ran will happily show files that contain nothing but error text. Do not report an item as fetched unless you have seen its content.
- Commits: **never** add `Co-Authored-By` / AI-coauthor trailers (Andy's global rule).
- Releases target `main` for `grav-plugin-api` and `ai-translate`; git-flow on `master`/`develop` for core and most plugins. Don't tag unless asked.
- Changelog: one non-technical sentence per bullet; upcoming version = latest tag + 1; consolidate unreleased blocks, don't stack them.
- Clear caches with `bin/grav clear`, never `rm -rf` of cache subdirs.
- admin-next: i18n-first (no hardcoded English in new strings), no native browser dialogs (use `window.__GRAV_DIALOGS`), rebuild the plugin bundle after edits.
