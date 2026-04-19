=== SPC Prefetch Connector ===
Contributors: nahnuplugins
Tags: prefetch, performance, speed, speculation rules, instant page
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0+

Smart two-layer link prefetching. Native Speculation Rules API on Chrome 109+; instant.page fallback everywhere else.

== Description ==

**Layer 1 — Speculation Rules API (Chrome 109+)**
Injects a `<script type="speculationrules">` block directly into `<head>`. Zero JavaScript execution cost. Chrome handles hover-prefetch entirely in the browser's network stack. Other browsers silently ignore this block.

**Layer 2 — instant.page fallback**
Loads `instant.page v5.2.0` as a `<script type="module">` for Firefox, Safari, and older Chromium. Prefetches the HTML of a link after the cursor has hovered for a configurable delay (default 65 ms).

**Mobile**
- *Touchstart (recommended):* prefetch fires the moment a finger touches a link, before release — nearly the same gain as hover on desktop.
- *Viewport:* prefetch every link as it scrolls into view (more bandwidth).
- *Disabled:* hover-only; Speculation Rules will still run on mobile Chrome.

**Super Page Cache compatibility**
When SPC is active *and* its own hover-prefetch is enabled, SPC Prefetch stays completely silent to avoid duplicate scripts. SPC's excluded-URL list is merged into SPC Prefetch's exclusion check automatically. Enable "Override SPC" in settings if you want SPC Prefetch to take over (e.g. to gain Speculation Rules support that SPC doesn't yet have).

== Installation ==

1. Upload the `spc-prefetch-connector` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to **Settings → SPC Prefetch** to configure.

== Frequently Asked Questions ==

= Will this conflict with WP Rocket / other caching plugins? =
No. The plugin only outputs a `<script type="speculationrules">` block and optionally enqueues instant.page. It does not touch the cache itself.

= How do I exclude a specific link? =
Add `data-no-instant` to the anchor tag. instant.page and our filter both respect it.

= Why does this not show up in PageSpeed / GTmetrix scores? =
Prefetching is triggered by real user interaction (hover / touch), so synthetic tests never see it. The improvement is felt by real visitors.

== Changelog ==

= 1.0.0 =
* Initial release.
* Native Speculation Rules API (Chrome 109+).
* instant.page 5.2.0 fallback.
* Touchstart / viewport / disabled mobile strategies.
* SPC conflict detection and exclusion-list merging.
* Settings page under Settings → SPC Prefetch.
