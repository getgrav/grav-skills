---
name: grav-inbox-triage
description: >-
  Use for Andy's daily sweep of his GitHub notifications on the Grav org (getgrav/*): issues, pull requests, discussions and mentions. Trigger on "do my inbox", "triage my notifications", "daily inbox", "clear my github inbox", or a request to work through getgrav issues and PRs. Runs end to end without check-ins: reproduces each bug on a local reeve install, fixes it on the repo that actually owns the code (often not where it was filed), verifies the fix, merges contributor PRs with corrections applied on top, commits and pushes to develop with a CHANGELOG bullet, posts a short reply, closes the issue, and marks the notification done. Only items that need a decision from Andy (product or design calls, behavior or interface changes, releases, anything that couldn't be verified) stop and wait. Security advisories are left for the weekly grav-security-advisories run.
---

# Grav inbox triage (daily)

Andy clears his getgrav notifications daily. **You fix, reply and close on your own.** The default for every item is to take it all the way: reproduce, fix, verify, push, reply, close, mark done. Stop only when an item genuinely needs Andy (see "When to stop"), and batch those into one short list at the end rather than asking mid-run.

Security advisories are **not** part of this sweep. They go through the weekly `grav-security-advisories` skill. Leave advisory notifications untouched; just count them in the summary so Andy knows how many are waiting.

Two things hold across almost every batch, so plan for them:

- **The reporter is usually right that something is broken and usually wrong about why or about the fix.** Of the contributor PRs in a typical sweep, most carry a regression alongside the fix, and most confirmed bugs have sibling instances the reporter never found. Confirm the mechanism yourself, grep for siblings, and run the proposed fix's own failure path.
- **Check whether it's already fixed on `develop`** before writing anything. Reporters test old tags.

## Ground rules

- **Repos are checked out** under `~/Projects/grav/<repo>` on `develop`: core `grav`, `grav-plugin-*`, and the admin-next **source** at `grav-admin-next`. Sites under `~/workspace/*` are runnable reeve installs at `https://<name>.test` (self-signed, `curl -k`). Never start a `php -S` server.
- **Validation agents run on `model: "opus"`,** one per item, read-only, in parallel. You (the main session) do the committing and posting, so two agents never race on a repo. Full test suites never run in parallel; run the tests next to the change.
- **Security in public issues:** if an issue turns out to describe a real vulnerability, fix it with neutral wording (describe the fix, not the attack), reply without elaborating on the exploit, and if it is unauthenticated or serious, put it in "When to stop" instead of fixing it publicly.
- **Replies post under Andy's name.** Short, friendly, non-technical, thank the reporter. No em-dashes. Use `@handle`, never a first name guessed from a handle (only use a name the person signed themselves).

## Method

### 1. Pull the inbox

**Andy's working list is his inbox filtered to not-Done.** Done means "doesn't need looking at". Read/unread means nothing: GitHub's notification emails carry a tracking image that marks the web notification read the moment he glances at the email.

The API can't filter on Done (it has no Done flag, and `all=true` returns Done threads too), and it can't filter on unread either for the reason above. What it can do is time: **new activity on a Done thread moves it back into the inbox**, so "everything with activity since the last sweep" is exactly what has arrived in or come back to his not-Done list since then. The state file holds the rest:

```bash
STATE=~/Projects/grav/.inbox-triage/state.json   # {"last_run": "<ISO8601>", "carryover": ["<thread-id>", ...]}
SINCE=$(jq -r .last_run "$STATE")
gh api "notifications?all=true&since=${SINCE}&per_page=100" --paginate > notifications.json
jq -r '.[] | [.id,.reason,.subject.type,(.repository.full_name|sub("getgrav/";"")),.subject.title,.subject.url] | @tsv' notifications.json
```

The day's set is:

1. **Every thread in that window**, read or unread,
2. **plus the carryover**: threads the previous run left for Andy (fetch each with `gh api notifications/threads/<id>`),
3. **minus threads whose latest activity is Andy's own**: he closed or merged it, or his comment is the newest one. He has already dealt with those. Check the issue/PR timeline, not the notification.

At the end of the run, write the run's **start** time to `last_run` (so activity that arrives mid-run isn't skipped next time) and replace `carryover` with the thread ids of this run's stop items. Everything handled gets marked Done with `DELETE`, which keeps the web inbox matching.

If the state file is missing, ask Andy for the date of his last sweep and the count his inbox shows, seed `last_run`, and check the day's set against his count once. After that, don't ask. Print the item list at the start of the run and carry on without waiting for confirmation. If Andy names specific items, do those regardless of the window.

Drop `RepositoryAdvisory` items (count them). Group the rest: bug issues, PRs, feature requests/questions, and noise (CI, releases, bot activity).

### 2. Read each item properly

`gh issue view <n> -R getgrav/<repo> --json number,title,body,state,author,comments,labels` (or `gh pr view ... --json ...,files,commits,baseRefName,maintainerCanModify`). **Read the whole thread, including Andy's own comments.** If he already answered or decided something, act on that answer. Never report a decision as pending without having read the thread yourself; an agent's summary once reported an issue as undecided when Andy had answered it in full the day before.

### 3. Validate in parallel

One agent per item, given the full text in a scratch file, the repo paths, and a read-only instruction (no commits, pushes, or edits to tracked files). Each returns:

1. Already fixed? Commit and first tag that ships it.
2. Reproduced? Steps on a `.test` site and what happened; for UI bugs, drive it in Chrome.
3. The real mechanism, with `file:line`, and whether it matches the reporter's.
4. Siblings: other instances of the same defect across core and plugins.
5. Owning repo (admin-next UI bugs filed on `grav-plugin-admin2` live in `grav-admin-next`; blueprint and form bugs can be in core, api, flex-objects or admin2).
6. A fix as a unified diff with a regression test that fails on the unfixed code. For a PR, whether the PR is correct as written, and the corrections it needs.
7. Anything that makes it a "stop" item (below).

Spot-check every "already fixed" and every negative result yourself before acting on it.

### 4. Act

**Bug, reproduced, fix verified:**

1. Apply the fix on the owning repo's `develop` (check out `develop` explicitly in `grav-plugin-api`, which also has `main`).
2. Run the tests next to the change with the project's own runner and confirm the new test fails without the fix.
3. **Core changes to routing, base URL, config or blueprint resolution order, sessions or auth need a live page load** on a `.test` site before commit, including logging in to admin. Lint and unit tests have passed on changes that broke login.
4. Add a CHANGELOG bullet under the upcoming version (latest tag + 1, consolidate, never a new block per commit), one plain sentence.
5. admin-next changes: rebuild the admin2 bundle from committed HEAD (`npm run build:plugin`, stash WIP first) and add the admin2 CHANGELOG bullet. Both are required.
6. Commit only your files (`git add <paths>`, never `-A`: repos often hold Andy's uncommitted WIP). No `Co-Authored-By` trailers.
7. Before pushing, `git fetch` and check `git log origin/develop..develop`. If it holds commits that aren't yours, **don't push**: Andy's unpushed work would go public with yours. Put it in the stop list.
8. Push, reply, close, mark done.

**Contributor PR:** check the base branch (`develop`, not `main`), run their test against unfixed code (if it passes, it proves nothing), and apply the needed corrections yourself rather than requesting changes. House merge pattern: merge locally `--no-ff` with `Merge PR #N: <lowercase description>`, push, then a separate `[bugfix] ...` commit with your corrections. `getgrav/grav` blocks merge commits through the API, and some repos (e.g. `grav-plugin-simplesearch`) block merge commits entirely, so squash there. Or push corrections to the contributor's branch when `maintainerCanModify` is set, then merge. Reply thanking them and saying briefly what you adjusted.

**Already fixed:** reply with the version that has it, close.

**Can't reproduce after a real attempt:** reply with what you tried and on which version, ask for the missing detail if one would settle it, close.

**Underspecified:** ask for the specific missing thing (version, blueprint, steps). Leave open.

**Questions and support:** answer if the answer is clear from the code or docs, close if resolved.

**Noise** (CI, release, bot): mark done.

Posting:

```bash
gh issue comment <n> -R getgrav/<repo> --body-file reply.md
gh issue close <n> -R getgrav/<repo>
gh api -X DELETE notifications/threads/<thread-id>      # marks the notification Done
```

Post the comment separately from the close: `gh issue close --comment` has dropped the comment while still closing. Check the comment landed. Mark a notification done only when its item is fully handled.

### 5. When to stop and leave it for Andy

Don't act on an item, just draft, when any of these holds:

- **A product or design decision**: a feature request, a UX change, a default that would change for existing sites, restyling across a theme, declining or accepting a contribution offer.
- **A behavior or interface change**: a method added to a public interface, a changed default, anything where patch vs minor is a real question.
- **Risky areas you couldn't fully verify**: base URL and custom base paths (test the home page with a query string on a subfolder install), routing, session and auth, config resolution order. If verified live across the matrix, go ahead; if not, stop.
- **It couldn't be reproduced or verified**, and the fix isn't obviously safe.
- **A serious vulnerability in a public issue** (see Ground rules).
- **Pushing would publish Andy's unpushed commits**, or the fix collides with his uncommitted WIP.
- **Closing someone else's PR** as superseded or declined.
- **Releases.** Never tag or release. Releases happen when Andy cuts them.

For each stop item, still do everything short of the public action: the investigation, a local branch with the fix, and the drafted reply, so Andy's answer is one word.

## Report

End with a short summary in the terminal:

- One line per item: linked id, what was done (fixed + pushed / merged / already fixed / asked for info / closed as can't reproduce), commits.
- **Needs Andy:** the stop items, each with the recommendation and the drafted reply.
- **Ready to release:** repos with unreleased commits from this sweep and their upcoming version.
- Security advisories waiting for the weekly run: count.

If there are stop items with drafted replies, or more than a handful of items, publish the same content as an HTML Artifact so Andy can copy replies out of it, with every reply in its own copy block and every id linked. Update it in place if anything changes, before mentioning the change in chat.

## House conventions that bite

- **zsh corrupts git and grep loops silently**: no word-splitting on unquoted variables; `:c`/`:t`/`:h` after a variable are history modifiers even in double quotes (always brace: `"${t}:${f}"`); unquoted globs in flags fail before the command runs (`--include='*.yaml'`).
- **A loop that says "0 hits" or "not affected" is guilty until proven innocent.** Re-run one iteration un-piped and read the raw output.
- **Run tests through the project's own runner before calling one broken.** Codeception in core doesn't expand `#[DataProvider]` attributes; use `@dataProvider` docblocks.
- **`??` is an `isset()` test**: an empty-string value suppresses the fallback. A frequent contributor regression around `$_SERVER`/`getenv()`.
- **`isAdmin()` is false during API requests** (AdminProxy registers at routing), so plugins that gate on it at `onPluginsInitialized` never subscribe under admin2.
- **Portal translation commits land on admin2 `develop` mid-work.** On conflict keep their new strings, drop only your removals, and re-check the YAML parses.
- Clear caches with `bin/grav clear`, never `rm -rf`. `Admin::enablePages()` before using the pages object in admin context. admin-next: i18n for every new string, `window.__GRAV_DIALOGS` instead of native dialogs.
- Releases: `grav-plugin-api`, `admin2` and a few others release to `main`; core and most plugins use git-flow on `master`/`develop`.
