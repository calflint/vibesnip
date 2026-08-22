# VibeSnip — Roadmap

This is the plan for where VibeSnip goes next, and an honest account of where it is today. If you are thinking about contributing, this is the file to read. `README.md` tells you what the plugin does; this one tells you what is missing and where the work is.

Last updated: 2026-08-22, against version 0.12.1.

---

## What VibeSnip is for

Every code snippets plugin stores code. That part is solved. The problem VibeSnip is built around is a different one:

**Running someone else's code on a live website is dangerous, and nothing in WordPress helps you with that.**

You paste a snippet from a forum post into `functions.php`, it has a typo, and the site is white. Or it works for a week and then fatals inside a hook when a specific page loads. Or an AI writes it for you, sounds confident, and you have no way to judge it. WordPress will happily run all of it and let the site fall over.

So VibeSnip is a snippets manager wrapped in a safety loop:

**describe → generate → validate → approve → activate → health-check → auto-rollback**

PHP is parsed before it is allowed to go active. A snippet that fatals is identified, switched off, and recorded, so the next page load is healthy. Activating a snippet triggers a real front-page check, and anything that breaks it is rolled back. `VIBESNIP_SAFE_MODE` in `wp-config.php` pauses everything if you are locked out.

The long-term goal follows from that: **an AI should be able to change a live WordPress site, and never be able to leave it broken.** Everything on this roadmap either makes that loop stronger, or makes it useful to more people.

---

## The project's terms

These are commitments, not marketing. They are here so you know what you are contributing to before you spend an evening on it.

- **Free, and staying free.** All of it. There is no paid tier, no Pro version, no locked features, no "upgrade to unlock". Earlier planning documents in this project's history described a paid cloud add-on. **That plan is dead.** It is not being built.
- **Funded by donations only.** There is a Ko-fi button at the bottom of the plugin's admin screens, it is optional, and it can be switched off permanently in Settings. That is the entire business model. If donations are zero, the plugin still ships.
- **Community-owned in direction.** GPL-2.0-or-later. If the maintainer disappears, everything needed to carry on is in this repository.
- **No telemetry. Ever.** VibeSnip does not phone home, does not count installs, does not report usage. The only outbound requests are ones you cause: an AI call with your own key, the update check, and the Ko-fi button on admin pages. Each is written up in `readme.txt` under `== External services ==`.
- **Self-hosted distribution.** VibeSnip was submitted to the wordpress.org plugin directory and rejected. That route is closed and is not being retried. You install and update it from the GitHub Releases zip, through the plugin's own update checker.

---

## Where it is today

Version 0.12.1 is complete and installable. Honestly assessed, the foundation is done and the surface is thin.

**Solid:**

- The safety loop, end to end. Syntax validation, fatal attribution (including fatals thrown later inside hooks a snippet registered — that case is the one most implementations miss), auto-deactivate, post-activation health check, Safe Mode.
- Snippet types PHP / CSS / JS / HTML / text, with locations, priority ordering, and a visual conditional-rule builder.
- Revisions on every save, and an audit log that records who did what — including when the actor was an AI.
- Optional AI, off until you switch it on. Anthropic, OpenAI, Google, and anything OpenAI-compatible (OpenRouter, Groq, Together, Azure, local Ollama or llama.cpp). Your key, stored encrypted on your own site.
- The WordPress Abilities API adapter, so an external agent can drive the same guarded loop the dashboard uses, without a way around the guard.
- A self-hosted update checker.

**Thin:**

- **Almost no automated tests.** Three standalone harnesses under `tests/` and nothing else. This is the biggest single weakness in the project.
- **13 bundled library snippets.** That is a demo, not a library.
- **Not translated.** The text domain is wired up throughout, but no `.pot` file is generated and there are no translations.
- **The editor is plain CodeMirror** with no preferences — no font size, no tab width, no theme choice.
- **No way to try a snippet before committing to it.** There used to be a Test button; see "Things that were tried and removed" below.

---

## Near term — the next few releases

These are small enough to finish, and each one stands alone. If you are looking for somewhere to start, start here.

### 1. A real test suite

**Why it matters more than any feature.** Two defects shipped in tagged releases purely because the code was read and linted but never actually run. A third — `update()` silently blanking every field a caller did not pass, which switched *active snippets off* — survived for months and was found by accident. All three were obvious within a minute of executing the code.

The existing harnesses in `tests/` show the pattern that works here: plain PHP, stub the handful of WordPress functions involved, assert real behaviour, no framework, no bootstrap. `tests/harness-partial-update.php` is a good model — 18 assertions, and it fails 11 of them against the unfixed code.

What is wanted, roughly in order: the guard's validate/fatal-attribution path, the conditional-rule evaluator, the importers against real WPCode and Code Snippets exports, and the repository's write paths. A GitHub Actions workflow that runs them all on push.

### 2. Grow the bundled library

13 snippets is not enough to be useful. The library lives in `includes/class-vibesnip-library.php` and adding one is a small, self-contained pull request — a good first contribution.

What makes a good addition: it solves a problem people actually have, it is correct on a current WordPress, it does not depend on a particular theme or plugin unless it says so, and it does not need another snippet to work. Quality matters far more than count here. Eighty snippets that are right beat eight hundred that are nearly right, because every one of them runs on somebody's live site.

### 3. Internationalisation

Generate a `.pot` file, get the strings audited for anything unfriendly to translators (concatenation, missing context), and open the door to translations. Mechanical, valuable, and a genuinely good first issue.

### 4. Editor preferences

Font size, tab width, and a light/dark editor theme, stored per user. Small, visible, and one of the most common requests for any code editor.

### 5. Accessibility and keyboard use

The admin screens have never had an accessibility pass. Focus order, screen-reader labelling on the permissions matrix and the conditional builder, and keyboard access to everything that currently needs a mouse.

---

## Medium term

### 6. A community snippet library

Today's library is hardcoded into the plugin, so it only grows when a release ships. The intent is a proper library: a curated, growing collection, fetched from a source we publish, searchable from inside the plugin.

The design work for this is already done and the important decisions are settled:

- **Fetch the whole metadata index; search it locally.** Titles, descriptions, tags, hashes — no code. Code is fetched only when someone installs one snippet. This means nothing about your site ever leaves it, the library keeps working offline, and search is instant.
- **Vetting changes how a snippet is presented, never how it runs.** A library snippet lands inactive and goes through the identical guard as anything you typed yourself. Upstream review cannot know your theme, your plugins, or your PHP version. Only the guard knows if it is safe *here*.
- **Never auto-update an installed snippet.** That is silently changing running code on a live site. Show "update available", show a diff, let the user apply it as a new revision, then revalidate and health-check.
- **Never a remote kill switch.** The index can flag a snippet as deprecated or recalled and say why, loudly. It cannot reach into a site and switch anything off. The day you need that power is the day it breaks somebody's checkout at 2am.
- **Signed and hashed.** This is a code distribution channel. A per-snippet SHA-256 in the index, the index itself signed, verified before anything is displayed.

Now that the project is community-run rather than commercial, the open question is **who curates it and how submissions get reviewed.** That is a governance problem more than a coding one, and it is worth solving in the open. If this part interests you, it is the most impactful thing on this list.

### 7. Reuse before generating

Before AI Compose writes anything, check whether the library already answers the request. Keyword and tag prefilter locally, then pass the top handful of candidates — titles and descriptions only, no code — into the call, letting the model choose between reusing an existing snippet and writing a new one.

Better for the user (a reviewed snippet beats a fresh one) and cheaper (no generation). It depends on item 6.

### 8. Surface AI provenance in the interface

Snippets already record `source=ai`. Showing that plainly in the list and on the snippet itself is cheap, honest, and increasingly expected of software that generates code.

---

## Longer term

### 9. Trying a snippet without committing to it

The genuinely hard, genuinely valuable one. Right now the loop goes straight from "validated" to "active on the live site", and the health check is what catches the mistake — *after* it has run once.

Any real answer must be asynchronous, or must execute somewhere other than the site itself. See the removals section below for why; this is not a matter of writing it more carefully.

### 10. Deeper agent integration

The Abilities API adapter is in place. The next step is making VibeSnip a first-class target for agents generally — MCP, and whatever the WordPress ecosystem settles on — while keeping the rule that has held from the start: **an agent gets exactly the same guarded path a human gets, and no shortcut around it.**

---

## Explicit non-goals

Saying no in advance saves everyone's time. None of the following will be merged:

- **A paid tier, a Pro version, or any locked feature.** Not planned, not accepted.
- **Telemetry, analytics, or install counting**, however anonymised.
- **A third `eval()`.** There are exactly two, both in `includes/execution.php`, both documented, side by side. One executes a snippet; the other is the syntax check and executes nothing (the code is wrapped in `if ( false ) { … }`, so PHP parses every line while not one statement can run). Two is the ceiling.
- **Any path where generated, imported, or downloaded code runs without a human activating it.** This is the whole point of the plugin.
- **Auto-updating installed snippets**, or any remote control over a user's site.
- **Resubmitting to wordpress.org.**
- **A bundled editor or a build step.** VibeSnip is a plain PHP plugin. Clone it into `wp-content/plugins/` and it runs. Keeping it that way keeps the barrier to contributing low.

---

## Things that were tried and removed

Worth knowing, so nobody rebuilds them the same way.

**The Test button (removed in 0.9.0).** It ran a candidate snippet in an isolated request by having the site make an HTTP request *to itself*. On any host serving one request at a time that self-deadlocks — the request waits for a worker that is already busy waiting — and many shared hosts block loopback requests outright. In practice it returned a 25-second timeout far more often than a result. A dry-run has to run somewhere else, or be fully asynchronous with polling. Do not reintroduce a synchronous loopback.

**The Preview pane (removed in 0.9.0).** It worked, but it could only ever show a placeholder for PHP — which is most snippets — and a synthetic sample DOM rather than the real site for CSS and HTML. It looked like a feature and taught nothing.

**A paid cloud add-on (planned, never built, now cancelled).** Managed AI, private snippet packs, cross-site sync, sold through a merchant of record. It was fully planned and then dropped when the project committed to being free and donation-funded. If any old comment or document refers to "the Cloud add-on", that is what it meant, and it is not happening.

---

## How to contribute

Issues and pull requests are welcome, including small ones. Fixing a typo in a message is a real contribution.

**Getting set up.** There is no build step and no dependency install. Clone the repository into `wp-content/plugins/vibesnip` and activate it. That is the whole setup.

**Before opening a pull request:**

1. **Lint.** `find . -name '*.php' -print0 | xargs -0 -n1 php -l`
2. **Run it.** Actually execute the change in a real WordPress install. Reading the code and concluding it looks right is not verification — that specific failure has shipped three separate bugs in this project. If you have no local WordPress, `npx @wp-playground/cli server --mount=.:/wordpress/wp-content/plugins/vibesnip` needs no Docker.
3. **If the logic can be tested without WordPress, add a harness** next to the existing ones in `tests/`. A test that fails against the old code and passes against yours is the most convincing thing you can put in a pull request.

**Conventions.** No namespaces. Class prefix `VibeSnip_`, files named `class-vibesnip-*.php`, text domain `vibesnip`. Prefix everything else too — options, transients, constants, CSS classes. Prefer WordPress core idiom (`wp_parse_args`, `wp_remote_post`, `$wpdb->update`) over clever PHP; a reviewer skims for familiar shapes.

**Comment the *why*, not the *what*.** `// increment the counter` is noise. `// Buffered because a snippet that echoes here breaks the redirect header` is the reason the code exists.

**One thing per pull request.** Easier to review, easier to revert, and far more likely to get merged quickly.

`CLAUDE.md` in this repository holds the full working standards — the hard rules, the security invariants, and the traps that have already cost somebody a day. It is written for AI coding assistants but reads perfectly well as a contributor guide, and it is worth skimming before a first substantial change.

---

## Where to start

If you want to help and have no strong preference:

| If you have… | Try |
|---|---|
| An hour, and PHP | Add a snippet to the bundled library (item 2) |
| An hour, and no PHP | Test the plugin on a real site and file what breaks |
| An evening | Write a harness for an untested part of the guard (item 1) |
| Some design sense | The accessibility pass (item 5) |
| Another language | Internationalisation (item 3) |
| Bigger ambitions | The community library, especially how it gets curated (item 6) |

Open an issue before starting anything large, so two people do not build the same thing twice.
