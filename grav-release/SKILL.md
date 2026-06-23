---
name: grav-release
description: >-
  Use when cutting a release of a Grav plugin (Team Grav / Trilby Media, e.g. `grav-plugin-*`) or Grav core itself. Covers the two release shapes — full git-flow when the work is on `develop`, and tag-only when the work is on a dedicated release branch (like flex-objects `1.4.0` or grav `2.0`) — plus the prerelease decision (`testing: true` in blueprints, or an `-rc.`/`-beta.` version, or Grav core's `system/defines.php`), the bare-numeric tag/title rule (never `v`-prefixed), sourcing release notes from the top CHANGELOG block, and post-release verification. Trigger when the user says "release <plugin>", "cut a release", "tag and release", "get <plugin> out", "do a prerelease", or asks to run a git-flow release / `gh release` for a Grav plugin or Grav core.
---

# Releasing a Grav plugin (or Grav core)

Cut a release of a Grav plugin or Grav core the way Andy does it. There are **two shapes**, decided by which branch the release work sits on. The version is **already set** in `blueprints.yaml` and the `CHANGELOG.md` before you start — your job is to verify it, tag it, and publish, not to invent a version.

Releases always happen in the **source repo** (`~/Projects/grav/grav-plugin-<name>` or the Grav core repo), never in a symlinked site copy under `user/plugins/`.

## The one rule that bites: bare numeric, never `v`

Tags and release titles are **bare numeric**: `3.4.5`, `2.0.0-rc.1`, `1.4.0-rc.8`. **Never** `v`-prefixed. This applies to all three of:

1. the git tag — `git tag 3.4.5`
2. the `gh release create <tag>` argument — `gh release create 3.4.5`
3. the **`--title`** — `--title "3.4.5"`, NOT `--title "v3.4.5"`

A single `v`-prefixed tag/release breaks `git tag --sort=-v:refname` and GitHub's `releases/latest`, so GPM and the gpm CLI stop seeing it as latest. CHANGELOG headings like `# v3.4.5` are fine — that's just doc text. Only the tag, the release tag arg, and the title must be bare.

## Step 0 — preflight (always)

From inside the source repo:

```bash
cd ~/Projects/grav/grav-plugin-<name>
git branch --show-current          # which branch? decides the shape (see below)
git status --short                 # MUST be clean (or only the release-prep edits)
grep -E '^version' blueprints.yaml # the version being released
head -3 CHANGELOG.md               # top heading must match that version
git tag | grep -x <version>        # MUST be absent — tag must not already exist
git rev-parse --abbrev-ref @{u} 2>/dev/null && git log --oneline @{u}..HEAD  # unpushed commits?
gh auth status                     # logged in, correct account
git remote -v                      # confirm the repo (trilbymedia/* or getgrav/*)
```

Checklist before proceeding:

- **Working tree clean.** If the version bump / changelog aren't committed yet and it's a git-flow release, you'll commit them on the release branch (Path A). For tag-only (Path B) they should already be committed and pushed.
- **`blueprints.yaml` `version:` == top `CHANGELOG.md` heading.** They should already agree. If they don't, stop and ask — don't guess which is right.
- **CHANGELOG date is today.** The `## MM/DD/YYYY` line under the top heading should be today's date, not whenever the block was first drafted. Fix it if stale.
- **Tag does not already exist.**
- **Never invent a version.** The version is whatever blueprints/changelog already say (typically last tag + 1). Don't bump it yourself per the changelog rules.

## Decide the shape

- **On `develop`?** → **Path A: git-flow.** (standard stable plugins: tntsearch, etc.)
- **On a dedicated release branch** (e.g. flex-objects `1.4.0`, grav `2.0`)? → **Path B: tag-only.** No merges.

## Decide prerelease vs full release

Mark the GitHub release as `--prerelease` if **any** of:

- `blueprints.yaml` has `testing: true`
- the version contains `-rc.` or `-beta.` (e.g. `1.4.0-rc.8`, `2.0.0-beta.5`)
- **Grav core:** the `GRAV_VERSION` in `system/defines.php` is an rc/beta (core has no `blueprints.yaml`)

Otherwise it's a normal (latest) release — omit `--prerelease`.

## Commit rules

Per Andy's global git rules: **never add `Co-Authored-By:` or any AI-assistant coauthor trailer.** Commits are solely his.

---

## Path A — git-flow (work is on `develop`)

The version-prep edits (code fix, `blueprints.yaml` bump, `CHANGELOG.md` block) may be uncommitted on `develop` or already committed. This produces the canonical graph: a `release/X.Y.Z` branch merged `--no-ff` into `master`, tagged there, then the tag merged back into `develop`.

```bash
cd ~/Projects/grav/grav-plugin-<name>

# carries any uncommitted release-prep edits onto the release branch
git checkout -b release/<version>
git add -A
git commit -m "<short description of the release changes>"   # e.g. "PHP 8.4 deprecations"

# merge into master and tag THERE (master holds the stable line)
git checkout master
git merge --no-ff release/<version> -m "Merge branch 'release/<version>'"
git tag <version>

# back-merge the tag into develop so develop carries the release commit
git checkout develop
git merge --no-ff <version> -m "Merge tag '<version>' into develop"
git branch -d release/<version>

# push everything
git push origin master develop
git push origin <version>
```

Merge-commit message style matches existing history: `Merge branch 'release/X.Y.Z'` and `Merge tag 'X.Y.Z' into develop`.

Then create the GitHub release (Path A/B share this — see "Create the GitHub release").

---

## Path B — tag-only (work is on a release branch like `1.4.0` / `2.0`)

These lines (e.g. flex-objects `1.4.0`, grav `2.0`) are **not** git-flow. The branch is the release line. The changelog + version are already committed and the branch is already pushed. You just tag the branch HEAD and publish — **no master/develop merges.**

```bash
cd ~/Projects/grav/grav-plugin-<name>     # or the Grav core repo
git status --short                         # clean
git log --oneline @{u}..HEAD               # should be empty — branch already pushed
git tag <version>                          # e.g. 1.4.0-rc.8
git push origin <version>
```

Then create the GitHub release with `--target <branch>` (the release branch name).

---

## Create the GitHub release (both paths)

Release notes come from the **top block of `CHANGELOG.md`** (the block for this version). Convert its bullets into the release body, grouped by `#new` / `#improved` / `#bugfix`.

```bash
gh release create <version> \
  --repo <org>/grav-plugin-<name> \
  --title "<version>" \              # bare numeric — NO v
  --target <branch> \               # master for Path A; the release branch (1.4.0 / 2.0) for Path B
  [--prerelease] \                  # include per the prerelease decision above
  --notes "### New
- ...

### Bugfix
- ..."
```

`--target` matters: Path A targets `master`; Path B targets the release branch.

## Verify after

```bash
gh release view <version> --repo <org>/grav-plugin-<name> \
  --json tagName,name,isPrerelease,isDraft,targetCommitish
```

Confirm: `name` is bare numeric, `isDraft:false`, and `isPrerelease` matches the decision. For git-flow, also confirm the graph: `master` at `Merge branch 'release/X.Y.Z'` (tagged), `develop` at `Merge tag 'X.Y.Z' into develop`.

## Grav core specifics

- Version lives in `system/defines.php` as `GRAV_VERSION`, not `blueprints.yaml`.
- Grav core uses a release-line branch (e.g. `2.0`) — **Path B (tag-only)**, not git-flow.
- Prerelease is decided by whether `GRAV_VERSION` is an rc/beta.
- A plugin can depend on a specific core version (its `blueprints.yaml` `dependencies`/`grav:` constraint) — if a plugin RC needs an unreleased core RC, the core has to ship first or the dependency won't resolve in GPM.

## Notes / gotchas

- The plugin under `user/plugins/<name>` in a test site may be a **symlink to the source repo** (release there) or a **GPM-installed copy** (read-only — not releasable; find its real repo under `~/Projects/grav/`).
- A prerelease never becomes GPM/GitHub "latest" — that's the point of `--prerelease` for the `-rc.`/`-beta.`/`testing:true` lines, so it stays off the `?stable=1` feed.
- If `git push` is blocked by the sandbox, retry the push with the sandbox disabled — pushing is an explicitly authorized step of a release.
