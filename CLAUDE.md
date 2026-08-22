# CLAUDE.md — VibeSnip

Standalone WordPress plugin. A from-scratch, AI-native competitor to WPCode and Code Snippets. Read `README.md` first for status and architecture.

## What this is

A code snippets manager whose real product is a **safety loop** that will let an AI write and ship code that changes the live site without ever leaving it broken: describe → generate → validate → approve → activate → health-check → auto-rollback. P0/P1 (foundation + safety) are built; P2–P4 add the modern editor, AI Compose, and the WordPress Abilities API adapter.

**The loop deliberately has no dry-run step.** 0.9.0 removed the sandbox Test button and the CSS/HTML Preview pane. Testing a candidate meant the site making a blocking HTTP request to itself, which self-deadlocks on any single-worker host and is blocked outright on many shared hosts — it returned a 25-second timeout far more often than a result. Do not reintroduce a synchronous loopback. If a dry-run is ever wanted again, it has to run somewhere other than this site (that is the Cloud add-on's job), or be fully async with polling. Safety does not depend on it: validate-before-activate, auto-deactivate-on-fatal, the post-activation health-check and Safe Mode all remain.

**VibeSnip is free, community-run and donation-funded — there is no paid tier and none is planned.** The cloud add-on that older documents describe was cancelled. `ROADMAP.md` is the current plan of record and is written for contributors; read it before proposing anything sizeable.

**VibeSnip is self-hosted. It is not going in the wordpress.org directory.** It was submitted and it was **rejected**, so that route is closed and is not being retried. Distribution is the GitHub Releases zip plus the built-in update checker (`includes/class-vibesnip-updater.php`), which is how users install and update it.

Do not reason from wordpress.org guidelines. Concretely, none of these apply any more: no Plugin Check gate, no ban on loading a third-party script from another host, no `Tested up to:` policing, no writing the diff for a reviewer. If a change is good for the user and honest about what it does, that is the whole bar. The older planning documents (`SUBMISSION.md`, `MONETIZATION.md`, `PLAN*.md`, `LEDGER.md`, `PROGRESS.md`, `IDEA-LIBRARY.md`) argued from directory rules and from a paid-cloud plan that no longer exists. **They have been moved out of the repository** to `antiGrav/vibesnip-vital/` — kept as history, deliberately not shipped and not tracked here. `ROADMAP.md` replaces all of them as the single forward-looking document.

What survives from that era is the engineering, not the compliance: the security chain, the guard, and honest disclosure of anything the plugin sends anywhere. See **Release gates** below.

## Working principles

Bias toward caution over speed; use judgment on trivial tasks.

1. **Think before coding.** State assumptions explicitly; if uncertain, ask. If multiple interpretations exist, surface them — don't pick silently. If a simpler approach exists, say so.
2. **Simplicity first.** Minimum code that solves the problem. No speculative features, single-use abstractions, or config nobody asked for. If 200 lines could be 50, rewrite it.
3. **Surgical changes.** Touch only what the request requires. Don't "improve" adjacent code, don't refactor what isn't broken, match existing style. Remove only the orphans *your* change created; flag pre-existing dead code, don't delete it. Every changed line should trace to the request.
4. **Goal-driven execution.** Turn tasks into verifiable goals and loop until they pass. See **Verify** — a change is not done until it has been *run*, not merely linted.

## Writing code a human will read

The bar is: a WordPress developer opens any file in this plugin and cannot tell whether a person or a model wrote it. Concretely:

- **Comment the *why*, never the *what*.** `// Increment the counter` is noise. `// Buffered because a snippet that echoes here breaks the redirect header` is the reason this file exists. If a block needs no justification, it needs no comment.
- **One function, one job, one screen.** If a method needs a "part 2" comment or scrolls past ~60 lines, it is two functions. `admin/class-vibesnip-admin.php` has already drifted past this (menu + 8 page renderers + 6 form handlers + AJAX in one 1200-line class) — **don't add to it**; new screens and new handlers get their own file.
- **Name things after what they mean to the user**, not the mechanism. `health_check`, `fail_snippet`, `can_author` are right. `process_data`, `handle_stuff`, `do_thing` are not.
- **Prefer WordPress core idiom over clever PHP.** `wp_parse_args`, `wp_remote_post`, `$wpdb->update`, `checked()`/`disabled()`. A reviewer skims for familiar shapes; unfamiliar shapes get read line by line.
- **No abstraction with one implementation.** No interfaces, no factories, no `providers/` directory until there is a genuine second provider. A doc comment saying "provider-agnostic by design" is fine; a provider *layer* for one provider is not.
- **No dead configuration.** Every option, filter and constant must have a live caller. If you add one "for later", you are adding maintenance to buy nothing.
- **Every `phpcs:ignore` carries its reason on the same line**, after `--`. A bare `// phpcs:ignore WordPress.DB.DirectDatabaseQuery` tells the next reader (and the reviewer) nothing and reads as suppression. Several existing ones are bare — fix them as you touch them, not in a sweep.
- **Sanitizers are lossy; never round-trip an identifier through one.** `sanitize_key()` lowercases. `sanitize_text_field()` collapses whitespace. If a value is a token, an ID or a hash, validate its *shape* (`preg_match`, `ctype_alnum`, `strlen`) — do not "clean" it and then compare it to the original. This exact mistake silently killed the sandbox Test button for its whole life: `sanitize_key()` lowercased a mixed-case token, so the transient it was minted under could never be found again — zero of 20,000 sampled tokens survived the round-trip. (The feature was removed in 0.9.0 for an unrelated reason, but the lesson stands.)

## Hard rules

- **`includes/execution.php` must stay in the global namespace.** Snippet code is `eval`'d there so it resolves `WP_Query`, `add_action`, constants, etc. like theme code. Namespacing it would break user snippets that reference classes.
- **`eval()` exists in exactly one file** — `includes/execution.php`, twice (execute + syntax-check). Nowhere else, ever. Both are the product's whole point and both are guarded. A third one is a bug.
- **Snippet `code` is stored and output raw** — it is code, not user content. It is guarded instead: PHP is syntax-validated before activation, execution is wrapped so a fatal auto-deactivates the culprit, and only a user with the `manage_vibesnip` capability can create/activate. Do not "sanitize"/escape snippet code on the way in or out.
- **Never weaken the guard.** Every path that activates a PHP snippet must validate first (`VibeSnip_Guard::validate`). The shutdown handler + throwable handler must always be able to attribute a fatal to the running snippet — preserve the `mark_running`/`clear_running` (try/finally) contract.
- **Safe Mode is the recovery contract:** `define( 'VIBESNIP_SAFE_MODE', true )` in wp-config.php pauses all execution. Keep it working and keep it constant-only (never query-param triggerable — that would let a visitor bypass access-control snippets).
- **`manage_vibesnip` is the right to run arbitrary PHP as the web server.** Treat it as equivalent to `edit_plugins`, not as a content capability. Two consequences that must hold on every path that creates, updates or activates a **PHP** snippet:
  - **Multisite:** require `is_super_admin()`. A per-site administrator on a network must never be able to execute PHP — that is exactly why core disables the file editors and `unfiltered_html` there. Non-PHP types (CSS/JS/HTML/text) may stay at `manage_vibesnip`.
  - **Role grants:** the Settings → Permissions matrix must never offer `manage_vibesnip` to a role that lacks `edit_posts`+`manage_options`-level trust. Offering it to Subscriber is a privilege-escalation ladder.
- **Abilities never bypass the guard.** `class-vibesnip-abilities.php` exposes the safe loop over the WordPress Abilities API (`wp_register_ability` on `wp_abilities_api_init`, category on `wp_abilities_api_categories_init`). Preserve these invariants in every ability callback: `create-snippet` writes an **inactive** draft (never active); `activate-snippet` runs `VibeSnip_Guard::validate` first and `VibeSnip_Guard::health_check` after (auto-rollback on a front-end fatal); `update-snippet` refuses to overwrite a *live* PHP snippet with code that fails validation; `rollback-snippet` only ever deactivates and/or restores a prior revision. Every ability's `permission_callback` requires `manage_vibesnip`, and every write goes through the `VibeSnip_Snippets` repository so revisions + audit stay intact. It is additive — guard it with `function_exists( 'wp_register_ability' )` so installs without the Abilities API are unaffected.
- **AI never bypasses the guard.** `class-vibesnip-ai.php` `generate()` only *proposes*: AI Compose always saves generated snippets as **inactive** drafts (`source=ai`) that go through the same validate-before-activate path as anything else. Never add a path where AI output runs without a human activating it. It calls the **Anthropic Messages API over `wp_remote_post`** (no bundled Composer SDK — WP-idiomatic raw HTTP), uses structured outputs (`output_config.format` json_schema), sets a 60s timeout, and handles `stop_reason: "refusal"` + non-200s. **Before touching AI code, consult the `claude-api` skill** for current model IDs and the request/response contract — don't hand-edit model strings from memory.
- **Any new outbound HTTP request is a documentation change too.** Adding a host, or adding a field to what is sent to Anthropic, means editing the `== External services ==` section of `readme.txt` in the same commit. Not because a reviewer checks — nobody does any more — but because a plugin that quietly phones home is the kind of plugin this one exists not to be.

## Conventions

- No namespaces; class prefix `VibeSnip_`, files `class-vibesnip-*.php`. Text domain `vibesnip`.
- **Prefix everything**, not just classes: options (`vibesnip_*`), transients (`vibesnip_*`), constants (`VIBESNIP_*`), query args (`vs_*`/`vibesnip_*`), CSS classes (`vibesnip-`/`vs-`), JS globals (`VibeSnip`), capabilities (`manage_vibesnip`, `vibesnip_use_ai`).
- Editor uses WordPress' built-in CodeMirror via `wp_enqueue_code_editor()` — no bundled editor, no build step. (Monaco is a P2 replacement.)
- Tables via `VibeSnip_DB`; all writes go through the `VibeSnip_Snippets` repository so revisions + audit stay consistent.
- **A version bump touches three files or none**: the `Version:` header in `vibesnip.php`, the `VIBESNIP_VERSION` constant, and `Stable tag:` + a `== Changelog ==` entry in `readme.txt`. The updater compares the header against the GitHub release tag, so a mismatch means users are offered an update that installs the same version.

## Release gates (self-hosted)

VibeSnip ships as a zip on GitHub Releases and updates through `class-vibesnip-updater.php`. There is no review queue, which means there is nobody but you between a bad release and somebody's live site. Run these before tagging.

1. **The updater path works end to end.** Bump the version, build the zip, publish the release, and confirm an existing install is *offered* the update and *survives applying it*. This is the gate that replaced Plugin Check: a broken updater is now the only thing that can strand every user at once.
2. **The nonce → capability → sanitize → escape chain is unbroken on every handler.** Capability check first, `check_admin_referer`/`check_ajax_referer` second, sanitize on input, escape on output. Snippet `code` is the only sanctioned exception, and only where the existing `phpcs:ignore` comments explain it. This was never a directory rule — it is what keeps a plugin that runs arbitrary PHP from becoming the hole.
3. **Anything the plugin sends anywhere is written down** in the `== External services ==` section of `readme.txt`, including third-party scripts the admin pages load. Users still read that file; it is now the only disclosure they get.
4. **`sslverify => false` only on same-host loopbacks, and always with a comment saying so.** Anywhere else it is a security bug.
5. **Bundled third-party code keeps its source and its licence** in the repo. Still GPL-compatible, because the plugin is GPL.
6. **`.distignore` excludes what users should not receive** (`CLAUDE.md`, `ROADMAP.md`, `README.md`, `.codegraph/`, `tests/`) from the built zip. Build the zip and list its contents before publishing. The old internal planning docs are no longer a concern here — they live outside the repository now, in `antiGrav/vibesnip-vital/`.
7. **Uninstall deletes nothing unless the user opted in**, and every capability the plugin added is removed in `uninstall.php`.

`Tested up to:` and Plugin Check are no longer gates. Keeping `Tested up to:` roughly honest is still polite; failing Plugin Check on the two `eval()` calls is expected and fine.

## Verify

There is no automated test suite. That makes the manual path mandatory, not optional — and "I read the code and it looks right" is not verification. Two defects shipped in tagged releases precisely because the code was only ever linted, never executed: the sandbox token bug above, and the guard not attributing a fatal thrown inside a hook a snippet had registered (which is how nearly every snippet works). Both were invisible to `php -l` and obvious within a minute of running the plugin.

Where logic can be exercised without a full WordPress — permission matrices, token shapes, validators — a short PHP harness that stubs the handful of core functions involved is faster than a browser and worth writing.

**Step 1 — lint (necessary, never sufficient):**

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

**Step 2 — run it in a real WordPress.** There is no local WP in this repo, so stand one up. Either works; check `--help` for exact flags rather than guessing them:

```bash
npx @wp-playground/cli server --mount=.:/wordpress/wp-content/plugins/vibesnip   # no Docker needed
# or, if Docker is available:
npx @wordpress/env start
```

**Step 3 — exercise the safety loop.** These are the behaviours the whole plugin exists to provide; every one of them must be observed working, in a browser, before "done":

| # | Do this | Expect |
|---|---------|--------|
| 1 | Save a PHP snippet with a deliberate syntax error, click Save & Activate | Refuses to activate, shows the parse error and line |
| 2 | Save and activate a good PHP snippet | Goes active, front end unaffected |
| 3 | Activate a snippet that fatals at runtime | Auto-deactivates, error recorded, next page load is healthy |
| 4 | `define( 'VIBESNIP_SAFE_MODE', true )` | All snippets pause, admin notice appears |
| 5 | Import a WPCode/Code Snippets JSON export | Everything lands inactive |
| 6 | Ask AI on a saved snippet (with a key) | Report renders; snippet status unchanged |
| 7 | Deactivate + reactivate the plugin | No errors, no duplicate seed snippet |
| 8 | Open the editor | Heading bar shows only the language dot, filename, pill and **Ask AI** — no Test, no Preview |

**Step 4 — install the built zip over a previous version** in that test site and confirm the update applies cleanly (release gate 1). Plugin Check is no longer run; it only ever had the two expected `eval()` findings.

Report what you actually ran and what it printed. If a step was skipped, say which and why — do not report a green run you did not observe.
