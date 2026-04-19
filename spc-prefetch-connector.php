<?php
/**
 * Plugin Name: SPC Prefetch Connector
 * Plugin URI:  https://www.nahnuplugins.com
 * Description: Makes WordPress feel like a static site. Speculation Rules (prefetch + prerender) for Chrome 109+, instant.page fallback for all others, optional View Transitions cross-fade/slide, and preconnect hints for third-party origins.
 * Version:     1.0.9
 * Author:      Nahnu Plugins
 * Author URI:  https://www.nahnuplugins.com
 * License:     GPL-2.0+
 * Text Domain: spc-prefetch
 */

defined( 'ABSPATH' ) || exit;

define( 'SPC_PREFETCH_VERSION', '1.0.9' );
define( 'SPC_PREFETCH_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SPC_PREFETCH_URL',     plugin_dir_url( __FILE__ ) );
define( 'SPC_PREFETCH_OPTION',  'spc_prefetch_settings' );

add_action( 'plugins_loaded', 'spc_prefetch_init' );

function spc_prefetch_init() {
	if ( is_admin() ) {
		add_action( 'admin_menu',    'spc_prefetch_admin_menu' );
		add_action( 'admin_init',    'spc_prefetch_register_settings' );
		add_action( 'admin_notices', 'spc_prefetch_spc_conflict_notice' );
	}

	if ( ! is_admin() ) {
		add_action( 'wp_enqueue_scripts', 'spc_prefetch_enqueue' );
		add_action( 'wp_head',            'spc_prefetch_output_head_tags', 2 );
	}

	// SPC Pro compatibility hooks (1.0.3).
	// These fire on both front-end and admin so Pro's HTML parser sees them.
	// spc_defer_script: tells Defer JS not to add defer="" to our scripts.
	// Note: Defer JS already skips type="module" scripts (line 313 of Pro's
	// HTML_Modifier), so this is belt-and-suspenders for edge cases where the
	// type attribute isn't present yet when the filter runs.
	add_filter( 'spc_defer_script', 'spc_prefetch_protect_from_defer', 10, 3 );

	// Delay JS has no per-script filter. It reads its exclusion list from the
	// swcfpc_config option. We hook option_swcfpc_config to inject our script
	// paths into cf_delay_js_excluded_files at read time — no DB writes needed.
	add_filter( 'option_swcfpc_config', 'spc_prefetch_inject_delay_js_exclusions' );
}

// ---------------------------------------------------------------------------
// SPC Pro compatibility (1.0.3)
// ---------------------------------------------------------------------------

/**
 * Our script paths — used in both Pro compatibility hooks.
 *
 * @return string[]
 */
function spc_prefetch_our_script_paths(): array {
	// Relative paths as they appear in the src attribute after the domain.
	// Pro's is_excluded_src() does a strpos() match, so partial paths work.
	return [
		'spc-prefetch-connector/assets/js/instantpage.min.js',
		'spc-prefetch-connector/assets/js/view-transitions.js',
	];
}

/**
 * Filter: spc_defer_script
 *
 * Tells SPC Pro's Defer JS feature not to add defer="" to our scripts.
 * Defer JS already skips type="module" scripts via its own check, but this
 * is a belt-and-suspenders guard for environments where the cached HTML is
 * processed before our type="module" attribute is written.
 *
 * @param bool   $should_defer Whether to defer the script.
 * @param string $src          The script src URL.
 * @param string $id           The script element id attribute.
 * @return bool
 */
function spc_prefetch_protect_from_defer( bool $should_defer, string $src, string $id ): bool {
	if ( ! $should_defer ) {
		return false; // already excluded by something else
	}

	foreach ( spc_prefetch_our_script_paths() as $path ) {
		if ( strpos( $src, $path ) !== false ) {
			return false; // tell Pro: do not defer this script
		}
	}

	return $should_defer;
}

/**
 * Filter: option_swcfpc_config
 *
 * Injects our script paths into SPC Pro's cf_delay_js_excluded_files list
 * at read time, so the Delay JS feature never touches our scripts.
 *
 * Delay JS has no per-script filter hook. It reads its user-defined exclusion
 * list from swcfpc_config['cf_delay_js_excluded_files'] via Settings_Store::get().
 * By filtering the option on the way out we avoid any DB writes while still
 * making Pro treat our scripts as excluded.
 *
 * This filter is a no-op when SPC Pro is not active (the Delay JS setting
 * key simply won't exist in the config array).
 *
 * @param mixed $config The raw swcfpc_config option value.
 * @return mixed
 */
function spc_prefetch_inject_delay_js_exclusions( $config ) {
	if ( ! is_array( $config ) ) {
		return $config;
	}

	// Only act when the Delay JS excluded files key exists (Pro-only setting).
	if ( ! array_key_exists( 'cf_delay_js_excluded_files', $config ) ) {
		return $config;
	}

	$existing  = is_array( $config['cf_delay_js_excluded_files'] )
		? $config['cf_delay_js_excluded_files']
		: [];
	$our_paths = spc_prefetch_our_script_paths();

	// Early exit: if all our paths are already present, nothing to do.
	// This avoids re-building the array on every swcfpc_config read.
	$needs_update = false;
	foreach ( $our_paths as $path ) {
		if ( ! in_array( $path, $existing, true ) ) {
			$needs_update = true;
			break;
		}
	}
	if ( ! $needs_update ) {
		return $config;
	}

	foreach ( $our_paths as $path ) {
		if ( ! in_array( $path, $existing, true ) ) {
			$existing[] = $path;
		}
	}

	$config['cf_delay_js_excluded_files'] = $existing;

	return $config;
}

// ---------------------------------------------------------------------------
// Settings helpers
// ---------------------------------------------------------------------------

function spc_prefetch_get_settings(): array {
	static $cached = null;
	if ( $cached !== null ) {
		return $cached;
	}

	$defaults = [
		// Core
		'enabled'                => true,
		'hover_delay_ms'         => 65,
		'mobile_strategy'        => 'touchstart',  // touchstart | viewport | disabled
		'allow_query_strings'    => false,
		'allow_external'         => false,
		'excluded_paths'         => '',

		// Prerender (1.0.1)
		'prerender_enabled'      => true,
		'prerender_eagerness'    => 'conservative', // conservative | moderate
		// conservative = mousedown only (safe default — no side effects from hover)
		// moderate = hover ~200ms (only use when pages have no analytics on load
		//            or you accept duplicate pageview events)

		// View Transitions (1.0.1)
		'view_transitions'       => true,
		'transition_style'       => 'fade',        // fade | slide | none

		// Preconnect hints (1.0.1)
		'preconnect_origins'     => '',            // newline-separated origins

		// Prerender analytics guard (1.0.2)
		// Script handles to defer until after prerender activation.
		// Comma-separated WP script handles e.g. "google-tag, facebook-pixel"
		'prerender_defer_scripts' => '',

		// WPML / Polylang (1.0.2)
		// Auto-detected — no setting needed. Exclusion list is built at runtime.

		// SPC compat
		'override_spc'           => false,
	];

	$cached = wp_parse_args( get_option( SPC_PREFETCH_OPTION, [] ), $defaults );
	return $cached;
}

function spc_prefetch_spc_owns_prefetch(): bool {
	static $cached = null;
	if ( $cached !== null ) {
		return $cached;
	}
	$spc_config = get_option( 'swcfpc_config', [] );
	$cached = ! empty( $spc_config['cf_prefetch_urls_on_hover'] );
	return $cached;
}

/**
 * Return true when SPC Pro (not just Free) is active.
 * Detected via the SPC_PRO_PATH constant Pro defines in its main file,
 * or the Pro-only Loader class.
 */
function spc_prefetch_is_spc_pro(): bool {
	static $cached = null;
	if ( $cached !== null ) {
		return $cached;
	}
	$cached = defined( 'SPC_PRO_PATH' ) || class_exists( 'SPC_Pro\\Loader' );
	return $cached;
}

/**
 * Return true when SPC Free OR Pro is installed and active — regardless of
 * whether any particular setting is turned on.
 *
 * Detection strategy (in order of reliability):
 *   1. SW_CLOUDFLARE_PAGECACHE class — the main plugin class both Free and
 *      Pro define. Present as soon as either version loads.
 *   2. swcfpc_config option exists in the database — written on first save,
 *      covers edge cases where the class hasn't loaded yet at our hook.
 *   3. SPC_PRO_PATH constant — Pro only, subset of case 1.
 */
function spc_prefetch_is_spc_installed(): bool {
	static $cached = null;
	if ( $cached !== null ) {
		return $cached;
	}
	if ( class_exists( 'SW_CLOUDFLARE_PAGECACHE' ) ) {
		$cached = true;
		return $cached;
	}
	if ( spc_prefetch_is_spc_pro() ) {
		$cached = true;
		return $cached;
	}
	// Fall back to option existence — present on any site that has ever had SPC.
	$config = get_option( 'swcfpc_config', '__not_set__' );
	$cached = $config !== '__not_set__';
	return $cached;
}

/**
 * Return the current SPC Pro settings relevant to our compatibility table.
 * Returns null values when Pro is not active.
 *
 * @return array{hover_prefetch:bool, defer_js:bool|null, delay_js:bool|null, unused_css:bool|null}
 */
function spc_prefetch_get_spc_pro_status(): array {
	static $cached = null;
	if ( $cached !== null ) {
		return $cached;
	}
	$config       = get_option( 'swcfpc_config', [] );
	$is_installed = spc_prefetch_is_spc_installed();
	$is_pro       = spc_prefetch_is_spc_pro();

	// For Free: hover_prefetch is meaningful; defer/delay/unused_css do not
	// exist in Free so we use the string 'free' as a sentinel so the dashboard
	// can render a distinct "Free — not available" badge rather than N/A.
	$cached = [
		'hover_prefetch' => $is_installed ? ! empty( $config['cf_prefetch_urls_on_hover'] ) : null,
		'defer_js'       => $is_pro ? ! empty( $config['cf_defer_js'] )   : ( $is_installed ? 'free' : null ),
		'delay_js'       => $is_pro ? ! empty( $config['cf_delay_js'] )   : ( $is_installed ? 'free' : null ),
		'unused_css'     => $is_pro ? ! empty( $config['cf_unused_css'] ) : ( $is_installed ? 'free' : null ),
	];
	return $cached;
}

/**
 * Build the full exclusion path list.
 *
 * Hard exclusions are always included. WooCommerce paths are added
 * automatically when WooCommerce is active. SPC's own excluded URL
 * list is merged in when SPC is active.
 */
function spc_prefetch_get_excluded_paths(): array {
	static $cached = null;
	if ( $cached !== null ) {
		return $cached;
	}

	$settings = spc_prefetch_get_settings();

	$hard = [
		'/wp-admin*',
		'/wp-login.php*',
		'/wp-cron.php*',
		'*action=logout*',
		'*nonce=*',
	];

	// -----------------------------------------------------------------------
	// WooCommerce exclusions — with WPML and Polylang support (1.0.2)
	//
	// wc_get_page_id() only returns the ID for the current language. With WPML
	// or Polylang active, each WC page has a separate translated page ID and
	// slug per language. We collect ALL translated slugs so that /panier*,
	// /warenkorb*, /carrello* etc. are all excluded, not just the default slug.
	// -----------------------------------------------------------------------
	if ( class_exists( 'WooCommerce' ) ) {
		$wc_slugs    = [];
		$wc_page_keys = [ 'cart', 'checkout', 'myaccount', 'shop' ];

		// Collect all language codes we need to check.
		$lang_codes = spc_prefetch_get_all_language_codes();

		foreach ( $wc_page_keys as $page_key ) {
			$original_id = wc_get_page_id( $page_key );
			if ( $original_id < 1 ) {
				continue;
			}

			// Always include the original/default language slug.
			$slug = get_post_field( 'post_name', $original_id );
			if ( $slug ) {
				$wc_slugs[] = '/' . $slug . '*';
			}

			// Collect translated slugs for each active language.
			foreach ( $lang_codes as $lang_code ) {
				$translated_id = spc_prefetch_get_translated_post_id( $original_id, 'page', $lang_code );
				if ( $translated_id && $translated_id !== $original_id ) {
					$t_slug = get_post_field( 'post_name', $translated_id );
					if ( $t_slug ) {
						$wc_slugs[] = '/' . $t_slug . '*';
					}
				}
			}
		}

		// Query-string patterns that apply in every language.
		$wc_slugs[] = '*add-to-cart=*';
		$wc_slugs[] = '*order-pay*';
		$wc_slugs[] = '*order-received*';

		$hard = array_merge( $hard, $wc_slugs );
	}

	// User-configured paths.
	$raw = trim( $settings['excluded_paths'] );
	$our_paths = $raw ? array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) : [];

	// SPC's excluded URL list.
	$spc_paths = [];
	$spc_config = get_option( 'swcfpc_config', [] );
	if ( ! empty( $spc_config['cf_fallback_cache_excluded_urls'] ) && is_array( $spc_config['cf_fallback_cache_excluded_urls'] ) ) {
		$spc_paths = $spc_config['cf_fallback_cache_excluded_urls'];
	}

	$cached = array_values( array_unique( array_merge( $hard, $our_paths, $spc_paths ) ) );
	return $cached;
}

/**
 * Return all active language codes from WPML or Polylang.
 * Returns an empty array when neither is active (caller handles the default).
 *
 * @return string[]
 */
function spc_prefetch_get_all_language_codes(): array {
	// WPML: apply_filters( 'wpml_active_languages', null ) returns an array
	// keyed by language code, each element having a 'language_code' key.
	if ( has_filter( 'wpml_active_languages' ) ) {
		$wpml_langs = apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] );
		if ( is_array( $wpml_langs ) && ! empty( $wpml_langs ) ) {
			return array_keys( $wpml_langs );
		}
	}

	// Polylang: pll_languages_list() returns an array of language slugs.
	if ( function_exists( 'pll_languages_list' ) ) {
		$pll_langs = pll_languages_list( [ 'fields' => 'slug' ] );
		if ( is_array( $pll_langs ) && ! empty( $pll_langs ) ) {
			return $pll_langs;
		}
	}

	return [];
}

/**
 * Get the translated post ID for a given language.
 * Supports WPML and Polylang. Falls back to the original ID.
 *
 * @param int    $post_id   Original post ID.
 * @param string $post_type Post type (e.g. 'page').
 * @param string $lang_code Language code (e.g. 'de', 'fr').
 * @return int
 */
function spc_prefetch_get_translated_post_id( int $post_id, string $post_type, string $lang_code ): int {
	// WPML: wpml_object_id filter returns the translated ID for the language.
	// Third arg TRUE = return original if translation is missing (safe).
	if ( has_filter( 'wpml_object_id' ) ) {
		$translated = apply_filters( 'wpml_object_id', $post_id, $post_type, true, $lang_code );
		if ( $translated ) {
			return (int) $translated;
		}
	}

	// Polylang: pll_get_post() returns the translated post ID.
	if ( function_exists( 'pll_get_post' ) ) {
		$translated = pll_get_post( $post_id, $lang_code );
		if ( $translated ) {
			return (int) $translated;
		}
	}

	return $post_id;
}

// ---------------------------------------------------------------------------
// Head tags: Speculation Rules + preconnect hints
// ---------------------------------------------------------------------------

function spc_prefetch_output_head_tags() {
	$settings = spc_prefetch_get_settings();

	if ( ! $settings['enabled'] ) {
		return;
	}

	// Preconnect hints — no SPC dependency, always output when configured.
	spc_prefetch_output_preconnect( $settings );

	// Prerender analytics guard — must come before analytics scripts load (1.0.2).
	// Defers specified script handles until after the prerendered page activates.
	spc_prefetch_output_prerender_guard( $settings );

	// Speculation Rules — defer to SPC if it owns prefetch and no override.
	if ( spc_prefetch_spc_owns_prefetch() && ! $settings['override_spc'] ) {
		return;
	}

	spc_prefetch_output_speculation_rules( $settings );
}

/**
 * Output the prerender analytics guard inline script.
 *
 * Problem: when Chrome prerenders a page, it runs all JS including analytics.
 * GA4/GTM fire a pageview before the user has actually navigated, inflating
 * metrics. The fix is to check document.prerendering and defer analytics
 * calls until the 'prerenderingchange' event fires (= real activation).
 *
 * This function injects a tiny <script> into <head> that:
 *  1. Detects if the page is currently being prerendered.
 *  2. If so, queues a callback that re-fires the deferred pageview once
 *     the user actually navigates to the page.
 *  3. For GTM: delays dataLayer.push until activation.
 *  4. For GA4 (gtag): wraps window.gtag so calls are queued.
 *  5. For Facebook Pixel (fbq): same queue pattern.
 *
 * Works entirely via the standard document.prerendering / prerenderingchange
 * API — no library needed, ~800 bytes unminified.
 *
 * Additionally patches wp_dequeue_script for any user-specified WP handles
 * so those scripts simply don't load during prerender at all (harder approach
 * for scripts that can't be deferred).
 *
 * @param array $settings Plugin settings.
 */
function spc_prefetch_output_prerender_guard( array $settings ) {
	if ( ! $settings['prerender_enabled'] ) {
		return;
	}

	// Parse user-specified script handles to fully suppress during prerender.
	$raw_handles  = trim( $settings['prerender_defer_scripts'] ?? '' );
	$defer_handles = $raw_handles
		? array_filter( array_map( 'trim', explode( ',', $raw_handles ) ) )
		: [];

	// If specific handles are listed, dequeue them now when prerendering.
	// We detect prerender server-side via the Sec-Purpose header (Chrome sends
	// "prefetch;prerender" or "prerender" for prerendered requests).
	if ( $defer_handles ) {
		// Validate the header value only contains expected characters before using.
		$sec_purpose = preg_replace( '/[^a-z;\s]/', '', strtolower( $_SERVER['HTTP_SEC_PURPOSE'] ?? '' ) );
		$is_prerender_request = str_contains( $sec_purpose, 'prerender' );

		if ( $is_prerender_request ) {
			foreach ( $defer_handles as $handle ) {
				wp_dequeue_script( sanitize_key( $handle ) );
			}
		}
	}

	// Always output the client-side guard for GTM/GA4/Pixel.
	// It does nothing when document.prerendering is false (normal navigation).
	echo "
<!-- SPC Prefetch Connector: prerender analytics guard -->
";
	echo '<script>' . "
";
	echo <<<'GUARDJS'
(function(){
	// Nothing to do if the page is loading normally (not being prerendered).
	if (!document.prerendering) return;

	// -----------------------------------------------------------------------
	// GTM: hold all dataLayer.push() calls until activation.
	// We replace window.dataLayer with a proxy array. On activation we replay
	// every queued push into the real dataLayer.
	// -----------------------------------------------------------------------
	var _spcDlQueue = [];
	var _realDL = window.dataLayer;

	if (!_realDL) {
		// GTM hasn't loaded yet — install queue as dataLayer so GTM snippet
		// initialises into our queue instead of the real one.
		window.dataLayer = {
			push: function() { _spcDlQueue.push(arguments); },
			_spc_queue: true
		};
	} else if (Array.isArray(_realDL)) {
		// GTM already loaded — wrap push.
		var _origPush = _realDL.push.bind(_realDL);
		_realDL.push = function() {
			_spcDlQueue.push(arguments);
		};
		window._spcRestoreDL = function() { _realDL.push = _origPush; };
	}

	// -----------------------------------------------------------------------
	// GA4 / gtag: queue all gtag() calls until activation.
	// -----------------------------------------------------------------------
	var _gtagQueue = [];
	var _realGtag  = window.gtag;
	window.gtag = function() { _gtagQueue.push(arguments); };
	window._spcRealGtag = _realGtag;

	// -----------------------------------------------------------------------
	// Facebook Pixel: queue all fbq() calls until activation.
	// -----------------------------------------------------------------------
	var _fbqQueue = [];
	if (window.fbq) {
		var _realFbq = window.fbq;
		window.fbq  = function() { _fbqQueue.push(arguments); };
		window._spcRealFbq = _realFbq;
	}

	// -----------------------------------------------------------------------
	// On activation: restore originals and replay all queued calls.
	// -----------------------------------------------------------------------
	document.addEventListener('prerenderingchange', function onActivate() {
		document.removeEventListener('prerenderingchange', onActivate);

		// Restore GTM dataLayer.
		if (window.dataLayer && window.dataLayer._spc_queue) {
			window.dataLayer = [];
		}
		if (typeof window._spcRestoreDL === 'function') {
			window._spcRestoreDL();
		}
		// Replay GTM pushes.
		if (Array.isArray(window.dataLayer)) {
			for (var i = 0; i < _spcDlQueue.length; i++) {
				window.dataLayer.push.apply(window.dataLayer, _spcDlQueue[i]);
			}
		}

		// Restore gtag and replay.
		window.gtag = window._spcRealGtag || function(){
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push(arguments);
		};
		for (var j = 0; j < _gtagQueue.length; j++) {
			window.gtag.apply(null, _gtagQueue[j]);
		}

		// Restore fbq and replay.
		if (window._spcRealFbq) {
			window.fbq = window._spcRealFbq;
			for (var k = 0; k < _fbqQueue.length; k++) {
				window.fbq.apply(null, _fbqQueue[k]);
			}
		}
	});
})();
GUARDJS;
	echo '</script>' . "
";
}

/**
 * Output <link rel="preconnect"> tags for configured third-party origins.
 *
 * preconnect tells the browser to perform the DNS lookup, TCP handshake,
 * and TLS negotiation for an origin ahead of time. Useful for Google Fonts,
 * CDNs, analytics hosts etc. Eliminates 100–500 ms on first asset from that
 * origin on slow connections.
 */
function spc_prefetch_output_preconnect( array $settings ) {
	$raw = trim( $settings['preconnect_origins'] );
	if ( ! $raw ) {
		return;
	}

	$origins = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

	echo "\n<!-- SPC Prefetch Connector: preconnect hints -->\n";
	foreach ( $origins as $origin ) {
		// Ensure it looks like a valid https:// origin.
		$origin = esc_url( $origin );
		if ( ! $origin ) {
			continue;
		}
		echo '<link rel="preconnect" href="' . $origin . '" crossorigin>' . "\n";
		// dns-prefetch as fallback for browsers that don't support preconnect
		// (extremely rare today, but costs nothing).
		echo '<link rel="dns-prefetch" href="' . $origin . '">' . "\n";
	}
}

/**
 * Output the <script type="speculationrules"> block.
 *
 * Contains TWO rules:
 *
 * 1. PREFETCH — eagerness matches the mobile_strategy setting.
 *    Downloads the HTML in the background. Used by all Chrome 109+ visitors.
 *
 * 2. PRERENDER — eagerness always "conservative" (mousedown/touchstart) unless
 *    the user explicitly sets it to "moderate".
 *    Executes the full page in a hidden renderer. When the user clicks, Chrome
 *    swaps the pre-rendered page in with zero latency — genuinely instant.
 *
 * The two rules are additive. Chrome will prefetch first on hover, then
 * if the user actually clicks (mousedown), upgrade to prerender if a slot
 * is available (Chrome caps concurrent prerenders at ~2).
 *
 * Firefox and Safari ignore the entire block silently.
 */
function spc_prefetch_output_speculation_rules( array $settings ) {
	$excluded = spc_prefetch_get_excluded_paths();

	// Build negative patterns array.
	$negative_patterns = [];
	foreach ( $excluded as $pattern ) {
		$negative_patterns[] = [ 'pathname' => [ 'glob' => $pattern ] ];
	}

	// Build the shared "where" clause.
	if ( $negative_patterns ) {
		$where_clause = [
			'and' => [
				[ 'href_matches' => [ 'origin' => get_site_url() ] ],
				[ 'not' => [ 'or' => $negative_patterns ] ],
			],
		];
	} else {
		$where_clause = [ 'href_matches' => [ 'origin' => get_site_url() ] ];
	}

	// ------------------------------------------------------------------
	// Prefetch rule
	// ------------------------------------------------------------------
	$prefetch_eagerness = ( 'touchstart' === $settings['mobile_strategy'] )
		? 'conservative'   // only on mousedown — instant.page handles the hover window
		: 'moderate';      // hover ~200 ms (viewport on mobile Chrome)

	$prefetch_rule = [
		'source'    => 'document',
		'eagerness' => $prefetch_eagerness,
		'where'     => $where_clause,
	];

	// ------------------------------------------------------------------
	// Prerender rule (1.0.1)
	// Only included when prerender is enabled in settings.
	// ------------------------------------------------------------------
	$rules_obj = [ 'prefetch' => [ $prefetch_rule ] ];

	if ( $settings['prerender_enabled'] ) {
		$prerender_rule = [
			'source'    => 'document',
			'eagerness' => $settings['prerender_eagerness'], // conservative | moderate
			'where'     => $where_clause,
		];
		$rules_obj['prerender'] = [ $prerender_rule ];
	}

	$json = wp_json_encode( $rules_obj, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

	echo "\n<!-- SPC Prefetch Connector: Speculation Rules (Chrome 109+) -->\n";
	echo '<script type="speculationrules">' . "\n" . $json . "\n" . '</script>' . "\n";
}

// ---------------------------------------------------------------------------
// wp_enqueue_scripts: instant.page fallback + View Transitions
// ---------------------------------------------------------------------------

function spc_prefetch_enqueue() {
	$settings = spc_prefetch_get_settings();

	if ( ! $settings['enabled'] ) {
		return;
	}

	// -----------------------------------------------------------------------
	// View Transitions (1.0.1) — independent of SPC, always enqueue when on.
	// -----------------------------------------------------------------------
	if ( $settings['view_transitions'] && 'none' !== $settings['transition_style'] ) {
		wp_enqueue_style(
			'nahnu-view-transitions',
			SPC_PREFETCH_URL . 'assets/css/view-transitions.css',
			[],
			SPC_PREFETCH_VERSION
		);

		wp_register_script(
			'nahnu-view-transitions',
			SPC_PREFETCH_URL . 'assets/js/view-transitions.js',
			[],
			SPC_PREFETCH_VERSION,
			[ 'in_footer' => true, 'strategy' => 'defer' ]
		);
		wp_enqueue_script( 'nahnu-view-transitions' );
		add_filter( 'script_loader_tag', 'spc_prefetch_add_module_type', 10, 2 );

		// Tell the CSS which animation style to use via a <html> data attribute.
		// We inject this before </body> so it's set before any transition fires.
		$style = sanitize_key( $settings['transition_style'] );
		wp_add_inline_script(
			'nahnu-view-transitions',
			'document.documentElement.setAttribute("data-nahnu-transition",' . wp_json_encode( $style ) . ');',
			'before'
		);
	}

	// -----------------------------------------------------------------------
	// instant.page fallback — defer to SPC when it owns prefetch.
	// -----------------------------------------------------------------------
	if ( spc_prefetch_spc_owns_prefetch() && ! $settings['override_spc'] ) {
		return;
	}

	$excluded = spc_prefetch_get_excluded_paths();
	$delay    = absint( $settings['hover_delay_ms'] );
	$mobile   = $settings['mobile_strategy'];

	wp_register_script(
		'nahnu-instantpage',
		SPC_PREFETCH_URL . 'assets/js/instantpage.min.js',
		[],
		SPC_PREFETCH_VERSION,
		[ 'in_footer' => true, 'strategy' => 'defer' ]
	);
	wp_enqueue_script( 'nahnu-instantpage' );
	add_filter( 'script_loader_tag', 'spc_prefetch_add_module_type', 10, 2 );

	// Build body attribute lines.
	$attr_lines = [];

	if ( 'viewport' === $mobile ) {
		$attr_lines[] = 'document.body.setAttribute("data-instant-intensity","viewport");';
	} elseif ( $delay !== 65 ) {
		$attr_lines[] = 'document.body.setAttribute("data-instant-intensity",' . wp_json_encode( (string) $delay ) . ');';
	}

	if ( $settings['allow_query_strings'] ) {
		$attr_lines[] = 'document.body.setAttribute("data-instant-allow-query-string","");';
	}

	if ( $settings['allow_external'] ) {
		$attr_lines[] = 'document.body.setAttribute("data-instant-allow-external-links","");';
	}

	$attrs_js      = $attr_lines ? implode( "\n\t", $attr_lines ) : '// (no extra attributes)';
	$excluded_json = wp_json_encode( $excluded );
	$block_touch   = ( 'disabled' === $mobile )
		? 'if (typeof window.matchMedia === "function" && window.matchMedia("(hover:none)").matches) return false;'
		: '';

	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
	$inline  = "(function(){\n";
	$inline .= "\t" . $attrs_js . "\n\n";
	$inline .= "\tvar _excluded = " . $excluded_json . ";\n\n";
	$inline .= "\tfunction _wildcard(str,rule){\n";
	$inline .= "\t\tvar esc=function(s){return s.replace(/([.+?^=!:\${}()|\\[\\]\\/\\\\])/g,'\\\\$1');};\n";
	$inline .= "\t\treturn new RegExp('^'+rule.split('*').map(esc).join('.*')+'$').test(str);\n";
	$inline .= "\t}\n\n";
	$inline .= "\twindow.swcfpc_can_url_be_prefetched = function(href){\n";
	$inline .= "\t\tif (!href || href.indexOf('mailto:')===0) return false;\n";
	if ( $block_touch ) {
		$inline .= "\t\t" . $block_touch . "\n";
	}
	$inline .= "\t\tvar path=href.replace(/^https?:\\/\\/[^\\/]*/,'')||'/';\n";
	$inline .= "\t\tfor(var i=0;i<_excluded.length;i++){\n";
	$inline .= "\t\t\tif(_wildcard(path,_excluded[i])) return false;\n";
	$inline .= "\t\t}\n";
	$inline .= "\t\treturn true;\n";
	$inline .= "\t};\n";
	$inline .= "})();\n";
	// phpcs:enable

	wp_add_inline_script( 'nahnu-instantpage', $inline, 'before' );
}

/**
 * Add type="module" to instant.page and view-transitions script tags.
 * Both libraries require ES module context.
 */
function spc_prefetch_add_module_type( string $tag, string $handle ): string {
	$module_handles = [ 'nahnu-instantpage', 'nahnu-view-transitions' ];

	if ( ! in_array( $handle, $module_handles, true ) ) {
		return $tag;
	}

	if ( strpos( $tag, 'text/javascript' ) !== false ) {
		return str_replace( 'text/javascript', 'module', $tag );
	}

	return str_replace( ' src', ' type="module" src', $tag );
}

// ---------------------------------------------------------------------------
// Admin
// ---------------------------------------------------------------------------

function spc_prefetch_admin_menu() {
	add_options_page(
		__( 'SPC Prefetch Connector', 'spc-prefetch' ),
		__( 'SPC Prefetch', 'spc-prefetch' ),
		'manage_options',
		'spc-prefetch',
		'spc_prefetch_page_shell'
	);
}

function spc_prefetch_register_settings() {
	register_setting(
		'spc_prefetch_group',
		SPC_PREFETCH_OPTION,
		[ 'sanitize_callback' => 'spc_prefetch_sanitize_settings' ]
	);
}

function spc_prefetch_sanitize_settings( $input ): array {
	$clean = [];
	$clean['enabled']             = ! empty( $input['enabled'] );
	$clean['hover_delay_ms']      = max( 0, min( 1000, absint( $input['hover_delay_ms'] ?? 65 ) ) );
	$clean['mobile_strategy']     = in_array( $input['mobile_strategy'] ?? '', [ 'touchstart', 'viewport', 'disabled' ], true )
	                                  ? $input['mobile_strategy'] : 'touchstart';
	$clean['allow_query_strings'] = ! empty( $input['allow_query_strings'] );
	$clean['allow_external']      = ! empty( $input['allow_external'] );
	$clean['excluded_paths']      = sanitize_textarea_field( $input['excluded_paths'] ?? '' );
	$clean['prerender_enabled']   = ! empty( $input['prerender_enabled'] );
	$clean['prerender_eagerness'] = in_array( $input['prerender_eagerness'] ?? '', [ 'conservative', 'moderate' ], true )
	                                  ? $input['prerender_eagerness'] : 'conservative';
	$clean['view_transitions']    = ! empty( $input['view_transitions'] );
	$clean['transition_style']    = in_array( $input['transition_style'] ?? '', [ 'fade', 'slide', 'none' ], true )
	                                  ? $input['transition_style'] : 'fade';
	$clean['preconnect_origins']      = sanitize_textarea_field( $input['preconnect_origins'] ?? '' );
	$clean['prerender_defer_scripts'] = sanitize_text_field( $input['prerender_defer_scripts'] ?? '' );
	$clean['override_spc']            = ! empty( $input['override_spc'] );
	return $clean;
}

function spc_prefetch_spc_conflict_notice() {
	$settings = spc_prefetch_get_settings();
	if ( spc_prefetch_spc_owns_prefetch() && ! $settings['override_spc'] ) {
		$url = admin_url( 'options-general.php?page=spc-prefetch' );
		echo '<div class="notice notice-info is-dismissible"><p>';
		printf(
			/* translators: %s = settings link */
			esc_html__( 'SPC Prefetch: Super Page Cache is handling hover-prefetch. Speculation Rules and View Transitions are still active. %s to take over completely.', 'spc-prefetch' ),
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Enable Override', 'spc-prefetch' ) . '</a>'
		);
		echo '</p></div>';
	}
}

/**
 * Enqueue admin assets only on our settings page.
 */
function spc_prefetch_admin_assets( string $hook ) {
	if ( $hook !== 'settings_page_spc-prefetch' ) {
		return;
	}

	$css = '
/* Hide all panels; JS shows the active one instantly */
.spc-panel { display:none; }
.spc-panel.spc-panel-active { display:block; }

.spc-status-card { background:#fff;border:1px solid #dcdcde;border-top:3px solid #787c82;border-radius:3px;padding:14px 16px; }
.spc-status-card.active { border-top-color:#00a32a; }
.spc-status-dot { width:8px;height:8px;border-radius:50%;background:#c3c4c7;flex-shrink:0;display:inline-block; }
.spc-status-dot.active { background:#00a32a; }
.spc-badge { display:inline-block;border-radius:3px;padding:1px 8px;font-size:12px;font-weight:600;color:#fff; }
.spc-badge-ok  { background:#00a32a; }
.spc-badge-warn{ background:#dba617; }
.spc-badge-na  { background:#787c82; }
.spc-badge-off { background:#d63638; }
';
	wp_add_inline_style( 'common', $css );

	// Save nonce for settings form AJAX only — no tab AJAX needed any more.
	$js = '(function($){
var spcSaveNonce=' . wp_json_encode( wp_create_nonce( 'spc_prefetch_save' ) ) . ';
var spcAjax=' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ';

function spcShowTab(tab) {
	// Hide all panels instantly.
	$(".spc-panel").removeClass("spc-panel-active");
	// Show the requested one instantly.
	$("#spc-panel-"+tab).addClass("spc-panel-active");
	// Update nav active state.
	$(".nav-tab").removeClass("nav-tab-active");
	$(".nav-tab[data-tab="+tab+"]").addClass("nav-tab-active");
	// Persist in URL without page load so refresh / back button work.
	history.replaceState(null, "",
		location.pathname + "?page=spc-prefetch&spc_tab=" + tab);
}

$(function(){
	// Tab clicks — instant, no network.
	$(document).on("click", ".nav-tab[data-tab]", function(e){
		e.preventDefault();
		spcShowTab($(this).data("tab"));
	});

	// Show correct tab on first render.
	var params = new URLSearchParams(location.search);
	var initial = params.get("spc_tab") || "dashboard";
	var allowed = ["dashboard","settings","about"];
	spcShowTab(allowed.indexOf(initial) !== -1 ? initial : "dashboard");

	// Settings form: AJAX save so we stay on the settings panel.
	$(document).on("submit", "#spc-settings-form", function(e){
		e.preventDefault();
		var $btn = $(this).find("[type=submit]");
		$btn.prop("disabled", true).val("Saving\u2026");
		$.post(spcAjax,
			$(this).serialize() + "&action=spc_prefetch_save&_ajax_nonce=" + spcSaveNonce,
			function(res){
				$btn.prop("disabled", false).val("Save Changes");
				if (res.success) {
					var $n = $("<div class=\"notice notice-success is-dismissible\" style=\"margin:0 0 16px\"><p>"
						+ res.data.message + "</p></div>");
					$("#spc-settings-form").prepend($n);
					setTimeout(function(){ $n.fadeOut(400, function(){ $(this).remove(); }); }, 3000);
				}
			}
		);
	});
});
})(jQuery);';
	wp_add_inline_script( 'jquery', $js );
}
add_action( 'admin_enqueue_scripts', 'spc_prefetch_admin_assets' );

/**
 * AJAX: save settings (used by the settings form; no tab AJAX needed).
 */
function spc_prefetch_ajax_save() {
	check_ajax_referer( 'spc_prefetch_save' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
	}
	$input = $_POST[ SPC_PREFETCH_OPTION ] ?? [];
	$clean = spc_prefetch_sanitize_settings( $input );
	update_option( SPC_PREFETCH_OPTION, $clean );
	wp_send_json_success( [ 'message' => __( 'Settings saved.', 'spc-prefetch' ) ] );
}
add_action( 'wp_ajax_spc_prefetch_save', 'spc_prefetch_ajax_save' );

/**
 * Page shell: renders all three panels on one PHP request.
 * JS shows/hides them instantly with display:none / display:block.
 */
function spc_prefetch_page_shell() {
	$base = admin_url( 'options-general.php?page=spc-prefetch' );
	?>
	<div class="wrap">

		<h1 style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
			<span><?php esc_html_e( 'SPC Prefetch Connector', 'spc-prefetch' ); ?></span>
			<span style="font-size:12px;font-weight:400;color:#787c82;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;padding:2px 8px;">
				v<?php echo esc_html( SPC_PREFETCH_VERSION ); ?>
			</span>
			<a href="https://github.com/jaimealnassim/SPC-Prefetch-Connector" target="_blank" rel="noopener noreferrer"
			   style="display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:400;color:#787c82;text-decoration:none;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;padding:2px 10px;line-height:1.6;"
			   title="<?php esc_attr_e( 'View on GitHub', 'spc-prefetch' ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
					<path d="M12 0C5.37 0 0 5.37 0 12c0 5.3 3.438 9.8 8.205 11.387.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61-.546-1.387-1.333-1.756-1.333-1.756-1.09-.745.083-.729.083-.729 1.205.084 1.84 1.237 1.84 1.237 1.07 1.834 2.807 1.304 3.492.997.108-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.31.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0 1 12 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222 0 1.606-.015 2.898-.015 3.293 0 .322.216.694.825.576C20.565 21.796 24 17.298 24 12c0-6.63-5.37-12-12-12z"/>
				</svg>
				GitHub
			</a>
		</h1>

		<nav class="nav-tab-wrapper" style="margin-bottom:20px;">
			<a href="<?php echo esc_url( $base . '&spc_tab=dashboard' ); ?>"
			   data-tab="dashboard" class="nav-tab">
				<?php esc_html_e( 'Dashboard', 'spc-prefetch' ); ?>
			</a>
			<a href="<?php echo esc_url( $base . '&spc_tab=settings' ); ?>"
			   data-tab="settings" class="nav-tab">
				<?php esc_html_e( 'Settings', 'spc-prefetch' ); ?>
			</a>
			<a href="<?php echo esc_url( $base . '&spc_tab=about' ); ?>"
			   data-tab="about" class="nav-tab">
				<?php esc_html_e( 'About', 'spc-prefetch' ); ?>
			</a>
		</nav>

		<div style="max-width:780px;">

			<div id="spc-panel-dashboard" class="spc-panel">
				<?php spc_prefetch_tab_dashboard(); ?>
			</div>

			<div id="spc-panel-settings" class="spc-panel">
				<?php spc_prefetch_tab_settings(); ?>
			</div>

			<div id="spc-panel-about" class="spc-panel">
				<?php spc_prefetch_tab_about(); ?>
			</div>

		</div>

	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Tab: Dashboard
// ---------------------------------------------------------------------------

function spc_prefetch_tab_dashboard() {
	$settings   = spc_prefetch_get_settings();
	$spc_active    = spc_prefetch_spc_owns_prefetch();
	$spc_pro       = spc_prefetch_is_spc_pro();
	$spc_installed = spc_prefetch_is_spc_installed();
	$spc_status    = spc_prefetch_get_spc_pro_status();
	$wc_active     = class_exists( 'WooCommerce' );

	$settings_url = admin_url( 'options-general.php?page=spc-prefetch&spc_tab=settings' );
	?>

	<?php if ( ! $settings['enabled'] ): ?>
	<div class="notice notice-warning inline" style="margin-bottom:16px">
		<p><?php printf(
			/* translators: %s = settings link */
			esc_html__( 'SPC Prefetch Connector is disabled. %s to turn it on.', 'spc-prefetch' ),
			'<a href="#" data-tab="settings" class="nav-tab-link">' . esc_html__( 'Go to Settings', 'spc-prefetch' ) . '</a>'
		); ?></p>
	</div>
	<?php endif; ?>

	<?php /* Feature status cards */ ?>
	<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:24px;">
	<?php
	$cards = [
		[
			'label' => __( 'Speculation Rules', 'spc-prefetch' ),
			'on'    => $settings['enabled'],
			'note'  => __( 'Chrome 109+ — native prefetch + prerender', 'spc-prefetch' ),
		],
		[
			'label' => __( 'instant.page Fallback', 'spc-prefetch' ),
			'on'    => $settings['enabled'] && ( ! $spc_active || $settings['override_spc'] ),
			'note'  => ( $spc_active && ! $settings['override_spc'] )
				? __( 'Paused — SPC handles hover-prefetch', 'spc-prefetch' )
				: __( 'Firefox / Safari / older Chrome', 'spc-prefetch' ),
		],
		[
			'label' => __( 'Prerender', 'spc-prefetch' ),
			'on'    => $settings['enabled'] && $settings['prerender_enabled'],
			'note'  => __( 'Chrome 108+ — zero-latency navigation', 'spc-prefetch' ),
		],
		[
			'label' => __( 'View Transitions', 'spc-prefetch' ),
			'on'    => $settings['enabled'] && $settings['view_transitions'],
			'note'  => __( 'Chrome 111+ / Safari 18+', 'spc-prefetch' ),
		],
		[
			'label' => __( 'Analytics Guard', 'spc-prefetch' ),
			'on'    => $settings['enabled'] && $settings['prerender_enabled'],
			'note'  => __( 'GTM / GA4 / Pixel — deferred on prerender', 'spc-prefetch' ),
		],
		[
			'label' => __( 'Preconnect Hints', 'spc-prefetch' ),
			'on'    => $settings['enabled'] && ! empty( trim( $settings['preconnect_origins'] ) ),
			'note'  => ! empty( trim( $settings['preconnect_origins'] ) )
				? sprintf(
					/* translators: %d = count */
					_n( '%d origin', '%d origins', count( array_filter( array_map( 'trim', explode( "\n", $settings['preconnect_origins'] ) ) ) ), 'spc-prefetch' ),
					count( array_filter( array_map( 'trim', explode( "\n", $settings['preconnect_origins'] ) ) ) )
				)
				: __( 'No origins configured', 'spc-prefetch' ),
		],
	];
	foreach ( $cards as $c ):
	?>
	<div class="spc-status-card<?php echo $c['on'] ? ' active' : ''; ?>">
		<div style="display:flex;align-items:center;gap:7px;margin-bottom:5px;">
			<span class="spc-status-dot<?php echo $c['on'] ? ' active' : ''; ?>"></span>
			<strong style="font-size:13px;"><?php echo esc_html( $c['label'] ); ?></strong>
		</div>
		<p style="margin:0;font-size:12px;color:#787c82;"><?php echo esc_html( $c['note'] ); ?></p>
	</div>
	<?php endforeach; ?>
	</div>

	<?php /* SPC Compatibility table — always shown */ ?>
	<div style="border:1px solid #c3c4c7;border-left:4px solid #2271b1;background:#fff;border-radius:2px;margin-bottom:20px;">
		<div style="padding:12px 16px;border-bottom:1px solid #f0f0f1;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
			<strong style="font-size:13px;"><?php esc_html_e( 'Super Page Cache — Recommended Settings', 'spc-prefetch' ); ?></strong>
			<?php if ( $spc_pro ): ?>
				<span style="font-size:12px;color:#2271b1;background:#f0f6fc;border:1px solid #c3d9f2;border-radius:3px;padding:1px 8px;"><?php esc_html_e( 'Pro detected', 'spc-prefetch' ); ?></span>
			<?php elseif ( $spc_installed ): ?>
				<span style="font-size:12px;color:#00a32a;background:#f0f9ee;border:1px solid #a7dfa0;border-radius:3px;padding:1px 8px;"><?php esc_html_e( 'Free detected', 'spc-prefetch' ); ?></span>
			<?php else: ?>
				<span style="font-size:12px;color:#787c82;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;padding:1px 8px;"><?php esc_html_e( 'Not detected', 'spc-prefetch' ); ?></span>
			<?php endif; ?>
		</div>
		<?php if ( ! $spc_installed && ! $spc_pro ): ?>
		<div style="padding:12px 16px;">
			<p style="margin:0;font-size:13px;color:#787c82;"><?php esc_html_e( 'Super Page Cache is not detected. This table shows what settings to use if you install it alongside this plugin.', 'spc-prefetch' ); ?></p>
		</div>
		<?php elseif ( $spc_installed && ! $spc_pro ): ?>
		<div style="padding:10px 16px;background:#f0f9ee;border-bottom:1px solid #d8f0d5;">
			<p style="margin:0;font-size:13px;color:#1a6b22;"><?php esc_html_e( 'Using Super Page Cache Free. The three rows marked Pro only do not apply to your version — they are greyed out below. The Prefetch URLs on hover setting does apply to both Free and Pro.', 'spc-prefetch' ); ?></p>
		</div>
		<?php endif; ?>
		<div style="padding:0 16px 16px;">
			<p style="font-size:13px;color:#50575e;margin-bottom:10px;"><?php esc_html_e( 'Configure Super Page Cache with these settings for best results alongside this plugin. The Status column reflects your live SPC configuration.', 'spc-prefetch' ); ?></p>
			<table style="width:100%;border-collapse:collapse;font-size:13px;">
				<thead>
					<tr style="border-bottom:2px solid #f0f0f1;">
						<th style="text-align:left;padding:7px 10px;width:26%;"><?php esc_html_e( 'Setting', 'spc-prefetch' ); ?></th>
						<th style="text-align:left;padding:7px 10px;width:14%;"><?php esc_html_e( 'Recommended', 'spc-prefetch' ); ?></th>
						<th style="text-align:left;padding:7px 10px;width:11%;"><?php esc_html_e( 'Status', 'spc-prefetch' ); ?></th>
						<th style="text-align:left;padding:7px 10px;"><?php esc_html_e( 'Why', 'spc-prefetch' ); ?></th>
					</tr>
				</thead>
				<tbody>

					<?php /* Row 1 — Prefetch on hover (Free + Pro) */ ?>
					<tr style="border-bottom:1px solid #f0f0f1;">
						<td style="padding:8px 10px;font-weight:500;">
							<?php esc_html_e( 'Prefetch URLs on hover', 'spc-prefetch' ); ?>
						</td>
						<td style="padding:8px 10px;">
							<span class="spc-badge spc-badge-off">OFF</span>
						</td>
						<td style="padding:8px 10px;">
							<?php if ( $spc_status['hover_prefetch'] === null ): ?>
								<span class="spc-badge spc-badge-na">N/A</span>
							<?php elseif ( $spc_status['hover_prefetch'] ): ?>
								<span class="spc-badge spc-badge-warn">ON</span>
							<?php else: ?>
								<span class="spc-badge spc-badge-ok">OFF</span>
							<?php endif; ?>
						</td>
						<td style="padding:8px 10px;color:#50575e;"><?php esc_html_e( 'Our plugin handles this better with Speculation Rules + prerender. If left ON, our plugin auto-detects it and defers to SPC, but you lose Speculation Rules and prerender entirely.', 'spc-prefetch' ); ?></td>
					</tr>

					<?php /* Row 2 — Defer JS (Pro only) */ ?>
					<tr style="border-bottom:1px solid #f0f0f1;<?php echo $spc_status['defer_js'] === 'free' ? 'opacity:.45;' : ''; ?>">
						<td style="padding:8px 10px;font-weight:500;">
							<?php esc_html_e( 'Defer JS', 'spc-prefetch' ); ?>
							<span style="font-size:11px;color:#787c82;font-weight:400;"> <?php esc_html_e( '(Pro only)', 'spc-prefetch' ); ?></span>
						</td>
						<td style="padding:8px 10px;">
							<span class="spc-badge spc-badge-ok"><?php esc_html_e( 'Either', 'spc-prefetch' ); ?></span>
						</td>
						<td style="padding:8px 10px;">
							<?php if ( $spc_status['defer_js'] === null ): ?>
								<span class="spc-badge spc-badge-na">N/A</span>
							<?php elseif ( $spc_status['defer_js'] === 'free' ): ?>
								<span class="spc-badge spc-badge-na"><?php esc_html_e( 'Free', 'spc-prefetch' ); ?></span>
							<?php else: ?>
								<span class="spc-badge spc-badge-ok">OK</span>
							<?php endif; ?>
						</td>
						<td style="padding:8px 10px;color:#50575e;">
							<?php if ( $spc_status['defer_js'] === 'free' ): ?>
								<?php esc_html_e( 'Not available in Super Page Cache Free — no action needed.', 'spc-prefetch' ); ?>
							<?php else: ?>
								<?php esc_html_e( 'Explicitly skips type="module" scripts, so it will not touch ours. We also hook spc_defer_script as a belt-and-suspenders backup.', 'spc-prefetch' ); ?>
							<?php endif; ?>
						</td>
					</tr>

					<?php /* Row 3 — Delay JS (Pro only) */ ?>
					<tr style="border-bottom:1px solid #f0f0f1;<?php echo $spc_status['delay_js'] === 'free' ? 'opacity:.45;' : ''; ?>">
						<td style="padding:8px 10px;font-weight:500;">
							<?php esc_html_e( 'Delay JS', 'spc-prefetch' ); ?>
							<span style="font-size:11px;color:#787c82;font-weight:400;"> <?php esc_html_e( '(Pro only)', 'spc-prefetch' ); ?></span>
						</td>
						<td style="padding:8px 10px;">
							<span class="spc-badge spc-badge-off">OFF</span>
						</td>
						<td style="padding:8px 10px;">
							<?php if ( $spc_status['delay_js'] === null ): ?>
								<span class="spc-badge spc-badge-na">N/A</span>
							<?php elseif ( $spc_status['delay_js'] === 'free' ): ?>
								<span class="spc-badge spc-badge-na"><?php esc_html_e( 'Free', 'spc-prefetch' ); ?></span>
							<?php elseif ( $spc_status['delay_js'] ): ?>
								<span class="spc-badge spc-badge-warn">ON</span>
							<?php else: ?>
								<span class="spc-badge spc-badge-ok">OFF</span>
							<?php endif; ?>
						</td>
						<td style="padding:8px 10px;color:#50575e;">
							<?php if ( $spc_status['delay_js'] === 'free' ): ?>
								<?php esc_html_e( 'Not available in Super Page Cache Free — no action needed.', 'spc-prefetch' ); ?>
							<?php elseif ( $spc_status['delay_js'] ): ?>
								<?php esc_html_e( 'Delay JS is ON. Does NOT skip module scripts — could break instant.page and view-transitions. We auto-inject our paths as exclusions, but OFF is cleaner.', 'spc-prefetch' ); ?>
							<?php else: ?>
								<?php esc_html_e( 'Does NOT skip type="module" scripts. Could break instant.page and view-transitions if turned on. We auto-exclude our scripts via option filter as protection.', 'spc-prefetch' ); ?>
							<?php endif; ?>
						</td>
					</tr>

					<?php /* Row 4 — Unused CSS (Pro only) */ ?>
					<tr style="<?php echo $spc_status['unused_css'] === 'free' ? 'opacity:.45;' : ''; ?>">
						<td style="padding:8px 10px;font-weight:500;">
							<?php esc_html_e( 'Remove Unused CSS', 'spc-prefetch' ); ?>
							<span style="font-size:11px;color:#787c82;font-weight:400;"> <?php esc_html_e( '(Pro only)', 'spc-prefetch' ); ?></span>
						</td>
						<td style="padding:8px 10px;">
							<span class="spc-badge spc-badge-ok"><?php esc_html_e( 'Fine', 'spc-prefetch' ); ?></span>
						</td>
						<td style="padding:8px 10px;">
							<?php if ( $spc_status['unused_css'] === null ): ?>
								<span class="spc-badge spc-badge-na">N/A</span>
							<?php elseif ( $spc_status['unused_css'] === 'free' ): ?>
								<span class="spc-badge spc-badge-na"><?php esc_html_e( 'Free', 'spc-prefetch' ); ?></span>
							<?php else: ?>
								<span class="spc-badge spc-badge-ok">OK</span>
							<?php endif; ?>
						</td>
						<td style="padding:8px 10px;color:#50575e;">
							<?php if ( $spc_status['unused_css'] === 'free' ): ?>
								<?php esc_html_e( 'Not available in Super Page Cache Free — no action needed.', 'spc-prefetch' ); ?>
							<?php else: ?>
								<?php esc_html_e( 'Operates on cached HTML only. Does not touch the wp_head script enqueue pipeline — no effect on our scripts.', 'spc-prefetch' ); ?>
							<?php endif; ?>
						</td>
					</tr>

				</tbody>
			</table>
		</div>
	</div>

	<?php /* WooCommerce / WPML / Polylang detections */ ?>
	<?php if ( $wc_active ): ?>
	<div class="notice notice-success inline" style="margin:0">
		<p>
			<strong><?php esc_html_e( 'WooCommerce detected.', 'spc-prefetch' ); ?></strong>
			<?php esc_html_e( 'Cart, checkout, my-account, and order pages are automatically excluded from prefetch and prerender.', 'spc-prefetch' ); ?>
			<?php if ( has_filter( 'wpml_active_languages' ) ): ?>
				<?php esc_html_e( 'WPML detected — all translated page slugs included automatically.', 'spc-prefetch' ); ?>
			<?php elseif ( function_exists( 'pll_languages_list' ) ): ?>
				<?php esc_html_e( 'Polylang detected — all translated page slugs included automatically.', 'spc-prefetch' ); ?>
			<?php endif; ?>
		</p>
	</div>
	<?php endif; ?>

	<?php
}

// ---------------------------------------------------------------------------
// Tab: Settings
// ---------------------------------------------------------------------------

function spc_prefetch_tab_settings() {
	$settings   = spc_prefetch_get_settings();
	$spc_active = spc_prefetch_spc_owns_prefetch();
	$opt        = SPC_PREFETCH_OPTION;
	// We use our own AJAX save so we don't need settings_fields nonce here,
	// but we still include it so the native form fallback also works.
	?>
	<form id="spc-settings-form" method="post" action="options.php">
		<?php settings_fields( 'spc_prefetch_group' ); ?>

		<h2 class="title" style="margin-top:4px"><?php esc_html_e( 'General', 'spc-prefetch' ); ?></h2>
		<table class="form-table" role="presentation">

			<tr>
				<th><?php esc_html_e( 'Enable', 'spc-prefetch' ); ?></th>
				<td><label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="1" <?php checked( $settings['enabled'] ); ?>>
					<?php esc_html_e( 'Activate SPC Prefetch Connector', 'spc-prefetch' ); ?>
				</label></td>
			</tr>

			<tr>
				<th><label for="npc_delay"><?php esc_html_e( 'Hover delay (ms)', 'spc-prefetch' ); ?></label></th>
				<td>
					<input type="number" id="npc_delay" name="<?php echo esc_attr( $opt ); ?>[hover_delay_ms]"
						value="<?php echo esc_attr( $settings['hover_delay_ms'] ); ?>" min="0" max="1000" step="5" class="small-text">
					<p class="description"><?php esc_html_e( 'instant.page fallback only (Firefox / Safari). Default 65 ms. Speculation Rules uses the browser\'s own hover heuristic.', 'spc-prefetch' ); ?></p>
				</td>
			</tr>

			<tr>
				<th><?php esc_html_e( 'Mobile strategy', 'spc-prefetch' ); ?></th>
				<td><fieldset>
					<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[mobile_strategy]" value="touchstart" <?php checked( $settings['mobile_strategy'], 'touchstart' ); ?>>
						<?php esc_html_e( 'Touchstart (recommended) — fires on finger-touch before release', 'spc-prefetch' ); ?></label><br>
					<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[mobile_strategy]" value="viewport" <?php checked( $settings['mobile_strategy'], 'viewport' ); ?>>
						<?php esc_html_e( 'Viewport — prefetch links as they scroll into view (higher bandwidth)', 'spc-prefetch' ); ?></label><br>
					<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[mobile_strategy]" value="disabled" <?php checked( $settings['mobile_strategy'], 'disabled' ); ?>>
						<?php esc_html_e( 'Desktop hover only — no mobile prefetch from instant.page', 'spc-prefetch' ); ?></label>
				</fieldset></td>
			</tr>

			<tr>
				<th><?php esc_html_e( 'Allow query strings', 'spc-prefetch' ); ?></th>
				<td><label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[allow_query_strings]" value="1" <?php checked( $settings['allow_query_strings'] ); ?>>
					<?php esc_html_e( 'Prefetch URLs that contain query strings. Off by default — query-string URLs are usually uncached.', 'spc-prefetch' ); ?>
				</label></td>
			</tr>

			<tr>
				<th><?php esc_html_e( 'Allow external links', 'spc-prefetch' ); ?></th>
				<td><label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[allow_external]" value="1" <?php checked( $settings['allow_external'] ); ?>>
					<?php esc_html_e( 'Prefetch cross-origin links (Chromium only).', 'spc-prefetch' ); ?>
				</label></td>
			</tr>

			<tr>
				<th><label for="npc_excluded"><?php esc_html_e( 'Excluded paths', 'spc-prefetch' ); ?></label></th>
				<td>
					<textarea id="npc_excluded" name="<?php echo esc_attr( $opt ); ?>[excluded_paths]" rows="5" class="large-text code"><?php echo esc_textarea( $settings['excluded_paths'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One glob per line, e.g. /cart*, /members*. wp-admin, wp-login, and WooCommerce pages are always excluded automatically.', 'spc-prefetch' ); ?></p>
				</td>
			</tr>

		</table>

		<h2 class="title"><?php esc_html_e( 'Prerender', 'spc-prefetch' ); ?> <span style="font-size:13px;font-weight:400;color:#787c82;">Chrome 108+</span></h2>
		<p style="color:#50575e;max-width:600px;"><?php esc_html_e( 'Fully executes the target page in a hidden renderer. When the user clicks, Chrome activates it instantly. Use conservatively: prerender fires analytics and page JS on the target page.', 'spc-prefetch' ); ?></p>
		<table class="form-table" role="presentation">

			<tr>
				<th><?php esc_html_e( 'Enable prerender', 'spc-prefetch' ); ?></th>
				<td><label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[prerender_enabled]" value="1" <?php checked( $settings['prerender_enabled'] ); ?>>
					<?php esc_html_e( 'Add a prerender rule to the Speculation Rules block (Chrome only, other browsers ignore it)', 'spc-prefetch' ); ?>
				</label></td>
			</tr>

			<tr>
				<th><?php esc_html_e( 'Eagerness', 'spc-prefetch' ); ?></th>
				<td><fieldset>
					<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[prerender_eagerness]" value="conservative" <?php checked( $settings['prerender_eagerness'], 'conservative' ); ?>>
						<strong><?php esc_html_e( 'Conservative (recommended)', 'spc-prefetch' ); ?></strong>
						&mdash; <?php esc_html_e( 'mousedown / touchstart only. No accidental pageview inflation.', 'spc-prefetch' ); ?></label><br>
					<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[prerender_eagerness]" value="moderate" <?php checked( $settings['prerender_eagerness'], 'moderate' ); ?>>
						<strong><?php esc_html_e( 'Moderate', 'spc-prefetch' ); ?></strong>
						&mdash; <?php esc_html_e( 'hover ~200 ms. Analytics fire on the target page before the user clicks. Only use if you accept that.', 'spc-prefetch' ); ?></label>
				</fieldset></td>
			</tr>

			<tr>
				<th><label for="npc_defer_scripts"><?php esc_html_e( 'Extra script suppression', 'spc-prefetch' ); ?></label></th>
				<td>

					<p style="margin-top:0;color:#50575e;max-width:600px;"><?php esc_html_e( 'GTM (dataLayer), GA4 (gtag), and Facebook Pixel (fbq) are guarded automatically — you do not need to add them here. Use this field for any other third-party script that should not run while a page is being prerendered in the background.', 'spc-prefetch' ); ?></p>

					<p style="color:#50575e;max-width:600px;"><?php esc_html_e( 'Typical candidates are session-recording tools (Hotjar, Microsoft Clarity), live chat widgets (Intercom, Crisp, Tidio), and A/B testing scripts. If left unblocked, these scripts may start a recording session, open a chat socket, or fire an experiment impression before the user has actually navigated to the page.', 'spc-prefetch' ); ?></p>

					<input type="text" id="npc_defer_scripts"
						name="<?php echo esc_attr( $opt ); ?>[prerender_defer_scripts]"
						value="<?php echo esc_attr( $settings['prerender_defer_scripts'] ); ?>"
						class="large-text code"
						placeholder="e.g. hotjar, clarity-script, intercom, crisp-js">

					<p class="description" style="margin-top:8px;">
						<?php esc_html_e( 'Enter the WordPress script handle for each script, separated by commas. The handle is the first argument passed to wp_enqueue_script() for that script.', 'spc-prefetch' ); ?>
					</p>

					<div style="margin-top:12px;padding:12px 14px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;font-size:12px;max-width:600px;">
						<strong style="display:block;margin-bottom:8px;font-size:13px;"><?php esc_html_e( 'How to find a script handle', 'spc-prefetch' ); ?></strong>
						<p style="margin:0 0 8px;"><?php esc_html_e( 'The easiest way is to view the page source and look at the id attribute on the script tag. WordPress sets it to {handle}-js:', 'spc-prefetch' ); ?></p>
						<code style="display:block;background:#fff;border:1px solid #dcdcde;padding:6px 10px;border-radius:2px;word-break:break-all;">&lt;script id="<strong>hotjar</strong>-js" src="..."&gt;</code>
						<p style="margin:8px 0 6px;"><?php esc_html_e( 'In that example the handle is hotjar. Common handles for popular tools:', 'spc-prefetch' ); ?></p>
						<table style="border-collapse:collapse;width:100%;">
							<tr>
								<td style="padding:3px 10px 3px 0;color:#50575e;width:35%;"><?php esc_html_e( 'Hotjar', 'spc-prefetch' ); ?></td>
								<td><code>hotjar</code></td>
							</tr>
							<tr>
								<td style="padding:3px 10px 3px 0;color:#50575e;"><?php esc_html_e( 'Microsoft Clarity', 'spc-prefetch' ); ?></td>
								<td><code>clarity-script</code> <?php esc_html_e( 'or', 'spc-prefetch' ); ?> <code>microsoft-clarity</code></td>
							</tr>
							<tr>
								<td style="padding:3px 10px 3px 0;color:#50575e;"><?php esc_html_e( 'Intercom', 'spc-prefetch' ); ?></td>
								<td><code>intercom</code></td>
							</tr>
							<tr>
								<td style="padding:3px 10px 3px 0;color:#50575e;"><?php esc_html_e( 'Crisp', 'spc-prefetch' ); ?></td>
								<td><code>crisp-js</code></td>
							</tr>
							<tr>
								<td style="padding:3px 10px 3px 0;color:#50575e;"><?php esc_html_e( 'Tidio', 'spc-prefetch' ); ?></td>
								<td><code>tidio-chat</code></td>
							</tr>
						</table>
						<p style="margin:8px 0 0;color:#787c82;"><?php esc_html_e( 'Note: this uses the Sec-Purpose: prerender HTTP header that Chrome sends on prerender requests. Scripts are only suppressed during the background prerender — they load normally when the user actually navigates to the page.', 'spc-prefetch' ); ?></p>
					</div>

				</td>
			</tr>

		</table>

		<h2 class="title"><?php esc_html_e( 'View Transitions', 'spc-prefetch' ); ?> <span style="font-size:13px;font-weight:400;color:#787c82;">Chrome 111+ / Safari 18+</span></h2>
		<p style="color:#50575e;max-width:600px;"><?php esc_html_e( 'Wraps navigation clicks in a smooth animation. When prerender is ready the transition is truly instant. On other browsers the animation plays during normal load, masking the wait.', 'spc-prefetch' ); ?></p>
		<table class="form-table" role="presentation">

			<tr>
				<th><?php esc_html_e( 'Enable', 'spc-prefetch' ); ?></th>
				<td><label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[view_transitions]" value="1" <?php checked( $settings['view_transitions'] ); ?>>
					<?php esc_html_e( 'Intercept navigation clicks and wrap in document.startViewTransition()', 'spc-prefetch' ); ?>
				</label></td>
			</tr>

			<tr>
				<th><?php esc_html_e( 'Animation', 'spc-prefetch' ); ?></th>
				<td><fieldset>
					<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[transition_style]" value="fade" <?php checked( $settings['transition_style'], 'fade' ); ?>>
						<?php esc_html_e( 'Fade (160 ms crossfade)', 'spc-prefetch' ); ?></label><br>
					<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[transition_style]" value="slide" <?php checked( $settings['transition_style'], 'slide' ); ?>>
						<?php esc_html_e( 'Slide (old page left, new page from right)', 'spc-prefetch' ); ?></label><br>
					<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[transition_style]" value="none" <?php checked( $settings['transition_style'], 'none' ); ?>>
						<?php esc_html_e( 'None (instant cut, no animation)', 'spc-prefetch' ); ?></label>
				</fieldset></td>
			</tr>

		</table>

		<h2 class="title"><?php esc_html_e( 'Preconnect Hints', 'spc-prefetch' ); ?></h2>
		<p style="color:#50575e;max-width:600px;"><?php esc_html_e( 'Completes the DNS + TLS handshake for third-party origins before they are needed. No side effects — every browser supports this.', 'spc-prefetch' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="npc_preconnect"><?php esc_html_e( 'Origins', 'spc-prefetch' ); ?></label></th>
				<td>
					<textarea id="npc_preconnect" name="<?php echo esc_attr( $opt ); ?>[preconnect_origins]" rows="4" class="large-text code"><?php echo esc_textarea( $settings['preconnect_origins'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One origin per line, e.g. https://fonts.googleapis.com', 'spc-prefetch' ); ?></p>
				</td>
			</tr>
		</table>

		<?php if ( $spc_active ): ?>
		<h2 class="title"><?php esc_html_e( 'Super Page Cache', 'spc-prefetch' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><?php esc_html_e( 'Override SPC hover-prefetch', 'spc-prefetch' ); ?></th>
				<td><label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[override_spc]" value="1" <?php checked( $settings['override_spc'] ); ?>>
					<?php esc_html_e( 'Force instant.page to run even though SPC\'s hover-prefetch is enabled.', 'spc-prefetch' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Speculation Rules and View Transitions always run regardless of this setting.', 'spc-prefetch' ); ?></p>
				</td>
			</tr>
		</table>
		<?php endif; ?>

		<?php submit_button( __( 'Save Changes', 'spc-prefetch' ) ); ?>

		<div style="margin-top:0;padding:12px 16px;background:#f0f6fc;border-left:3px solid #72aee6;font-size:13px;">
			<strong><?php esc_html_e( 'Per-link opt-outs:', 'spc-prefetch' ); ?></strong>
			<code style="margin-left:8px">data-no-instant</code> <?php esc_html_e( '— skip instant.page', 'spc-prefetch' ); ?>
			&nbsp;&bull;&nbsp;
			<code>data-no-transition</code> <?php esc_html_e( '— skip View Transitions', 'spc-prefetch' ); ?>
		</div>

	</form>
	<?php
}

// ---------------------------------------------------------------------------
// Tab: About
// ---------------------------------------------------------------------------

function spc_prefetch_tab_about() {
	?>
	<div>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:3px;padding:24px 28px;margin-bottom:16px;">
			<h2 style="margin-top:0;font-size:18px;"><?php esc_html_e( 'SPC Prefetch Connector', 'spc-prefetch' ); ?></h2>
			<p style="color:#50575e;font-size:14px;line-height:1.7;margin-top:0;"><?php esc_html_e( 'Makes WordPress feel like a static site by layering three browser-native performance technologies: the Speculation Rules API (Chrome 109+), instant.page fallback (Firefox / Safari), and the View Transitions API for smooth page animations.', 'spc-prefetch' ); ?></p>
			<p style="color:#50575e;font-size:14px;line-height:1.7;"><?php esc_html_e( 'Designed to work alongside Super Page Cache Free and Pro. Fully compatible with WooCommerce, WPML, and Polylang — sensitive pages are excluded automatically without any configuration.', 'spc-prefetch' ); ?></p>
			<hr style="border:none;border-top:1px solid #f0f0f1;margin:16px 0;">
			<p style="margin:0;font-size:13px;color:#50575e;">
				<strong><?php esc_html_e( 'License:', 'spc-prefetch' ); ?></strong> GPL-2.0+
				&nbsp;&bull;&nbsp;
				<strong><?php esc_html_e( 'Free for everyone, forever.', 'spc-prefetch' ); ?></strong>
			</p>
		</div>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:3px;padding:24px 28px;margin-bottom:16px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'How it works', 'spc-prefetch' ); ?></h3>
			<table style="width:100%;border-collapse:collapse;font-size:13px;">
				<thead>
					<tr style="border-bottom:2px solid #f0f0f1;">
						<th style="text-align:left;padding:6px 10px;width:28%;"><?php esc_html_e( 'Layer', 'spc-prefetch' ); ?></th>
						<th style="text-align:left;padding:6px 10px;width:24%;"><?php esc_html_e( 'Browser support', 'spc-prefetch' ); ?></th>
						<th style="text-align:left;padding:6px 10px;"><?php esc_html_e( 'What it does', 'spc-prefetch' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr style="border-bottom:1px solid #f0f0f1;">
						<td style="padding:7px 10px;font-weight:500;"><?php esc_html_e( 'Speculation Rules: prefetch', 'spc-prefetch' ); ?></td>
						<td style="padding:7px 10px;color:#787c82;">Chrome 109+</td>
						<td style="padding:7px 10px;color:#50575e;"><?php esc_html_e( 'Downloads the HTML of a page on hover. Zero JS overhead — browser handles it natively.', 'spc-prefetch' ); ?></td>
					</tr>
					<tr style="border-bottom:1px solid #f0f0f1;">
						<td style="padding:7px 10px;font-weight:500;"><?php esc_html_e( 'Speculation Rules: prerender', 'spc-prefetch' ); ?></td>
						<td style="padding:7px 10px;color:#787c82;">Chrome 108+</td>
						<td style="padding:7px 10px;color:#50575e;"><?php esc_html_e( 'Fully executes the page in a hidden renderer on mousedown. Click = instant activation.', 'spc-prefetch' ); ?></td>
					</tr>
					<tr style="border-bottom:1px solid #f0f0f1;">
						<td style="padding:7px 10px;font-weight:500;"><?php esc_html_e( 'instant.page fallback', 'spc-prefetch' ); ?></td>
						<td style="padding:7px 10px;color:#787c82;"><?php esc_html_e( 'Firefox, Safari, Edge', 'spc-prefetch' ); ?></td>
						<td style="padding:7px 10px;color:#50575e;"><?php esc_html_e( 'Prefetches HTML via link rel=prefetch on hover (65 ms delay). Touchstart on mobile.', 'spc-prefetch' ); ?></td>
					</tr>
					<tr style="border-bottom:1px solid #f0f0f1;">
						<td style="padding:7px 10px;font-weight:500;"><?php esc_html_e( 'View Transitions', 'spc-prefetch' ); ?></td>
						<td style="padding:7px 10px;color:#787c82;">Chrome 111+, Safari 18+</td>
						<td style="padding:7px 10px;color:#50575e;"><?php esc_html_e( 'Wraps navigation in a 160 ms fade or slide. When prerender is ready the transition is instant.', 'spc-prefetch' ); ?></td>
					</tr>
					<tr>
						<td style="padding:7px 10px;font-weight:500;"><?php esc_html_e( 'Analytics guard', 'spc-prefetch' ); ?></td>
						<td style="padding:7px 10px;color:#787c82;"><?php esc_html_e( 'All browsers', 'spc-prefetch' ); ?></td>
						<td style="padding:7px 10px;color:#50575e;"><?php esc_html_e( 'Queues GTM / GA4 / Facebook Pixel during prerender and replays on real activation. Prevents inflated pageview counts.', 'spc-prefetch' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div style="background:#fff;border:1px solid #dcdcde;border-radius:3px;padding:24px 28px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Built by Nahnu Plugins', 'spc-prefetch' ); ?></h3>
			<p style="color:#50575e;font-size:13px;line-height:1.7;margin-top:0;"><?php esc_html_e( 'Focused, no-bloat WordPress plugins for developers and site owners who care about performance and clean code.', 'spc-prefetch' ); ?></p>
			<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-top:4px;">
				<a href="https://www.nahnuplugins.com" target="_blank" rel="noopener noreferrer"
				   style="display:inline-flex;align-items:center;gap:6px;font-size:13px;text-decoration:none;color:#2271b1;">
					&#x1F310; www.nahnuplugins.com
				</a>
				<a href="https://github.com/jaimealnassim/SPC-Prefetch-Connector" target="_blank" rel="noopener noreferrer"
				   style="display:inline-flex;align-items:center;gap:6px;font-size:13px;text-decoration:none;color:#24292f;">
					<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
						<path d="M12 0C5.37 0 0 5.37 0 12c0 5.3 3.438 9.8 8.205 11.387.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61-.546-1.387-1.333-1.756-1.333-1.756-1.09-.745.083-.729.083-.729 1.205.084 1.84 1.237 1.84 1.237 1.07 1.834 2.807 1.304 3.492.997.108-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.31.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0 1 12 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222 0 1.606-.015 2.898-.015 3.293 0 .322.216.694.825.576C20.565 21.796 24 17.298 24 12c0-6.63-5.37-12-12-12z"/>
					</svg>
					github.com/jaimealnassim/SPC-Prefetch-Connector
				</a>
			</div>
		</div>

	</div>
	<?php
}
