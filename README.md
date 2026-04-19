# SPC Prefetch Connector

**Makes WordPress feel like a static site.**

A free WordPress plugin by [Nahnu Plugins](https://www.nahnuplugins.com) that layers three browser-native performance technologies on top of [Super Page Cache](https://wordpress.org/plugins/wp-cloudflare-page-cache/) (Free and Pro) to make page navigation feel instant.

---

## How it works

| Layer | Browser support | What it does |
|---|---|---|
| **Speculation Rules: prefetch** | Chrome 109+ | Downloads the HTML of a page on hover. Zero JS overhead — the browser handles it natively in the network stack. |
| **Speculation Rules: prerender** | Chrome 108+ | Fully executes the page in a hidden renderer on mousedown. When the user clicks, Chrome activates it with zero latency. |
| **instant.page fallback** | Firefox, Safari, Edge | Prefetches HTML via `<link rel=prefetch>` on hover (65 ms delay). Touchstart on mobile. |
| **View Transitions** | Chrome 111+, Safari 18+ | Wraps navigation in a 160 ms fade or slide animation. When prerender is ready the transition is truly instant. |
| **Analytics guard** | All browsers | Queues GTM / GA4 / Facebook Pixel during prerender and replays on real activation. Prevents inflated pageview counts. |
| **Preconnect hints** | All browsers | Completes DNS + TLS handshake for third-party origins before they are needed. |

---

## Requirements

- WordPress 6.0+
- PHP 7.4+
- [Super Page Cache](https://wordpress.org/plugins/wp-cloudflare-page-cache/) (Free or Pro) — recommended but not strictly required

---

## Installation

1. Download the latest release zip from the [Releases](https://github.com/jaimealnassim/SPC-Prefetch-Connector/releases) page
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate
4. Go to **Settings → SPC Prefetch** to configure

---

## Super Page Cache — Recommended Settings

When running alongside this plugin, configure SPC as follows for best results:

| SPC Setting | Recommended | Why |
|---|---|---|
| Prefetch URLs on hover | **OFF** | This plugin handles prefetch better with Speculation Rules + prerender. If left ON, it auto-detects SPC and defers, but you lose Speculation Rules and prerender entirely. |
| Defer JS (Pro) | Either | Pro's Defer JS explicitly skips `type="module"` scripts. We also hook `spc_defer_script` as a backup. Safe either way. |
| Delay JS (Pro) | **OFF** | Does not skip `type="module"` scripts — could break instant.page and view-transitions. We auto-exclude our scripts via an option filter as protection, but OFF is cleaner. |
| Remove Unused CSS (Pro) | Fine | Operates on cached HTML only. No effect on our scripts. |

---

## Features

### Speculation Rules API (Chrome 109+)
Injects a `<script type="speculationrules">` block directly into `<head>`. No JavaScript execution cost — Chrome handles prefetch and prerender natively. Firefox and Safari ignore the block silently.

**Prefetch eagerness** is configurable:
- `conservative` — fires on mousedown (default when mobile strategy is touchstart)
- `moderate` — fires on hover ~200 ms

**Prerender eagerness** is configurable:
- `conservative` (recommended) — fires on mousedown only, no accidental pageview inflation
- `moderate` — fires on hover ~200 ms, analytics will fire on the target page before the user clicks

### instant.page fallback
Loads [instant.page v5.2.0](https://instant.page) as a `<script type="module">` for Firefox, Safari, and older Chromium. Configurable hover delay (default 65 ms).

**Mobile strategies:**
- `touchstart` — fires on finger-touch before release (recommended)
- `viewport` — prefetch links as they scroll into view
- `disabled` — hover only, no mobile prefetch from instant.page

### View Transitions
Intercepts navigation clicks and wraps them in `document.startViewTransition()`. Two animation styles: **fade** (160 ms crossfade) and **slide** (old page left, new page from right). Respects `prefers-reduced-motion`.

### Analytics guard
Automatically protects GTM (`dataLayer`), GA4 (`gtag`), and Facebook Pixel (`fbq`) during prerender by queuing calls and replaying them on activation via the `prerenderingchange` event.

For scripts that cannot be deferred (Hotjar, Clarity, chat widgets etc.), the plugin supports server-side suppression via the `Sec-Purpose: prerender` HTTP header that Chrome sends on prerender requests. Add the WP script handle to the **Extra script suppression** setting.

### WooCommerce support
Cart, checkout, my-account, shop, and order pages are automatically excluded from prefetch and prerender. Works with **WPML** and **Polylang** — all translated page slugs are collected and excluded automatically.

### SPC Pro compatibility
- Hooks `spc_defer_script` filter to protect our scripts from Pro's Defer JS feature
- Hooks `option_swcfpc_config` to inject our script paths into Pro's Delay JS exclusion list at read time — no database writes

---

## Settings

All settings are under **Settings → SPC Prefetch** in WordPress admin.

### Dashboard tab
Live status cards for all six features. SPC compatibility table showing the recommended SPC settings alongside your current SPC configuration with live status badges.

### Settings tab
- Enable / disable the plugin
- Hover delay (instant.page fallback)
- Mobile strategy
- Allow query strings / external links
- Excluded paths (glob patterns, one per line)
- Prerender on/off + eagerness
- Extra script suppression (for Hotjar, Clarity etc.)
- View Transitions on/off + animation style
- Preconnect origins
- Override SPC hover-prefetch

### About tab
Plugin description, how-it-works table, license info, and links.

---

## Per-link opt-outs

Add these attributes to any `<a>` tag to exclude it from specific features:

```html
<!-- Skip instant.page prefetch -->
<a href="/page" data-no-instant>...</a>

<!-- Skip View Transitions intercept -->
<a href="/page" data-no-transition>...</a>
```

---

## How to verify it's working

1. Open Chrome or Edge and go to your site
2. Open DevTools → **Application → Background Services → Speculative Loads → Speculations**
3. Open this panel **before** loading the page
4. Navigate to any page on your site
5. You should see URLs listed with Action: **Prefetch** and Status: **Not triggered**
6. Hover over a link — status changes to **Success**
7. Click a link — if prerender fired, the page activates instantly

You can also verify by viewing page source and searching for:
- `speculationrules` — the Speculation Rules JSON block
- `instantpage` — the instant.page script tag (only present when SPC hover-prefetch is off or Override SPC is on)
- `prerenderingchange` — the analytics guard script

---

## Compatibility

- ✅ Super Page Cache Free
- ✅ Super Page Cache Pro (Defer JS, Delay JS, Unused CSS all handled)
- ✅ WooCommerce
- ✅ WPML
- ✅ Polylang
- ✅ Chrome 108+, Edge 108+
- ✅ Firefox (instant.page fallback)
- ✅ Safari (instant.page fallback + View Transitions on Safari 18+)

---

## Third-party libraries

| Library | Version | License | Author |
|---|---|---|---|
| [instant.page](https://instant.page) | 5.2.0 | LGPL-2.1 | Alexandre Dieulot |

---

## License

GPL-2.0+  
Free for everyone, forever.

---

## Author

**Nahnu Plugins**  
[www.nahnuplugins.com](https://www.nahnuplugins.com)
