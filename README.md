# VibeSnip

**AI-native code snippets for WordPress.** Add PHP, CSS, JavaScript, HTML and text snippets safely — validated before they run, and auto-deactivated the instant they fatal.

Every snippets plugin stores code. The thing VibeSnip is actually built around is the loop that makes running it survivable: **describe → generate → validate → approve → activate → health-check → auto-rollback.** Nothing runs until a human activates it, and nothing that breaks the site is allowed to stay running.

- **Free and GPL-2.0-or-later.** No paywalled features in this repository.
- **No build step, no bundled dependencies.** It is a plain WordPress plugin — clone it into `wp-content/plugins/` and activate.
- **Bring your own AI key, or don't.** AI is off until you turn it on, and the plugin contacts nobody until you do.

## Safety model

This is the part worth reading before you trust any plugin that runs code.

- **Syntax validation before activation.** PHP is parsed before it is ever allowed to go active, so a typo cannot take the site down. The check executes nothing — the code is wrapped in `if ( false ) { … }`, so PHP parses every line while not one statement inside can run.
- **Auto-deactivate on fatal.** A shutdown handler plus a catchable-throwable handler identify the culprit snippet, switch it off, and record the message and line. This covers the deferred case too: callbacks a snippet registers via `add_action`/`add_filter` are wrapped as they are registered, so a fatal that only happens later, inside a hook, is still attributed to the snippet that hooked it.
- **Health-check with auto-rollback.** On activation, a non-blocking front-page request lets the guard vet a live PHP snippet immediately and roll back anything that fatals on the front end.
- **Safe Mode.** `define( 'VIBESNIP_SAFE_MODE', true );` in `wp-config.php` pauses all snippet execution. It is constant-only by design — never triggerable from a query parameter, which would let a visitor bypass an access-control snippet.
- **Revisions and an audit log.** A snapshot on every save, and a record of every create, edit, activate, deactivate and auto-deactivate, with the actor recorded — including when the actor was an AI.
- **`manage_vibesnip` is treated as the right to run arbitrary PHP**, equivalent to `edit_plugins` rather than a content capability. On multisite, PHP snippets additionally require a super admin.

## Features

- **Snippet types:** PHP, CSS, JavaScript, HTML, Text.
- **Locations:** run everywhere · admin only · front-end only · site header · site body · site footer · `[vibesnip id="N"]` shortcode. Priority-ordered.
- **Conditional logic** — a visual rule builder: run a snippet only when rules match (user status, role, page type, post type, URL path, device), combined with ALL/ANY.
- **Ready-made library** — a curated set of vetted snippets, added with one click as an inactive snippet you review before activating.
- **Importers** — WPCode and Code Snippets JSON exports map onto VibeSnip snippets, always imported inactive.
- **Editor** — WordPress' own CodeMirror via `wp_enqueue_code_editor()`, with type→mode switching and ⌘S / Ctrl+S to save. No bundled editor, no build step.
- **Permissions** — a role × capability matrix for `manage_vibesnip` and AI use. Administrators always keep `manage_vibesnip`, so you cannot lock yourself out.

## AI features (optional, off by default)

VibeSnip installs as an ordinary snippets plugin. There is no AI menu, no key field, and no contact with any external service until you deliberately switch AI on — a step that first sets out what is sent where, who pays for it, and what can go wrong.

- **Ask AI** — paste a snippet you found online and get a plain-English report: safe / caution / not-safe, what it does, what it changes, risks, and what to check after activating. Advisory only; the PHP syntax check remains the sole gate on activation.
- **Compose** — describe an outcome in plain English and agree on a plan *before* any code exists. VibeSnip restates what it understood and what it had to assume, with no code; you approve or rephrase. Only then does it write the snippet. Nothing touches the database until you click Save, and what is saved is an **inactive draft** with provenance recorded, which then goes through the same validate-before-activate path as hand-typed code.
- **Bring your own key** — Anthropic, OpenAI or Google, each with its own key and model, stored on your own site. Only the selected provider is ever contacted. Because the OpenAI adapter speaks chat-completions, the optional endpoint override also covers OpenRouter, Groq, Together, Azure OpenAI and local Ollama/llama.cpp.
- **The model list comes from your provider**, not from a hardcoded list that goes stale the moment a vendor renames something.

Every external host, and exactly what is sent to it, is documented under `== External services ==` in `readme.txt`.

## Agent-native (WordPress Abilities API)

VibeSnip registers its whole safe loop as standard WordPress [abilities](https://github.com/WordPress/abilities-api), so any AI agent, MCP client or plugin that speaks the Abilities API can drive the same guarded flow the dashboard uses: `get-site-context`, `list-snippets`, `get-snippet`, `create-snippet`, `update-snippet`, `activate-snippet`, `deactivate-snippet`, `rollback-snippet`.

**Nothing bypasses the guard.** `create-snippet` always writes an inactive draft; `activate-snippet` syntax-validates first and health-checks after; `update-snippet` refuses to overwrite a live snippet with broken PHP; `rollback-snippet` only ever deactivates or restores a known-good revision. Every ability requires `manage_vibesnip` and is recorded in the audit log with agent provenance.

It is purely additive — on installs without the Abilities API the registration hooks simply never fire.

## Architecture

```
vibesnip.php                     bootstrap + VibeSnip orchestrator
includes/
  execution.php                  GLOBAL-namespace eval + PHP syntax validator
  class-vibesnip-db.php          table names + dbDelta schema
  class-vibesnip-activator.php   activation: tables, caps, seed
  class-vibesnip-snippet.php     model + type/location metadata
  class-vibesnip-snippets.php    repository: CRUD, revisions, audit log
  class-vibesnip-guard.php       validation, Safe Mode, auto-deactivate on fatal
  class-vibesnip-executor.php    runs PHP + injects CSS/JS/HTML + shortcode
  class-vibesnip-conditions.php  the conditional-logic rule engine
  class-vibesnip-importer.php    WPCode / Code Snippets import
  class-vibesnip-library.php     the bundled ready-made snippets
  class-vibesnip-context.php     safe site snapshot for the AI prompt
  class-vibesnip-keys.php        API keys encrypted at rest
  class-vibesnip-ai.php          prompts, schemas, plan/generate/review, parsing
  class-vibesnip-provider.php    vendor adapters: Anthropic, OpenAI, Google
  class-vibesnip-abilities.php   WordPress Abilities API adapter
  class-vibesnip-donate.php      the optional donation footer
admin/
  class-vibesnip-admin.php       menu, assets, save/row-action handling, notices
  class-vibesnip-compose.php     the Compose conversation + its AJAX handlers
  class-vibesnip-list-table.php  the snippet manager
  class-vibesnip-brand.php       the plugin's mark
  views/edit.php                 the editor
  views/review.php               the Ask AI report
uninstall.php                    opt-in data removal
```

### Data model

- `wp_vibesnip_snippets` — the snippets (code, type, location, priority, status, conditions, provenance, last error).
- `wp_vibesnip_revisions` — a snapshot per save.
- `wp_vibesnip_runs` — the audit log (action, actor user/AI, result).

### Why PHP runs in the global namespace

Snippet code has to resolve `WP_Query`, `add_action`, constants and so on exactly as theme code does. An `eval()` inside a namespaced class would resolve class names to that namespace instead. `includes/execution.php` therefore has **no namespace**, so evaluated snippets behave like `functions.php`. Do not move it under one.

### About the two `eval()` calls

There are exactly two in the entire plugin, both in `includes/execution.php`, both deliberate and left plain and unobfuscated. One runs an admin-authored snippet — that is what the plugin is *for*, and it does for a database-stored snippet what WordPress does for a theme's `functions.php`. The other is the syntax check, which executes nothing. The file opens with a full note on what guards each. This is the same mechanism the established snippets plugins use.

## Development

No build step, no dependencies.

```bash
# Lint all PHP (requires php on PATH)
find . -name '*.php' -print0 | xargs -0 -n1 php -l

# Local WordPress: symlink or copy this folder into wp-content/plugins/vibesnip
```

Linting is not verification. Before calling any change done, run the plugin in a real WordPress and exercise the safety loop: save a PHP snippet with a deliberate syntax error (must refuse to activate), activate a good one, activate one that fatals at runtime (must auto-deactivate and recover), and confirm Safe Mode pauses everything.

**Recovery while developing:** if a snippet locks you out, add `define( 'VIBESNIP_SAFE_MODE', true );` to `wp-config.php`.

### Adding an AI provider

One entry in `VibeSnip_Provider::providers()` and one class extending `VibeSnip_Provider`. The adapter owns only the wire format — auth headers, the request URL, where the system prompt goes, how the reply is constrained to a JSON schema, and where the JSON sits in the response. Everything downstream (`parse_plan`, `parse_response`, `parse_review` and all validation) is provider-agnostic and must stay that way.

Third parties can register one without patching core, via the `vibesnip_ai_providers` filter.

**Any new host is a documentation change in the same commit** — add it to `== External services ==` in `readme.txt`, with what is sent, when, and links to that vendor's terms and privacy policy.

### Where things live

| You want to… | Go to |
|---|---|
| Change what the AI is told | the `*_system_prompt()` methods in `class-vibesnip-ai.php` |
| Change what a reply must contain | the `*_schema()` methods alongside them |
| Add or change a vendor | `class-vibesnip-provider.php` |
| Change the Compose conversation | `admin/class-vibesnip-compose.php` + `admin/js/compose.js` |
| Add a settings field | `render_settings_general()` + `handle_settings()` in `class-vibesnip-admin.php` |

## Contributing

Issues and pull requests are welcome. Two things that will not be merged, so it is fairer to say them up front:

1. **A third `eval()`.** There are two, they are documented, and that is the ceiling.
2. **Any path that lets generated or imported code run without a human activating it.**

Conventions: no namespaces, class prefix `VibeSnip_`, files named `class-vibesnip-*.php`, text domain `vibesnip`, and WordPress core idiom (`wp_parse_args`, `wp_remote_post`, `$wpdb->update`) over clever PHP.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
