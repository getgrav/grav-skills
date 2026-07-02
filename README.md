# Grav Skills for Claude Code

A bundle of [Claude Code](https://docs.claude.com/claude-code) skills that teach Claude how to work effectively on **Grav CMS** projects, with a particular focus on **Grav 2.0** plugin development against the new **API** and **Admin Next** architectures.

These skills are most useful if you're:

* **Upgrading an existing Grav 1.7 plugin to be 2.0-compatible** (compatibility flags, PHP 8.3+ deprecations, removing ties to the classic admin)
* **Building a new Grav 2.0 plugin from scratch** that integrates with the API and/or **Admin Next**
* **Adding Admin Next integration** to a plugin that previously had a custom classic-admin UI (custom form fields, custom admin pages, sidebar nav, panels, menu bar items)

The skills include the canonical patterns, blueprint snippets, and reference points to the **Grav Premium** plugins (Yeti Search Pro, SEO Magic, AI Pro, AI Translate, Quick Links) so Claude has real-world examples to look at when generating code.

## What's in the box

| Skill | When to use it |
|-------|----------------|
| **`grav-api-integration`** | Your plugin only needs to extend or consume the Grav API. No custom admin UI. Lightweight, narrow-scope. |
| **`grav-api-admin-next-integration`** | Your plugin has (or needs) custom Admin Next UI: custom form fields, dedicated admin pages, panels, menu bar items, sidebar nav. Includes everything the API skill covers, plus the Admin Next side. |
| **`grav-translations`** | You're editing a plugin's `languages/<lang>.yaml`, adding blueprint fields with `label:` / `help:` / `title:`, debugging humanized labels in admin2 (e.g. "Xss Security" instead of "XSS Security for Content"), or porting a Grav 1.7 lang file to Grav 2.0's ICU convention. Pairs with either of the integration skills. |
| **`grav-plugin-review`** | You're reviewing, vetting, or auditing a third-party Grav 2.0 / Admin2 plugin (e.g. a GPM `[add-resource]` submission) for compatibility and security. Encodes the review method, the verified API/Admin2 integration contract to check against, and a findings taxonomy (manifest correctness, auth, secret handling, injection, XSS, path traversal, uploads, SSE scaling, CSRF, spoofing) with concrete fixes. |
| **`grav-release`** | You're cutting a release of a Grav plugin or Grav core. Covers the two release shapes — full git-flow when work is on `develop`, tag-only when it's on a dedicated release branch (e.g. flex-objects `1.4.0`, grav `2.0`) — the prerelease decision (`testing: true`, `-rc.`/`-beta.` versions, Grav core's `defines.php`), the bare-numeric tag/title rule, release notes from the CHANGELOG, and post-release verification. |

Most plugin developers will want `grav-api-admin-next-integration`. Reach for the narrower `grav-api-integration` only when you're sure no admin UI work is needed. Pull in `grav-translations` whenever blueprint or admin labels are involved. Reach for `grav-plugin-review` when you're on the receiving end: vetting someone else's plugin rather than building your own.

## Install

In **Claude Code**, register this repository as a plugin marketplace, then install the `grav-skills` plugin from it:

```
/plugin marketplace add getgrav/grav-skills
/plugin install grav-skills@grav-skills
```

Installing the plugin makes all of the skills available. Claude can invoke them when their triggers match (e.g. when you open a Grav plugin file, or ask for help with API/Admin Next integration). You can also explicitly invoke a skill via the `Skill` tool with the relevant skill name.

To update after new skills or fixes are published:

```
/plugin marketplace update grav-skills
```

To remove:

```
/plugin uninstall grav-skills@grav-skills
/plugin marketplace remove grav-skills
```

## Usage

Once installed, the skills load automatically based on context. You don't need to invoke them by hand for most work. Examples that should pull in the right skill:

* "Help me add the **Grav 2.0 compatibility flag** to this plugin's blueprint and check it works under PHP 8.3"
* "Convert this classic-admin custom field to an **Admin Next** web component"
* "Add a custom API endpoint to this plugin so the staged **Admin Next** UI can fetch its data"
* "Build a plugin from scratch that adds a **menu bar item** with a confirmation action"

If you want to be explicit, ask Claude to use the skill by name (e.g. *"use the `grav-api-admin-next-integration` skill"*).

## Requirements

* **Claude Code** (CLI, desktop, web, or supported IDE extensions). The skills will also work in any other Claude-aware editor that supports the Claude Code skill format
* For the actual plugin work the skills generate code for: **PHP 8.3+** and **Grav 2.0** (or 2.0 RC during the release-candidate period). The skills assume the modern stack throughout

## Background reading

These skills were written alongside the Grav 2.0 launch. If you're new to the 2.0 changes:

* [Migrating to Grav 2.0](https://getgrav.org/blog/migrating-to-grav-2)
* [Grav 2.0 for Plugin Developers: Overview](https://getgrav.org/blog/grav-2-for-plugin-developers)
* [Compatibility Flags](https://getgrav.org/blog/grav-2-dev-compatibility-flags)
* The full Grav 2.0 blog series at [getgrav.org/blog](https://getgrav.org/blog)

## Contributing

Issues and pull requests welcome. Particularly useful contributions:

* Worked examples of plugin patterns the existing skills don't yet cover
* Corrections when a skill's recommended approach is out of date with the current API or Admin Next
* New skills under this same marketplace (e.g. theme development, frontend integration patterns)

For development questions or general chat, the [Grav Discord](https://chat.getgrav.org) is the fastest place to reach the team and the wider community.

## License

Apache License 2.0. See [LICENSE](LICENSE).
