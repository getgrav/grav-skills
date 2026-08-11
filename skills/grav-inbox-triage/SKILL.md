---
name: grav-inbox-triage
description: >-
  Use when Andy asks to clean out / triage / process his GitHub notifications inbox for the Grav org (getgrav/*), or says "do my inbox", "triage my notifications", "review my github inbox", or runs his weekly security-advisory batch. Covers the full workflow: pull the inbox via `gh` and group it (security advisories, bug issues, PRs, other); fan out read-only agents to validate each item against the real checked-out repos under ~/Projects/grav; for security advisories — match against the KNOWN BUG-FAMILY REGISTER first (scope-cap, detectXss, Host-header, timing, admin-file-manager traversal — these are pre-decided and close in minutes), map the notification to its GHSA record, CONFIRM it against code, CHECK IF ALREADY FIXED on develop/tags (most reports are against an old snapshot), then assign one of THREE dispositions — not-a-vulnerability / fix-quietly-no-advisory / publish-advisory — using Grav's SECURITY.md trust-boundary rubric (NOT the reporter's CVSS), and confirm the correct fix repo (advisories filed on getgrav/grav core very often belong to grav-plugin-api or another plugin); for bug issues — reproduce via code + chrome/computer-use, fix on the repo that actually owns the code, and draft a short non-technical reply; keep ONE living review artifact as Andy's control panel; and only pull public triggers (comment/close/PR/publish, mark notifications done) after Andy signs off. Trigger on inbox/notification cleanup or weekly advisory triage for the Grav org.
---

# Grav GitHub inbox triage

Andy runs this to clear his GitHub notifications inbox for the Grav org with your help. The job is to turn a pile of notifications into **one living review document** he can act from — accept, close, comment, edit — plus prepared fixes and drafted replies. You do the investigation and preparation autonomously; **he pulls the public triggers.**

The single most valuable move in this whole workflow, learned the hard way: **before proposing any fix, check whether the issue is already fixed on `develop`.** Reporters routinely test against an old released tag (e.g. 2.0.1) and file against bugs that were patched weeks earlier. In the batch this skill was built from, **4 of 5 security advisories were already fixed and released** — the correct action was a credit-and-close reply, not a code change.

## Read this first: the acceptance bar changed (2026-08-11)

Grav's advisory intake went from ~5/month to **~60/month** between February and June 2026, and the queue is now the single biggest drain on Andy's time. Measured over the 225 advisories filed since March 2026, Grav was **publishing 51% of everything reported** (69% of Mediums, 58% of Lows), against a SECURITY.md that says Lows are "usually wontfix." A ~50% acceptance rate is what makes a project an attractive target and it is the thing generating the volume, so the policy was rewritten and **your default disposition has changed.**

**Assume a report is NOT advisory-worthy until it demonstrably crosses a trust boundary.** Read `~/Projects/grav/grav/SECURITY.md` at the start of every run — it now leads with "How we decide what is a vulnerability" and a "What we do not publish an advisory for" list, and that list is the operative rubric. The target is roughly **25% published**, so if a batch is coming out much above that, you are probably grading too generously.

### The three dispositions (assign exactly one to every advisory)

Every advisory gets one of these. Say which, by name, in the doc. "It's a real bug" is **not** an argument for publishing.

| Disposition | What it means | Reporter gets | Operator impact |
| --- | --- | --- | --- |
| **A. Not a vulnerability** | In-role capability, detectXss bypass, enumeration, self-XSS, no PoC, unsupported version, unreachable dep CVE | Polite close + link to the SECURITY.md section that covers it | None |
| **B. Fix quietly** | Real code issue, but the actor could already reach equivalent data through their granted role, or there is no demonstrated practical exploit | Credit in the **CHANGELOG**, advisory closed unpublished | None, ships in the normal release |
| **C. Publish advisory** | Crosses a trust boundary. Operators on a supported version need to know and upgrade | Credit on the published GHSA | Must act |

**B is the bucket that should absorb most of what used to be published.** Non-constant-time comparison with no practical oracle, permission tightening where the actor was already trusted with the data, traversal that needs admin file-manager rights, authenticated resource consumption with no amplification: take the patch, credit them in the CHANGELOG, close without an advisory. This is not a demotion and the reply should not read like one.

### Known bug-family register (check this BEFORE investigating)

These families are **pre-decided**. Match an incoming report against them first — it turns a 40-minute investigation into a 5-minute canned close. Each has produced multiple advisories from the same root cause:

- **`Security::detectXss()` bypasses** (vfmf, q2j8, 269c, f8wv, and counting). detectXss is a heuristic **denylist**, explicitly demoted to advisory-only and **not a security boundary**. A new string that slips past the pattern list is **disposition A, always** — no advisory, no CVE, no exception, regardless of how clever the bypass is. The only in-scope version of this report is content that renders **unescaped at an output sink**, which is a different finding and must show the rendered sink. Denylist bypasses are infinitely renewable; treating them as vulnerabilities is an open-ended commitment.
- **API-key scope-cap bypasses** (BlueprintPathResolver::resolveUserScope, MenubarController::executeAction, disable2fa, DemoController, PagesController::guardTwigContent, 95v9 createApiKey, jqgq — plus any still in triage). One root cause: an authorization path calling `isSuperAdmin()` or a bare permission string instead of going through the scope cap. Once the single chokepoint lands in `grav-plugin-api`, **new instances are disposition B** (the chokepoint is the fix, individual call sites are follow-ups) unless the report shows a scoped key actually escaping its scope in a shipped release, which is C.
- **Host-header link poisoning** (3rcc, 59vx, 5xc9, 69vf). Magic-login / reset / activation links built from an untrusted `Host`. The protection exists; instances are missing call sites. C only if the affected flow is reachable unauthenticated, otherwise B.
- **Non-constant-time comparison** (38p6, x239, and repeats). Disposition **B** by default. Only C with a demonstrated practical timing oracle over a network, which essentially never survives scrutiny in PHP.
- **Path traversal behind admin rights** (vq2x, wq58, fch7, and repeats). If the actor needs the admin file manager or backup config to reach it, they were already trusted with the filesystem: **A or B**, not C.
- **Account/email enumeration** (crh8 and repeats). Documented default behavior. **A.**

When you match a family, say so explicitly in the doc ("this is the Nth instance of the detectXss family, disposition A per the register") so Andy can confirm the pattern call rather than re-reading the whole report.

### Cadence

Andy now triages in **one batch per week** and publishes on **one release day per month**, and SECURITY.md tells reporters this. Consequences for you:

- Do not treat a fresh advisory as urgent because it is fresh. **Unauthenticated RCE or data exfiltration is the only thing that breaks the weekly cadence** — flag it immediately and say why. Everything else waits for the batch.
- Group the batch by disposition in the doc (all the A's together, then B's, then C's), not by arrival order. Andy approves the A-block in one pass, which is where the time saving actually comes from.
- Only C-bucket items need the full advisory-metadata treatment (severity, CVSS, affected range, title/description rewrite). **Do not spend that effort on A and B items** — a close comment is the whole deliverable.

### Start from the pre-triage digest

`scripts/advisory-pretriage.py` (in this skill directory) does the mechanical half ahead of time, so the weekly session starts with evidence already gathered rather than with a cold queue:

```bash
skills/grav-inbox-triage/scripts/advisory-pretriage.py            # everything in triage + draft
skills/grav-inbox-triage/scripts/advisory-pretriage.py --repos grav --state triage
```

It writes `~/Projects/grav/.advisory-triage/latest.md` (plus a dated copy and a JSON sidecar) containing, per advisory: the reporter, the reporter's CVSS, a **confidence-scored** family match, a **re-home candidate** derived from the source paths in the report, and an **already-fixed check** run against the correct local checkout.

Run it nightly so the digest is warm by review day:

```bash
crontab -e
# 47 3 * * *  /Users/rhuk/Projects/grav/grav-skills/skills/grav-inbox-triage/scripts/advisory-pretriage.py >> /tmp/grav-pretriage.log 2>&1
```

**Read the digest as evidence, not as a verdict.** It assembles facts and deliberately assigns no disposition and drafts no reply, because those are the judgement calls this skill exists to make. Two specific things to re-check rather than trust:

- **A "likely — strong evidence" already-fixed verdict** means a commit or a source comment cites the GHSA id. Confirm with `git show` and `git tag --contains` before replying "fixed in X" — the standing rule that a negative/clean result is guilty until proven innocent applies here too.
- **A `low` confidence family match** usually came from a pattern buried in a quoted PoC or a cross-reference to a prior GHSA, not from the actual finding. High and medium are worth acting on; low is a hint.

The re-home column is high value and easy to miss: `classes/Api/...` paths in a report filed on `getgrav/grav` mean the bug belongs to `grav-plugin-api`, and that single case is a large share of the mis-filed queue.

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

0. **Does this match a known bug family?** Check the register above first. A match is usually the whole answer, and it short-circuits steps 1-7 down to "name the family, assign its pre-decided disposition, write the canned close." *Do this before reading the report in depth.*
1. **Is the described code present as claimed?** Quote `file:line`.
2. **Is it ALREADY FIXED on `develop`?** `git -C <repo> log -S'<marker>'`, `git grep <GHSA-id>`, `git tag --contains <commit>`. If fixed, name the commit and the released tag(s). *Do this before anything else — it usually decides the outcome.*
3. **Disposition (A / B / C) and severity by Grav's SECURITY.md rubric (see below), not the reporter's CVSS.** Name the specific "What we do not publish" bullet if it is A. The burden of proof is on publishing: state which trust boundary is crossed, by whom, starting from what access. If you cannot write that sentence cleanly, it is not C.
4. **Is the fix repo correct?** Advisories are filed on `getgrav/grav` but the vulnerable code is frequently in a plugin — if the report's paths are `user/plugins/api/...` the fix belongs in `grav-plugin-api`, and the advisory should be re-homed.
5. **Minimal correct fix as a unified diff** (drafted, not applied), matching house code style.
6. **Draft non-technical reply to the reporter** (credit them; if fixed, name the version).
7. **Does the advisory's own title/description need correcting, and if so, what should they say?** Answer with the actual replacement text, never a note that it needs work — see the hard rule in §6. Ask explicitly: did the reporter hedge on something you confirmed, overstate reach, mis-scope the affected range, or lead with a payload that turns out to be inert? Any "yes" means you write the replacement.

Independently spot-check the agents' "already fixed" claims yourself with `git show`/`git tag --contains` before you write them into the doc — these determine whether Andy replies "fixed in X" vs. ships code.

### 4. Grav severity rubric (SECURITY.md — trust boundary, not CVSS)

Grav rates by **whether the actor escapes the trust scope of their role**, and explicitly overrides the CVSS the GitHub form computes. Read `~/Projects/grav/grav/SECURITY.md` each run in case it moved, but the shape is:

- **CRITICAL** — *unauthenticated* attacker gets RCE / data exfil / admin-equivalent control. No account.
- **HIGH** — cross-trust-boundary: a lower-privilege actor (or anon against a stored payload) runs code, exfiltrates data, or acts inside a higher-privilege session. E.g. stored XSS firing in a super-admin session; publisher→admin escalation.
- **MODERATE** — an authenticated user acts outside their role's documented scope, but impact stays in their own session / same-tier.
- **LOW** — an admin/super does something nefarious *within already-granted capabilities* → usually **wontfix / by design** (giving someone admin keys means trusting them).

Consequences to state in the doc:
- A **publisher running Twig in their own pages**, or an **admin using CLI/config**, is the role, not a vuln. Reports that are really "admin can do admin things" are **disposition A**, closed by design.
- **LOW never gets an advisory.** Under the rewritten policy a Low is either A (closed) or B (fixed quietly, CHANGELOG credit). If you find yourself writing a Low advisory card with full metadata, you have mis-bucketed it.
- **1.7 backport only** if exploitable **without** a publisher- or admin-level account. Anything needing publisher/admin → **2.0 only**.
- When your rubric rating differs from the reporter's (common — CVSS inflates), that's a **"severity needs tweaking" item Andy must see**: state reporter's rating, your rubric rating, and the one-line reason. Don't silently re-rate.

### 4b. Canned closes (A and B buckets)

Most of the batch should close with one of these. Fill the bracketed parts, keep them short and warm — these reporters are acting in good faith and a curt close is what generates argumentative follow-up threads, which cost more time than the original triage. **Always link the specific SECURITY.md section** so the close reads as policy rather than as a judgement call about their work.

**A — in-role capability:**

```
Thanks for taking the time on this, and for the clear write-up.

This one falls under our trust-boundary policy: [the actor here / a super-admin] already has [capability], so [doing X] is within the scope they were granted rather than an escape from it. We rate by whether an actor can escape their role's trust scope, not by what a given role is technically able to do — the reasoning is in our security policy:

https://github.com/getgrav/grav/blob/develop/SECURITY.md#how-we-decide-what-is-a-vulnerability

Closing on that basis. We do appreciate the report and hope you'll keep an eye on Grav.
```

**A — detectXss bypass:**

```
Thanks for the report.

`Security::detectXss()` is a heuristic denylist that flags suspicious content for human review. It is not a security boundary and it has never been complete — a denylist over an unbounded input space cannot be. Grav's actual XSS defense is escaping at output, so a payload that slips past the pattern list isn't a vulnerability on its own. This is now stated explicitly in our policy:

https://github.com/getgrav/grav/blob/develop/SECURITY.md#what-we-do-not-publish-an-advisory-for

If you can show this payload rendering **unescaped at an output sink**, that's a genuine finding and we'd very much like to see it — please open a new report with the rendered sink included.
```

**A — no PoC:**

```
Thanks for flagging this.

We can't act on this as filed — we need a minimal, working proof of concept that we can run to confirm both the issue and the fix, plus the exact version and commit tested. We're a small team and we don't have the capacity to build the PoC from a code-reading, so reports without one get closed.

https://github.com/getgrav/grav/blob/develop/SECURITY.md#pencil-reporting-a-vulnerability

If you can put a working PoC together, please do refile — we'll take a proper look.
```

**A — already fixed:**

```
Thanks for the report — good catch, though it turns out you're behind the fix.

This was resolved in [VERSION] ([commit]). You were testing against [tested version]; upgrading should clear it.

Closing as already fixed, but the analysis was sound and we'd welcome more.
```

**B — fix quietly:**

```
Thanks, this is a real improvement and we're taking the fix.

We're not issuing an advisory for it: [the actor could already reach equivalent data through their granted role / there's no demonstrated practical exploit path], so there's nothing operators need to act on urgently. It'll ship in [VERSION] and you're credited in the CHANGELOG. Our policy on which issues get advisories vs. quiet fixes is here:

https://github.com/getgrav/grav/blob/develop/SECURITY.md#what-we-do-not-publish-an-advisory-for

Appreciate the report.
```

**Wrong repo (re-home):**

```
Thanks for the report. This code lives in [PLUGIN], not Grav core — the advisory needs to be against [REPO] so it lands with the right maintainers and the right affected package.

We've re-homed it. No action needed from you.
```

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

- **Every piece of text Andy will paste somewhere lives IN the artifact, verbatim, in its own copyable block** — every advisory reply, issue comment, PR comment, **and every replacement advisory title or description**. Give each its own `<pre>`/code block (a copy button is a plus). Do **not** leave a drafted response only in the terminal chat, and do **not** make him reconstruct it from prose. If you drafted it, it goes in the artifact.
- **Anything you recommend changing, you write.** The test: could Andy execute this item using only copy and paste from the artifact? If acting on your recommendation would require him to *compose* something — a reworded title, a corrected impact paragraph, a changelog bullet — then you have handed the work back and the item isn't done. Write the text.
- **The terminal reply summarizes and points to the artifact; it does not reproduce every response.** State what you did and what's awaiting his call, link the artifact, and let the responses live there. (A single quick reply inline is fine; the full set belongs in the artifact.)
- If you realize you told Andy a reply was "drafted" but it isn't visibly in the artifact, that's the bug this rule exists to prevent — put it in the artifact.

**Structure:**

- **Hyperlink every issue and advisory** so Andy can jump straight to it to review/confirm — this is a hard requirement, not a nicety. Make each identifier a link: issues → `https://github.com/<repo>/issues/<n>`, PRs → `.../pull/<n>`, repository security advisories → `https://github.com/getgrav/grav/security/advisories/<GHSA-ID>` (or use the advisory's `.html_url` from the API). Link the ID in the at-a-glance table, in each card header, and in the backlog table. Also link the PRs you open.
- **Top:** date, inbox counts, and a one-line-per-item status table (item → group → **disposition (A/B/C)** → verdict → severity(rubric) → action needed → your recommendation). Include a **disposition tally and the published-rate for the batch** (e.g. "12 advisories: 7 A, 3 B, 2 C = 17% published") so Andy can see at a glance whether the bar is holding near the ~25% target.
- **Group advisory cards by disposition, A then B then C.** The A-block exists to be approved in a single pass, so keep those cards to three lines each: family matched (if any), the SECURITY.md bullet that covers it, and the canned close. Do not pad them.
- **Per security advisory:** GHSA id + link, state, reporter (for credit), **disposition + why**, **already-fixed verdict + commit/tag**, **severity: reporter vs rubric**, **correct fix repo / re-home note**, the **paste-ready reply in a copyable block**, and — only if unfixed — the proposed patch. Flag `reason: comment` advisories as "unread thread — check UI."
- **Per security advisory, an explicit "advisory changes" list** — the metadata edits to the GHSA record itself, separate from the code fix, each marked done vs. awaiting-you: **severity** (reporter → rubric value, and the enum is `medium` not `moderate`); **CVSS** (whether cleared or re-set, and why — note GitHub recomputes the severity label from a supplied vector, so a `PR:H` vector that still scores High will fight a Moderate label); **affected version range** and **patched version** (usually set at publish/release, not now); and the **title and description** (see the hard rule below). This list is a required part of every advisory card, not optional.
- **If you say the title or description needs changing, you MUST write the replacement.** This is a hard rule and the most common way this workflow fails Andy. "The impact text overstates this" / "the description needs reframing" / "the title should mention X" is a *finding*, not a deliverable — it hands the actual writing back to him, on the one artifact whose entire purpose is that he can copy from it and act. Whenever you flag a wording problem, ship, in its own copy block in that advisory's card: the **full replacement title** (verbatim, one line, ready to paste), and/or the **full replacement description** (complete markdown, publication-quality, not a diff or a list of notes on what to change). Say in one line *why* it changed so he can sanity-check the judgement. Same discipline for a **CWE** you think is wrong: name the replacement CWE, don't just say the current one is off.
  - Common triggers, all of which the reporter writes in good faith and none of which you should publish unedited: the report **hedges on something you then confirmed** ("I have not built this PoC" when you did — publishing the hedge understates the advisory); the report **overstates reach** ("or anywhere this is user-influenced" when no such path exists in the codebase); the **headline payload turns out to be inert** while a variant you found is the live one (the title now points at the wrong thing); the report **names only the version they tested** when the flaw shipped earlier; the title is **internal shorthand** — 30 words, a prior GHSA id, an implementation detail like a library function name — rather than something an operator can act on.
  - House style, checked against published advisories: the reporter's report largely *is* the description, so **preserve their technical substance and their evidence** and correct the framing around it. Don't erase their work and don't quietly delete the sentence you disagreed with — where you overrode their own assessment, say so in the text ("The original report noted … while declining to claim it as verified. We have since confirmed it."). Keep the `## Summary / ## Affected versions / ## Details / ## Impact / ## Patches / ## Credits` shape, and credit them by handle.
  - Put a **short title into the metadata PATCH** as `"summary"`, since it survives a heredoc cleanly. Leave the **long description to be pasted into the advisory editor** — a multi-paragraph markdown body round-tripped through shell quoting is more likely to be mangled than helped. Say which is which in the card.
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
