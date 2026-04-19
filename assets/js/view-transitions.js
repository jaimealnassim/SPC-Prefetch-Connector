/**
 * Nahnu Prefetch Connector - View Transitions bridge
 *
 * Wraps same-origin navigation in document.startViewTransition() when
 * the browser supports it. Works with both prefetch and prerender —
 * if a prerender is ready the transition activates it instantly; if
 * not, the animation plays during the normal navigation latency,
 * masking the wait.
 *
 * This script is loaded as type="module" so it only runs in browsers
 * that support ES modules. Those browsers overwhelmingly also support
 * View Transitions (Chrome 111+, Safari 18+). The feature-detect
 * inside is a belt-and-suspenders guard.
 *
 * Excluded links:
 *   - data-no-instant  (instant.page opt-out, we honour it too)
 *   - data-no-transition
 *   - target="_blank" or other non-self targets
 *   - modifier keys held (Ctrl/Cmd/Shift/Alt — open in new tab etc.)
 *   - Non http/https protocols
 *   - Cross-origin links
 */

if ( 'startViewTransition' in document ) {

	document.addEventListener( 'click', function ( e ) {
		// Walk up from the clicked element to find an <a>.
		const anchor = e.target.closest( 'a' );
		if ( ! anchor ) return;

		// Skip if any modifier key is held.
		if ( e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) return;

		// Only left-click (button 0).
		if ( e.button !== 0 ) return;

		// Honour opt-outs.
		if ( 'noInstant' in anchor.dataset ) return;
		if ( 'noTransition' in anchor.dataset ) return;

		// Must be a same-origin http/https link with no explicit non-self target.
		const target = anchor.getAttribute( 'target' );
		if ( target && target !== '_self' ) return;

		if ( ! [ 'http:', 'https:' ].includes( anchor.protocol ) ) return;
		if ( anchor.origin !== location.origin ) return;

		// Ignore same-page hash jumps.
		if (
			anchor.hash &&
			anchor.pathname + anchor.search === location.pathname + location.search
		) return;

		// Don't intercept download links.
		if ( anchor.hasAttribute( 'download' ) ) return;

		e.preventDefault();

		document.startViewTransition( () => {
			// If a prerender is queued for this URL, Chrome activates it here
			// instead of doing a real navigation — the page appears instantly.
			location.href = anchor.href;
		} );

	}, { capture: true, passive: false } );

}
