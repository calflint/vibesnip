=== VibeSnip ===
Contributors: calflint2030
Tags: code snippets, php, css, custom code, functions.php
Donate link: https://ko-fi.com/N4S525E796
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.0
Stable tag: 0.12.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-native code snippets for WordPress. Add PHP, CSS, JS, HTML and text safely — validated before they run, auto-deactivated if they ever fatal.

== Description ==

VibeSnip is a code snippets manager for WordPress. Add custom PHP, CSS, JavaScript, HTML and text without editing your theme's functions.php file. Snippets live in the database, so they survive theme switches and updates, and you toggle them on and off like a light switch.

The point of VibeSnip is safety. An AI can help you write and test code, with you approving every change and able to reverse it — which only works if the site can never stay broken. Every feature below serves that.

Like every code snippets plugin, VibeSnip runs the PHP you give it. How that is guarded, and the two `eval()` calls Plugin Check reports, are set out in full under "Security model" and "Notes for reviewers" below — nothing about it is hidden.

**Safety**

* **Syntax validation** — a PHP snippet is parsed before it is allowed to go active, so a typo can't take your site down.
* **Auto-deactivate on fatal** — if a live snippet ever throws a fatal error, VibeSnip catches it, switches that snippet off, and records the message and line, so the very next request loads a healthy site.
* **Health-check with automatic rollback** — activating a PHP snippet loads the front page in the background; if it fatals there, VibeSnip rolls it back and tells you, instead of a visitor finding out.
* **Safe Mode** — a wp-config.php constant that pauses all snippets for emergency recovery.

**Snippets**

* PHP, CSS, JavaScript, HTML and text snippets.
* Locations: run everywhere / admin only / front-end only (PHP), site-wide header / body / footer (CSS, JS, HTML), and shortcode.
* Priority ordering so you control what runs first.
* **Conditional logic** — a visual rule builder to run a snippet only when rules match (user status, role, page type, post type, URL path, device), combined with ALL or ANY.
* **Ready-made library** and **importers** for WPCode and Code Snippets JSON exports (everything imports inactive for review).
* **Revisions** — every save is snapshotted. **Audit log** — every create, edit, activation and auto-deactivation is recorded.

**AI (optional)**

* **Ask AI** — found a snippet online and cannot read code? Paste it, click Ask AI, and get a plain-English report: what it does, what it changes on your site, specific risks, and a safe / caution / not-safe assessment. It only ever reports — it cannot switch anything on, and it never replaces the syntax check.
* **AI Compose** — describe an outcome in plain English. VibeSnip first tells you what it understood and what it intends to write, so you can approve it or rephrase before a line of code exists. It then writes the snippet and reviews its own work, and **nothing is added to your snippets until you press Save**. What you save arrives inactive — nothing runs until you switch it on.
* **AI is switched off when you install VibeSnip, and stays off until you turn it on.** Until then there is no key field, no AI menu item, no AI button, and the plugin contacts nobody — it is an ordinary snippets manager. Switching it on is a deliberate step under Settings → General that sets out plainly what AI does, what is sent where, who pays for it and what can go wrong, and asks you to accept that before it will save.
* Once on, bring a key from **Anthropic (Claude)**, **OpenAI**, **Google Gemini**, or any **OpenAI-compatible** service (OpenRouter, Groq, Together, Azure OpenAI, or a model running locally under Ollama or llama.cpp). The model dropdown is filled in from the provider's own list of what your key can use, so you pick a real model rather than one baked into a release months ago. Covered under "External services" below.
* Your API key is **encrypted before it is stored**, using a secret from your `wp-config.php` that never goes into the database — so a leaked database backup does not hand over your key. It is never displayed back to you after saving.
* **WordPress Abilities API adapter** — VibeSnip registers its whole safe loop as standard WordPress abilities, so any AI agent that speaks the Abilities API can drive the exact same guarded flow. It works fully without the Abilities API present.

**On the roadmap**

* Editor preferences (font size, tab width, theme) and an optional Monaco editor. Today VibeSnip uses WordPress' built-in CodeMirror, so syntax highlighting works with zero build step.

== Installation ==

1. Upload the `VibeSnip` folder to `/wp-content/plugins/`, or install the zip via Plugins → Add New → Upload.
2. Activate VibeSnip through the Plugins menu.
3. Open the **VibeSnip** menu and add your first snippet. It stays inactive until you switch it on.

== Frequently Asked Questions ==

= Will I lose my snippets if I change my theme? =
No. Snippets are stored in the database, independent of your theme and unaffected by WordPress updates.

= A snippet broke my site. How do I recover? =
VibeSnip auto-deactivates any snippet that throws a fatal error, so normally the site recovers on its own. If you are ever locked out, add this line to `wp-config.php` to pause all snippets:

`define( 'VIBESNIP_SAFE_MODE', true );`

Fix or delete the offending snippet, then remove the line.

= Does VibeSnip work on multisite? =
Yes, with one deliberate restriction: only a Super Admin can create, edit or activate **PHP** snippets. A PHP snippet runs as site code, and WordPress already withholds that level of power from per-site administrators on a network — it is why the plugin and theme file editors are disabled there. Administrators of individual sites can still use CSS, JavaScript, HTML and text snippets normally, and any PHP snippet that is already active keeps running.

= What code can I add? =
Anything you would otherwise put in functions.php or a site-specific plugin: PHP, plus CSS, JavaScript, HTML and text.

= Why does Plugin Check report two eval() errors? =
Because VibeSnip runs the PHP you write, and in PHP there is no other way to do that without writing your code to a file on disk first — which is worse, not better. Both calls are in one file, `includes/execution.php`, side by side and unobfuscated, and there are none anywhere else in the plugin.

The first runs an active snippet — the same job WordPress does for your theme's `functions.php`, just from the database instead of a file. The second is the safety check, and it executes nothing at all: your code is wrapped in `if ( false ) { ... }`, so PHP reads every line to check the spelling but cannot carry out a single instruction. That is how a missing bracket is caught and refused before it ever reaches your visitors.

So it is one mechanism, reported twice, and the second one exists purely to make the first one safe. See "Security model" and "Notes for reviewers" below.

= Does VibeSnip send my data anywhere? =
Only if you use one of the optional AI features with your own API key. See "External services" below. Everything else runs entirely on your own site.

= Is the AI allowed to change my site on its own? =
No. Ask AI only reports; AI Compose only proposes, and its output is saved as an inactive draft. Nothing runs until you activate it, and activation always goes through the same syntax check and health-check as code you wrote yourself.

== Security model ==

A snippets plugin runs code on your site. That is the job, and it cannot be made risk-free — so VibeSnip is built so that the code can only ever come from someone you already trust, and so that a mistake cannot leave the site broken. Six things stand between a snippet and your visitors:

1. **Permission.** Creating, editing and activating snippets requires the `manage_vibesnip` capability, which VibeSnip treats as equivalent to WordPress's `edit_plugins` — the right to run arbitrary code as the web server. The Settings → Permissions matrix will not offer it to any role below administrator-level trust. On a multisite network, PHP snippets additionally require a Super Admin, for the same reason WordPress disables the file editors there.
2. **A nonce on every change.** Every create, edit, activation and deactivation is verified with `check_admin_referer` or `check_ajax_referer`.
3. **Syntax validation before activation.** A PHP snippet is fully parsed before it is allowed to go live. If it does not parse, activation is refused and you are shown the error and the line. This check runs the code zero times.
4. **Inactive by default.** Nothing runs until a human switches it on. That includes anything the optional AI wrote, and anything brought in by the WPCode / Code Snippets importers or the built-in library — all of it lands inactive, for review.
5. **Health-check, rollback and auto-deactivate.** Activating a PHP snippet loads the front page in the background; a fatal there rolls the snippet straight back. A live snippet that fatals later is switched off automatically by the shutdown handler, with the message and line recorded, so the next request serves a healthy site.
6. **Safe Mode.** `define( 'VIBESNIP_SAFE_MODE', true );` in `wp-config.php` pauses all snippet execution for emergency recovery. It is constant-only by design and can never be triggered by a URL.

Every save is snapshotted as a revision, and every create, edit, activation and auto-deactivation is written to an audit log, including changes made by an AI agent through the Abilities API.

== Notes for reviewers ==

VibeSnip reports **two Plugin Check errors**, both `eval()`, both in `includes/execution.php`, and nothing else. They are deliberate and disclosed rather than worked around, and the code is left plain — not renamed, not variable-indirected, not string-assembled — so that it reads exactly as what it is. There is a matching explanation in a comment block at the top of that file.

This is the same mechanism WPCode and Code Snippets use, both of which are listed in the directory. It is what a code snippets plugin is.

* `vibesnip_execute_php()` — runs an active, admin-authored snippet. Functionally identical to what WordPress does with a theme's `functions.php`; the only difference is that the code is stored in the database instead of a file. Removing this removes the plugin.
* `vibesnip_validate_php()` — the pre-activation syntax check. It executes nothing: the snippet is wrapped in `if ( false ) { ... }`, which forces PHP to parse every line and raise a `ParseError` on a typo while guaranteeing no statement inside can run. Top-level `namespace` declarations are rejected first so the wrapper cannot be escaped.

Two alternatives were considered and rejected. Writing snippets to `.php` files and `include`-ing them turns a memory-only operation into a persistent executable artifact on disk and requires filesystem write access — a larger attack surface, not a smaller one. Shelling out to `php -l` uses `exec()`, which is itself a forbidden function and is disabled on most shared hosting.

The guard chain around both calls is set out under "Security model" above. Also relevant: `includes/execution.php` is deliberately left in the global namespace so evaluated snippets resolve `WP_Query`, `add_action` and constants exactly as theme code does; and snippet `code` is intentionally stored and output unescaped, because it is code rather than user content — it is guarded by validation and capability checks instead. Both decisions are documented in comments at their sites.

Every outbound HTTP request the plugin can make is listed under "External services" below. All of it is optional, off on a new install, and requires the user's own API key.

== External services ==

VibeSnip's core — snippet storage, execution, validation, the health-check, revisions, the audit log and Safe Mode — runs entirely on your own server and contacts no external service.

**Ko-fi (the Donate button).** VibeSnip's admin screens end with an optional donation footer, and the button in it is Ko-fi's own widget, loaded from `storage.ko-fi.com`. Your browser fetches that script when a VibeSnip admin page loads, which tells Ko-fi your IP address and the usual details any web request carries. Nothing about your site, your snippets or your visitors is sent, and it never runs on the front end — only on VibeSnip's own admin pages, and only for a user who can manage snippets. Switch the footer off under Settings → General and the script is not loaded at all. Ko-fi's terms: https://more.ko-fi.com/tos — privacy policy: https://more.ko-fi.com/privacy

VibeSnip has two optional AI features, **both switched off on a new install**: **AI Compose**, which turns a plain-English request into a snippet proposal, and **Ask AI**, which reviews a snippet you have written or pasted and reports what it does and how safe it is.

Three separate things must happen before VibeSnip contacts anyone: you must switch AI features on under Settings → General and accept the stated risks, you must add your own API key for a provider you choose, and you must click the button. Until all three, no request leaves your site. Nothing either feature returns is ever activated automatically.

You choose **one** provider on the Settings page. **Only the provider you have selected is ever contacted.** VibeSnip never contacts the others, never contacts more than one host for a request, and never sends anything anywhere until you supply a key and click a button. Your API keys are stored only on your own site, are sent only to that provider to authenticate your own requests, and are never sent to the plugin author.

= What is sent =

When you click Generate on AI Compose, the following is sent to the selected provider:

* The text of the request you type, and — after you approve the plan it proposes — that plan.
* A small, non-sensitive snapshot of your site so the generated code fits it: your WordPress and PHP version, site locale, whether the site is multisite, your active theme's name, your public post-type slugs, and which of a short list of well-known plugins (WooCommerce, Easy Digital Downloads, Elementor, Advanced Custom Fields, Yoast SEO, Contact Form 7) are active.

When you click Ask AI, or when AI Compose reviews a snippet it has just written, the following is sent to the selected provider:

* The code of the snippet being reviewed, and its title, type, location and whether it is currently active.
* The same small site snapshot described above.

When you click **Test connection**, a single two-word request is sent to the selected provider to check your key works. No site data is included.

When you click **Refresh list** next to the model dropdown, VibeSnip asks the selected provider which models your key is allowed to use, so the dropdown shows real, current models instead of a list baked into the plugin. Only your key is sent; no site data and no snippet code. The answer is cached on your site for a week.

No posts, user data, passwords, or database contents are sent by any of these features.

= Anthropic (Claude) =

Host: api.anthropic.com — used only when Anthropic is the selected provider.

* Terms: https://www.anthropic.com/legal/commercial-terms
* Privacy policy: https://www.anthropic.com/legal/privacy

= OpenAI =

Host: api.openai.com — used only when OpenAI is the selected provider.

* Terms: https://openai.com/policies/terms-of-use/
* Privacy policy: https://openai.com/policies/privacy-policy/

= Google (Gemini) =

Host: generativelanguage.googleapis.com — used only when Google is the selected provider.

* Terms: https://ai.google.dev/gemini-api/terms
* Privacy policy: https://policies.google.com/privacy

= OpenAI-compatible =

Host: **whatever address you enter yourself.** This provider has no default and contacts nothing until you supply one. It exists for services that speak the OpenAI chat-completions format — OpenRouter, Groq, Together, Azure OpenAI, vLLM, or a model running locally under Ollama or llama.cpp.

If you select it, **all of the data described above is sent to the address you entered**, governed by that host's terms and privacy policy. VibeSnip cannot know in advance which host that will be, so you are responsible for reviewing the terms of whatever service you point it at. If you point it at software running on your own machine or network, nothing leaves it.

= Custom endpoint =

Anthropic, OpenAI and Google each have an optional **Advanced: custom endpoint** field on the Settings page. It is blank by default, and while it is blank VibeSnip contacts only the provider host listed above.

If you enter an address there, **all of the data described above is sent to that host instead of to the provider**, and it is governed by that host's terms and privacy policy rather than the provider's. VibeSnip cannot know in advance which host you will enter, so you are responsible for reviewing the terms of whatever service you point it at.

== Changelog ==

= 0.12.1 =
* Changed: the donation footer is now a **Ko-fi** button rather than a PayPal link. It is Ko-fi's own button, so a VibeSnip admin page now loads a small script from Ko-fi — written up under "External services" below. It is still optional, still admin-only, still never on the front end, and switching the footer off under Settings → General stops the script loading at all.
* Fixed: **saving a snippet could wipe the rest of it.** When a save sent only part of a snippet — as the Abilities API and some edit paths do — every field it did not send was written back empty. The name, description, tags and notes were cleared, the position and priority reset, and worst of all an **active snippet quietly switched itself off**. A save now changes only what you actually changed and leaves everything else exactly as it was.

= 0.12.0 =
* New: **AI is off until you turn it on.** VibeSnip now installs as an ordinary snippets plugin — no AI menu, no AI buttons, no key field, and no contact with anyone. Switching AI on is a deliberate step under Settings → General that first sets out what it does, what is sent where, who pays for it and what can go wrong, and asks you to accept that. Sites already using AI keep it on and are not asked again.
* New: **the model dropdown is filled in by your provider, not by us.** Click "Refresh list" and VibeSnip asks Anthropic, OpenAI or Google which models your key can actually use, and offers exactly those. A list shipped inside a release goes stale the moment a vendor renames something, which is how you end up picking a model your account has never had and reading an error you cannot act on. Models are shown by their real names, not as "cheaper and faster".
* Security: **your API key is now encrypted before it is stored**, with a secret from your `wp-config.php` that is never in the database — so a leaked backup or database dump does not give away your key. Keys saved by earlier versions are encrypted automatically on update.
* Security: the API key box is no longer a browser password field, so Chrome, Safari and password managers stop offering to remember a credential you never asked them to keep. It is still masked as you type, and a saved key is never rendered back into the page.
* Security: **a PHP snippet can no longer be stored against a header, body or footer position.** Those positions exist for CSS, JavaScript, HTML and text, which are printed into the page as written. A PHP snippet parked in one of them was never run at all — its source was printed into the page instead, on every visit, for everyone. The editor never offered that combination, but it could still be reached by a request made outside the editor or through the Abilities API, so the pairing is now enforced where snippets are saved. Any existing snippet in that state stops being printed as soon as you update; open it and give it a position that suits its language.
* Security: **uninstalling VibeSnip now always removes the permissions it added.** The "manage snippets" permission is the right to run code on your site, and it was only being taken back off your roles if you had also ticked "delete all data on uninstall" — so on an ordinary uninstall it stayed behind after the plugin was gone. Permissions are now removed every time. This does not touch your snippets: they, and your settings, are still kept unless you explicitly ask for them to be deleted.
* Fixed: **a snippet could be saved with no name**, then sat in the list as a blank row nobody could identify. The name is now required, and if a save is ever refused, everything you had typed is put straight back in the editor rather than lost.
* Fixed: dates on revisions and the activity log now use **your site's own date and time format**, and each screen says which timezone it is showing. If those times look hours out, it is because the site timezone is still on the WordPress default of UTC — set it under Settings → General and everything lines up.
* New: VibeSnip has its own mark. It replaces the generic code dashicon in the admin menu and now heads every VibeSnip screen, so the plugin's pages are recognisable at a glance in a crowded admin.
* Changed: the donation footer is one block again — the "Hide this permanently" link is gone and the sentence now says where to switch it off. The **What's New** page had a different donation link entirely, pointing at GitHub Sponsors; it now shows the same footer as every other screen.

= 0.11.0 =
* Fixed: **Test connection reported the wrong provider.** It tested whatever was last saved, so choosing OpenAI, pasting an OpenAI key and pressing the button answered "Connected to Anthropic". It now tests the provider, model, key and address shown on screen — including a key you have typed but not saved yet — and names the provider that actually answered. It still saves nothing.
* New: **OpenAI-compatible** is now its own provider, alongside OpenAI itself. Pick it and you supply the address and the model name yourself, which is what OpenRouter, Groq, Together, Azure OpenAI, vLLM and a local Ollama or llama.cpp need. Previously you could point OpenAI's custom endpoint at a gateway, but you were still choosing from a list of OpenAI model ids that gateway had never heard of.
* Improved: **AI Compose and Ask AI now show that they are working.** Both used to sit on a still screen for the tens of seconds a request takes, which is indistinguishable from one that has failed. AI Compose adds a card to the conversation with a spinner, what it is doing and a running clock; Ask AI spins in its own button.
* Fixed: **AI Compose was unreadable on a computer set to dark mode.** The conversation cards followed the operating system's dark setting while the rest of wp-admin — and the text inside those cards — stayed light-mode, giving near-black text on a near-black card. wp-admin does not follow the OS setting, so VibeSnip no longer does either.
* Improved: **what the AI writes.** It now aims for one snippet that completely does the job rather than several that each do part of it, and is told plainly to write the simplest code that fully works — no invented settings screens, no configurability nobody asked for, and equally no shortcuts or placeholders. A request like "make the admin greeting customizable" previously came back as two snippets, one of which did nothing without the other.

= 0.10.0 =
* New: **use the AI provider you already pay for.** Alongside Anthropic (Claude), VibeSnip now works with **OpenAI** and **Google Gemini** — pick one on the Settings page and paste that vendor's key. Your key, model and endpoint are remembered separately for each provider, so switching between them to compare does not throw the other one away. Only the provider you have selected is ever contacted; see **External services** above for exactly what is sent to each.
* New: **any OpenAI-compatible gateway.** Each provider has an optional custom endpoint. Because the OpenAI adapter speaks the standard chat-completions format, pointing it at OpenRouter, Groq, Together, Azure OpenAI, or a model running locally under Ollama or llama.cpp works with no extra setup. If you use it, your data goes to that host instead — the field says so on screen.
* New: **Test connection** on the Settings page. One tiny request that tells you whether your key works, instead of finding out minutes later in the middle of composing.
* Changed: **AI Compose is now a conversation, and nothing is saved until you say so.** Describe what you want and it first tells you what it understood, how it would build it, what it would write, and what it had to assume — with no code yet. Approve it and it writes the snippet; say it got it wrong and you can rephrase, with your earlier wording kept as context so the second attempt is a correction rather than a fresh start. What it writes is then shown with its name, its language, what it does and a safety review, and **only then** is there a Save button. Previously a proposal could be generated and saved before you had really read it, which made snippets easy to lose track of.
* Improved: a reply cut off by the provider's token limit now says so, instead of reporting that the AI "did not return a usable snippet" — which sent people rewriting a prompt that was never the problem.
* Fixed: the **Permissions** matrix looked like an empty area. On a standard install the only tickable box was already ticked, and every other cell was a bare dash. Each checkbox now has a label you can click, the Administrator row reads "Always on" instead of looking broken, and cells that cannot be granted say "Not eligible" with an explanation of what makes a role eligible. The rule itself is unchanged: managing snippets means running code, so it stays with roles that can already administer the site.
* New: an optional **donation** link at the bottom of the main screens, and a Settings checkbox that removes it everywhere. VibeSnip is free and every feature stays free; if you would rather not be asked, tick the box once and you will not be.

= 0.9.0 =
* Removed: the **Test** and **Preview** buttons in the editor. Test relied on the site making an HTTP request to itself, which times out on any host that serves one request at a time or blocks loopback requests — so in practice it reported a 25-second timeout instead of a result. Preview could only ever show a placeholder for PHP, which is what most snippets are. Neither carried its weight, and the safety they implied is already provided properly: PHP is syntax-checked before it can activate, and if a live snippet fatals it is switched off automatically and rolled back. The editor is simpler for it, and **Ask AI** now has the heading bar to itself.
* Changed: the `vibesnip/test-snippet` ability is gone for the same reason. Every other ability is unchanged.
* Improved: bigger, clearer editor heading bar, and PHP and CSS no longer share a near-identical blue — PHP is violet, CSS is teal, so you can tell a snippet's language at a glance.
* Security: on a **multisite network**, only a Super Admin can create, edit or activate **PHP** snippets. PHP snippets run as site code, and WordPress restricts that power on a network for the same reason it disables the plugin and theme file editors there. CSS, JavaScript, HTML and text snippets are unchanged, and PHP snippets that are already active keep running.
* Security: the Permissions matrix no longer offers snippet management to roles that cannot already administer the site. Managing snippets means running code, so granting it to a subscriber or contributor would have handed them more power than their role is meant to have. Any such grant made by an earlier version is removed the next time you save permissions.
* Improved: clearer explanations throughout the code for why each security exception exists, and a core dashicon in place of an inline menu image.

= 0.7.1 =
* Fixed: a snippet that fataled *later* — inside a hook it registered, which is how nearly every snippet works — was not being caught. Only code that ran during activation itself was attributed, so a broken callback kept fataling on the front end on every request. Callbacks a snippet registers are now guarded too: the snippet is auto-deactivated, the error and line are recorded, and the very next page load is healthy.

= 0.7.0 =
* New: **Ask AI** — a review button in the editor. Paste a snippet you found online and get a plain-English report: a safe / caution / not-safe assessment, what the code does, what it changes on your site, specific risks, and what to check after switching it on. One-click chips apply its suggested name, type, location and tags. The report is advisory and is stored with the snippet, so the assessment shows next to it on the All Snippets screen. It cannot activate anything: the PHP syntax check remains the only gate on activation, and the report never claims the code compiles.
* New: **Permissions** — Settings now has a role-by-capability matrix for "Manage snippets" and "Use AI features", backed by real WordPress capabilities. Hand the site to an editor or author for content work and they cannot touch snippets or spend AI credit. Administrators always keep "Manage snippets" so you cannot lock yourself out.
* New: Settings is organised into General, Permissions and Debug tabs. General adds "Save & Activate as the primary button", a default sort order for the snippets list, "Hide notices about optional paid features", and an opt-in "delete all data on uninstall". Debug adds "Upgrade database tables" and "Reset caches".
* New: Claude Opus 5 is available as a model and is the new default, at the same price as Opus 4.8.
* Fixed: database changes shipped in an update now actually reach sites that update in place. WordPress does not re-run activation hooks on update, so new columns were previously only added on a fresh activation.
* Fixed: the "delete all data on uninstall" opt-in had no way to be switched on — the setting it looked for could never be set. It is now the checkbox on Settings → General, and uninstall also clears settings and removes capabilities from every role.

= 0.6.0 =
* New: Agent access via the WordPress Abilities API (Phase 4). VibeSnip now registers its whole safe loop as standard WordPress "abilities" — get-site-context, list-snippets, get-snippet, create-snippet, update-snippet, test-snippet, activate-snippet, deactivate-snippet and rollback-snippet — so any AI agent or tool that speaks the Abilities API can drive the exact same guarded flow this dashboard uses. Nothing bypasses the guard: creating a snippet always makes an inactive draft, activating validates the PHP first and health-checks the front page after (auto-rolling-back a fatal), updating refuses to overwrite a live snippet with broken code, and rolling back always deactivates. Every ability requires the manage_vibesnip capability, and agent-driven changes are recorded in the audit log. The Settings page shows whether the Abilities API is present; VibeSnip works fully without it.

= 0.5.0 =
* New: Sandbox dry-run (Phase 3, part 2). A "Test" button in the editor runs your snippet ONCE in an isolated loopback request — a real WordPress context that never serves a page to a visitor and never loads your other active snippets — and reports whether it ran clean, its execution time, and any syntax error, exception or fatal (with the line). Test before you activate.
* New: Post-activation health-check with automatic rollback. When you activate a PHP snippet, VibeSnip immediately loads the front page in the background so any front-end-only fatal is caught right away — if it fatals, the snippet is automatically rolled back and you are told, instead of a visitor finding out.

= 0.4.0 =
* New: AI Compose (Phase 3, part 1). Describe what you want in plain English on the new AI Compose screen; VibeSnip calls your AI provider (Anthropic Claude — bring your own key on the Settings page) and writes the snippet for you. Each proposal is saved as an inactive draft and opened in the editor to review, preview and activate — nothing runs until you switch it on, and PHP is still syntax-checked before it can be activated. Generated snippets are tagged with their AI provenance in the audit log. (The isolated sandbox dry-run, before/after preview and automatic rollback are the next part of Phase 3.)
* New: Settings page to store your API key (kept only on your site) and choose the model.

= 0.3.1 =
* Fix: A PHP snippet that echoes output directly (e.g. `echo "© " . date('Y');`) no longer breaks the admin. Previously such a snippet ran before the admin action handler and sent output early, which stopped the deactivate toggle (and other admin redirects) from working — you would land on a near-blank page showing the snippet's output. Stray output from direct PHP execution is now discarded in the admin. To *display* something, use a shortcode snippet or a snippet that hooks `wp_footer`.

= 0.3.0 =
* New: Smart conditional logic — a visual rule builder to run a snippet only when rules match (user status, user role, page type, post type, URL path, device), combined with ALL or ANY. Conditional PHP is deferred to where page context exists so the rules mean what they say.
* New: Importers — bring your snippets over from WPCode and Code Snippets by uploading their JSON export. Everything imports inactive for review; scopes map to VibeSnip locations with safe fallbacks.
* This completes Phase 2 (bar an optional Monaco editor — WordPress' built-in CodeMirror covers syntax highlighting with zero build step).

= 0.2.0 =
* New: Ready-made Library — one-click, vetted snippets (disable comments, disable XML-RPC, allow SVG, limit revisions, reading time, and more). Each is added as an inactive snippet you review before switching on.
* New: Per-language colour in the editor — the heading (dot, filename and badge) is tinted by language and updates live when you change the type.
* New: Live preview for CSS, JavaScript, HTML and text snippets, rendered in a sandboxed frame.

= 0.1.0 =
* Initial release: snippet engine, execution for all types, syntax validation, auto-deactivate on fatal, Safe Mode, revisions and an audit log.
