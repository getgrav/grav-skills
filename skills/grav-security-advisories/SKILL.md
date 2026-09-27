---
name: grav-security-advisories
description: >-
  Use for Andy's weekly batch of GitHub security advisories on the Grav org (getgrav/*), or when he says "do the advisories", "weekly advisory triage", "security batch", "process the GHSA queue", or hands over a single GHSA to handle. Works from the nightly pre-triage digest, matches each advisory against the known bug-family register, confirms it against the checked-out code, checks whether it is already fixed on develop or a tag, and assigns one of three dispositions (A not a vulnerability, B fix quietly, C publish) using Grav's SECURITY.md trust-boundary rubric rather than the reporter's CVSS. Then does as much of the work as the GitHub API allows: lands B and C fixes with neutral commit wording, and applies the advisory metadata directly (title, description, severity, CVSS, CWE, owning package / re-home, affected and patched versions, credits). Advisory comments have no API, so every reply goes into one review artifact as paste-ready text; closing happens after Andy has posted them, and publishing waits for the release tag. For daily issue and PR notifications use grav-inbox-triage instead.
---

# Grav security advisories (weekly)

Andy triages security advisories in one batch per week and publishes on release day. This skill runs that batch. The goal is that when the run ends, the only things left for Andy are the things GitHub will not let you do (posting advisory comments) and the calls that are his (publishing, release timing, anything in "Needs Andy").

**Do the work, don't describe it.** Metadata edits go straight onto the advisory through the API. Fixes get committed. Replies get written in full. If you catch yourself writing "the title should mention X" or "the description needs reframing", stop and write the replacement instead.

The single most valuable check: **is it already fixed?** Reporters routinely test an old tag and file against bugs patched weeks earlier. In the batch that started this workflow, 4 of 5 advisories were already fixed and released. The second most valuable: **the reporter is usually right that something is broken and usually wrong about why.** In a typical batch 5 of 6 reports have the right symptom and the wrong mechanism, and most confirmed bugs have sibling instances the reporter never found.

## Ground rules

- **PoCs run only against installs Andy owns.** Reproduce on a local reeve site (`https://<name>.test`) or a hosted site Andy administers. Never send a PoC or payload to a URL from the report, the reporter's own test host, or any third-party Grav site, even to check whether it is live.
- **An advisory in `triage` or `draft` is an unpublished vulnerability.** Commit messages, CHANGELOG bullets, branch names, test names and any public comment use neutral wording: describe the fix ("Validate the form flash id before using it in a path"), never the attack, and never a GHSA id. This is the house precedent, and it is what lets fixes land on `develop` before the advisory publishes.
- **Read `~/Projects/grav/grav/SECURITY.md` at the start of every run.** It is the operative rubric and it changes. Where it and this skill disagree, SECURITY.md wins, and you should fix this skill.
- **Repos are checked out** under `~/Projects/grav/<repo>` on `develop`. Validate against the code, not memory. Sites under `~/workspace/*` are runnable reeve installs, not git repos.
- **Validation agents run on `model: "opus"`,** one per advisory, read-only, in parallel. Full test suites never run in parallel; run the tests next to the change, and leave whole suites to one serial run at the end if at all.

## The acceptance bar

Grav's intake went from about 5 advisories a month to about 60 in the first half of 2026, and Grav was publishing roughly half of them. A 50% acceptance rate is what makes a project an attractive target, so the policy was rewritten: **assume a report is not advisory-worthy until it demonstrably crosses a trust boundary.** The target is about **25% published**. If a batch comes out well above that, you are grading too generously.

### The three dispositions

Assign exactly one to every advisory and name it in the artifact. "It's a real bug" is not an argument for publishing.

| Disposition | Meaning | Reporter gets | You do |
| --- | --- | --- | --- |
| **A. Not a vulnerability** | In-role capability, denylist bypass, enumeration, self-XSS, no PoC, unsupported version, unreachable dependency CVE, already fixed | A polite close linking the SECURITY.md section | Write the reply. No metadata edits, no code. |
| **B. Fix quietly** | Real code issue, but the actor could already reach the same thing through their role, or there is no practical exploit | CHANGELOG credit, advisory closed unpublished | Land the fix, credit them in the CHANGELOG, write the reply. No metadata edits. |
| **C. Publish** | Crosses a trust boundary; operators on a supported version need to upgrade | Credit on the published GHSA | Land the fix, apply every metadata edit, write the reply. |

**B absorbs most of what used to be published.** Non-constant-time comparison with no practical oracle, permission tightening where the actor already had the data, traversal that needs admin file-manager rights, authenticated resource use with no amplification, anything that first needs the actor to install a plugin or write arbitrary files (they could already run their own PHP). The B reply should not read like a demotion.

**The burden of proof is on C.** Write one sentence: who the actor is, what access they start with, which boundary they cross, what they end up with. If that sentence doesn't come out clean, it is not C.

### Severity (SECURITY.md, trust boundary not CVSS)

- **Critical**: an unauthenticated attacker gets RCE, data exfiltration or admin-equivalent control.
- **High**: cross-trust-boundary. A lower-privilege actor (or an anonymous visitor via a stored payload) runs code, exfiltrates data or acts inside a higher-privilege session.
- **Moderate**: an authenticated user acts outside their role's documented scope, impact stays in their own session or tier.
- **Low**: an admin or super-admin doing something within granted capabilities. Low never gets an advisory; it is A or B.

GitHub's CVSS calculator typically inflates these reports to High by assuming `PR:N`. When your rating differs from the reporter's, set yours and say why in one line in the artifact.

**Backport to 1.7** only when it is exploitable without a publisher or admin account and has real impact (SECURITY.md "What gets backported to 1.7"). Anything needing publisher or admin access is fixed on 2.x only.

**The non-default-config exemption needs both halves.** "Only happens under a non-default configuration" closes a report only if the docs *already warn* that the setting is dangerous. Read the actual help text and grav-learn page before you invoke it. The Clockwork report failed this test because the docs recommended the feature.

### Known bug-family register (check before investigating)

These are pre-decided. A match turns a 40-minute investigation into a 5-minute close. Say so explicitly in the artifact ("Nth instance of the detectXss family, A per the register").

- **`Security::detectXss()` bypasses**: always **A**. It is a heuristic denylist, not a boundary. Only in scope as a different finding: content that renders unescaped at an output sink, with the rendered sink shown.
- **`Utils::isDangerousFunction()` bypasses**: always **A**, same reasoning. The Twig content sandbox is the boundary. A *string callable of any kind* executing inside sandboxed content is in scope.
- **API-key scope-cap bypasses** (an authorization path calling `isSuperAdmin()` or a bare permission string instead of the scope cap). The chokepoint and `ScopeCapChokepointTest` are in `grav-plugin-api`; new instances are **B** unless a scoped key actually escapes its scope in a shipped release, which is **C**. Plugin-side `requireXPermission()` gates are where this keeps reappearing; the fix is the api plugin's `scopeAllows()`. Grep every plugin for the same gate when one turns up.
- **Host-header link poisoning** (magic-login, reset, activation and invite links built from an untrusted `Host`). **C** only if the flow is reachable unauthenticated, otherwise **B**. Check all link-bearing emails, not just the reported one.
- **Non-constant-time comparison**: **B**. C only with a demonstrated network timing oracle, which essentially never survives scrutiny in PHP.
- **Path traversal behind admin rights** (file manager, backup config): **A or B**.
- **Account or email enumeration**: **A**, documented default behavior.
- **Needs a plugin installed or arbitrary file write first** (blueprint XSS, package-description XSS, poisoned job queue or index files): **B**. The actor could already run PHP.

## Method

### 1. Start from the pre-triage digest

`scripts/advisory-pretriage.py` in this skill directory gathers the evidence overnight: the full record, the reporter's CVSS, a confidence-scored family match, a re-home candidate from the source paths in the report, and an already-fixed check against the right checkout. It writes `~/Projects/grav/.advisory-triage/latest.md` plus a dated copy and a JSON sidecar.

```bash
~/.claude/skills/grav-security-advisories/scripts/advisory-pretriage.py            # triage + draft, all repos
~/.claude/skills/grav-security-advisories/scripts/advisory-pretriage.py --repos grav --state triage
```

**Check the digest's date first.** If `latest.md` is not from the last day or two, the cron is broken: look at `tail /tmp/grav-pretriage.log`, then just run the script by hand. Cron has no `/opt/homebrew/bin` in its PATH, so the crontab line must set it inline:

```
47 3 * * * PATH=/opt/homebrew/bin:/usr/bin:/bin /Users/rhuk/Projects/grav/grav-skills/skills/grav-security-advisories/scripts/advisory-pretriage.py >> /tmp/grav-pretriage.log 2>&1
```

Read the digest as evidence, not a verdict. A "likely fixed" verdict means a commit or comment cites the GHSA; confirm it with `git show` and `git tag --contains`. A `low` confidence family match usually came from a pattern quoted in the PoC; treat it as a hint. The re-home column is high value: `classes/Api/...` or `user/plugins/<name>/...` paths in an advisory filed on `getgrav/grav` mean the bug belongs to that plugin.

The script's `FAMILIES` list mirrors the register above. When you add a family here, add its patterns there.

### 2. Fetch the full records

```bash
gh api "repos/getgrav/<repo>/security-advisories?state=triage" --paginate
gh api repos/getgrav/<repo>/security-advisories/<GHSA-ID>
```

`.description` is the report; also `.state`, `.severity`, `.cvss`, `.cwes`, `.vulnerabilities`, `.credits`, `.html_url`. **Advisory comment threads are not in the REST API.** If the notification reason is `comment`, someone replied (maybe the reporter, maybe "this is fixed"); flag it for Andy to read in the UI before acting on that advisory.

### 3. One validation agent per advisory

Give each agent the advisory text in a scratch file, the repo paths, a strict read-only instruction (no commits, no pushes, no edits to tracked files) and the PoC rule from Ground rules. Require answers in this order:

0. **Family match?** If yes, name it and its disposition and stop at a short answer.
1. **Already fixed?** `git log -S'<marker>'`, `git grep <GHSA-id>`, `git tag --contains <sha>`. Name the commit and the first tag that ships it.
2. **Is the code as claimed?** Quote `file:line`. Confirm the mechanism yourself; the reporter's is often wrong while the symptom is real.
3. **Reproduced?** On a local `.test` install, with the exact request or steps and what came back.
4. **Siblings.** Grep for every other instance of the same defect. The fix is the family, not the line.
5. **Disposition and rubric severity**, with the one-sentence boundary statement for C or the SECURITY.md bullet for A/B.
6. **Owning repo and package.** The repo the fix goes in, and the composer package name for the advisory.
7. **Affected range.** When the vulnerable code was introduced (`git show "${tag}:${file}"` across tags), not just the version the reporter tested. Whether 1.7 is affected and qualifies for a backport.
8. **Fix** as a unified diff, house style, with a regression test that fails on the unfixed code.
9. **For C only: replacement title, full replacement description, CWE ids.**

Spot-check every "already fixed" and every negative finding yourself before it goes in the artifact. A negative result from a loop is guilty until proven innocent (see House conventions).

### 4. Land the fixes (B and C)

- Fix on the repo that owns the code, on `develop` (for `grav-plugin-api`, check out `develop` explicitly; `main` also exists and is its release branch).
- Neutral commit message, no GHSA id, no attack description. Add a CHANGELOG bullet under the upcoming version (latest tag + 1): one plain sentence, crediting the reporter by `@handle` for B items. Never invent a first name from a handle.
- Run the tests next to the change with the project's own runner (`vendor/bin/codecept` in core), and confirm the new test fails without the fix.
- For a 1.7 backport, apply the same fix on the `1.7` branch with the same neutral wording.
- **Push B and C fixes** to `develop` (and `1.7`) once tests pass, after checking `git log origin/develop..develop` holds only your commits. If Andy has unpushed local work on the branch, don't push; list it in Needs Andy.
- **Hold a Critical (unauthenticated) fix locally and put it in Needs Andy.** Pushing it starts the clock before a release exists, and release timing is Andy's call.
- Never tag or release. List what is ready to ship.

### 5. Apply the advisory metadata (C only)

Do this through the API, not as a list for Andy. Build the body with `jq --rawfile` so a multi-paragraph description survives without shell quoting:

```bash
jq -n \
  --arg summary "Publisher can read site configuration through page Twig" \
  --rawfile description desc.md \
  '{summary: $summary,
    description: $description,
    severity: "high",
    cvss_vector_string: null,
    cwe_ids: ["CWE-200"],
    vulnerabilities: [
      {package: {ecosystem: "composer", name: "getgrav/grav"},
       vulnerable_version_range: ">= 2.0.0, < 2.1.11", patched_versions: "2.1.11"},
      {package: {ecosystem: "composer", name: "getgrav/grav"},
       vulnerable_version_range: "< 1.7.53.5", patched_versions: "1.7.53.5"}
    ]}' > body.json
gh api -X PATCH repos/getgrav/<repo>/security-advisories/<GHSA-ID> --input body.json
gh api repos/getgrav/<repo>/security-advisories/<GHSA-ID> | jq '{summary,severity,cvss,cwes,vulnerabilities}'
```

Then read it back and confirm every field took.

- **Severity** is `critical`/`high`/`medium`/`low` (there is no `moderate`). A CVSS vector makes GitHub recompute the label, so to hold a rubric severity that differs from the calculator, clear the vector (`null`) and set severity alone.
- **Re-homing** a plugin bug filed on core is a `vulnerabilities[].package` change to `getgrav/grav-plugin-<name>`. GitHub has no advisory transfer; the advisory stays hosted where it was filed, and the reporter thread and credit survive. A blank ecosystem leaves the advisory unmapped in the GHSA database.
- **`vulnerabilities` is replaced wholesale**, so send every entry, including a separate one for the 1.7 line when it is affected.
- **Patched version** is the upcoming release (latest tag + 1) for that repo, even though it is not tagged yet. Publishing waits for the tag.
- **Credits**: confirm the reporter is in `.credits`; add them (`credits: [{login, type: "reporter"}]`) if not.
- **Description**: the report largely *is* the description, so keep their technical substance and evidence and correct the framing around it. Use `## Summary / ## Affected versions / ## Details / ## Impact / ## Patches / ## Credits`. Where you overrode their assessment, say so in the text ("The original report described this as unauthenticated. Exploitation requires a publisher account."). Common corrections: a hedge on something you confirmed, reach that doesn't exist in the code, a headline payload that turned out inert while a variant is live, an affected range that names only the tested version, a title that is internal shorthand instead of something an operator can act on.
- **Title**: short, operator-facing, names the component and the actor ("Publisher can ..."), no function names or GHSA ids.

Grav does not request CVEs (SECURITY.md), so never call the CVE endpoint.

### 6. Closing and publishing

- **A and B close only after Andy has posted the reply.** Closing first sends the reporter a bare close notification, which is what generates argumentative follow-up threads. When Andy says the replies are posted, close them in one batch: `gh api -X PATCH repos/getgrav/<repo>/security-advisories/<GHSA-ID> -f state=closed`. (He may also click Close in the UI right after pasting; check the state before closing.)
- **C publishes when the tag that ships the fix exists** and Andy says to publish: `-f state=published`. Check the metadata once more first; the patched version must match a real tag.
- **Temporary private forks** (`getgrav/<repo>-ghsa-xxxx`) with open PRs block publishing. If the PR's commit is already public (`git tag --contains <sha>`), close it with `gh pr close N -R getgrav/<fork>` and no `--comment` (workspace repos reject comments).
- At the end of the run, mark the advisory notification threads done: `gh api -X DELETE notifications/threads/<thread-id>`. Andy tracks advisories through this skill and the digest, not the inbox. Never treat an advisory's absence from the inbox as evidence it was resolved.

### 7. Needs Andy

Put these in their own section at the top of the artifact, each with your recommendation and the text he'd need:

- Posting the replies (always; there is no API).
- Publishing C advisories, and release timing for any Critical.
- A disposition you are genuinely unsure of, with both readings and your pick.
- A fix that changes public behavior or an interface (patch vs minor is his call).
- Unpushed local work of his on a branch you needed to push.
- Operational problems found along the way (a bounced `security@getgrav.org`, a broken cron).

## Canned replies

Fill the brackets, keep them short and warm. These reporters are acting in good faith, and a curt close is what generates follow-ups that cost more than the triage. Always link the SECURITY.md section so the close reads as policy. **They post under Andy's name: no em-dashes, and `@handle` rather than a guessed first name.**

**A, in-role capability:**

```
Thanks for taking the time on this, and for the clear write-up.

This one falls under our trust-boundary policy: [the actor] already has [capability], so [doing X] is within the scope they were granted rather than an escape from it. We rate by whether an actor can escape their role's trust scope, not by what a role is technically able to do. The reasoning is in our security policy:

https://github.com/getgrav/grav/blob/develop/SECURITY.md#triangular_ruler-how-we-decide-what-is-a-vulnerability

Closing on that basis. We do appreciate the report and hope you'll keep an eye on Grav.
```

**A, denylist bypass (detectXss or isDangerousFunction):**

```
Thanks for the report.

[`Security::detectXss()` / `Utils::isDangerousFunction()`] is a denylist. It exists to catch common mistakes, it has never been complete, and a denylist over an open input space can't be. It isn't a security boundary: [Grav's XSS defense is escaping at output / the Twig content sandbox is the boundary]. A new value that slips past the list isn't a vulnerability on its own, and our policy says so explicitly:

https://github.com/getgrav/grav/blob/develop/SECURITY.md#no_entry-what-we-do-not-publish-an-advisory-for

If you can show [this payload rendering unescaped at an output sink / a string callable executing inside sandboxed content], that's a genuine finding and we'd very much like to see it. Please open a new report that includes the rendered result.
```

**A, no PoC:**

```
Thanks for flagging this.

We can't act on this as filed. We need a minimal, working proof of concept that we can run to confirm both the issue and the fix, plus the exact version and commit tested. We're a small team and don't have the capacity to build the PoC from a code reading, so reports without one get closed.

https://github.com/getgrav/grav/blob/develop/SECURITY.md#pencil-reporting-a-vulnerability

If you can put a working PoC together, please refile and we'll take a proper look.
```

**A, already fixed:**

```
Thanks for the report. Good catch, though it turns out you were testing behind the fix.

This was resolved in [VERSION] ([commit]). You tested [tested version]; upgrading clears it.

Closing as already fixed, but the analysis was sound and we'd welcome more.
```

**B, fix quietly:**

```
Thanks, this is a real improvement and we're taking the fix.

We're not issuing an advisory for it: [the actor could already reach the same thing through their granted role / there's no practical exploit path], so there's nothing operators need to act on urgently. It ships in [VERSION] and you're credited in the CHANGELOG. Our policy on advisories vs. quiet fixes is here:

https://github.com/getgrav/grav/blob/develop/SECURITY.md#no_entry-what-we-do-not-publish-an-advisory-for

Appreciate the report.
```

**C, accepted:**

```
Thanks for the report, confirmed and fixed.

[One or two sentences on what we changed from the report and why, e.g. "Exploitation needs a publisher account, so we've rated it High rather than Critical, and the affected range goes back to 2.0.0."] The fix ships in [VERSION] and the advisory publishes with that release, with you credited.

Thanks again for the careful work.
```

**Re-homed** (append to whichever reply applies):

```
This code lives in [PLUGIN] rather than Grav core, so we've pointed the advisory at that package. Nothing needed from you.
```

## The review artifact

One artifact per batch, published as an HTML Artifact, updated in place as things change. Andy acts straight off it, so republish **before** reporting a change in chat, and never leave a superseded recommendation in it.

- **Top:** date, the disposition tally and published rate ("9 advisories: 5 A, 3 B, 1 C = 11% published"), Needs Andy, then one line per advisory: linked GHSA id, disposition, rubric severity (and the reporter's, if different), owning package, status (reply ready / fix pushed / metadata applied / waiting on tag).
- **Cards grouped A, then B, then C.** A cards are three lines: family or SECURITY.md bullet, one-line why, the reply in a copy block. Andy approves the A block in one pass; that's where the time saving comes from.
- **B cards:** why it's B, the commit(s) pushed, the CHANGELOG line, the reply.
- **C cards:** the boundary sentence, already-fixed verdict, commits, and an "applied to the advisory" list (title, description, severity + CVSS, CWE, package, affected and patched ranges, credits), each marked applied with the value. Show the applied title and description in copy blocks so he can read what went live. Then the reply.
- **Every GHSA id, commit and PR is a link.** Every reply is in its own copy block, verbatim. If a reply contains a table, fence the whole thing.
- Flag `reason: comment` advisories as "unread thread, check the UI first".

The terminal reply summarizes what was done, what is waiting on Andy, and links the artifact. It does not repeat the replies.

## House conventions that bite

- **zsh corrupts git and grep loops silently.** (1) No word-splitting on unquoted variables: `set -- $spec` leaves `$2` empty. Use functions with explicit args. (2) `:c`, `:t`, `:h` are history modifiers even inside double quotes: `git show "$t:classes/Foo.php"` mangles the path, the piped `grep -c` counts 0, and the loop reports a shipped release as clean. Always brace: `"${t}:${f}"`. (3) Unquoted globs in flags die before the command runs: quote `--include='*.yaml'` or use `git grep -- '*.yaml'`.
- **A loop that says "0 hits / not affected / already clean" is guilty until proven innocent.** Re-run one iteration outside the loop, un-piped, and read the raw output.
- **Check batch fetches for real.** A failed `gh` call writes a ~29-byte error into the output file and keeps going. Print OK/FAIL from the exit code and check sizes.
- **Run tests through the project's own runner before calling one broken.** An agent once built its own PHPUnit harness and wrongly reported a correct regression test as dead.
- **`UserObject::authorize()` returns false for any user loaded from disk** (it short-circuits before the ACL). Privilege checks on a loaded, non-session user are silent no-ops; reporter patches that rely on it fail.
- **The Twig sandbox is per-source.** `isSandboxed()` with no Source is always false in Grav; a guard that relies on it never fires in production.
- Never add `Co-Authored-By` trailers. Clear caches with `bin/grav clear`. Releases: `grav-plugin-api` and a few others release to `main`, core and most plugins use git-flow on `master`/`develop`.
