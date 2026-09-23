/**
 * Light/dark toggle. The <head> boot script has already applied a stored
 * choice; this handles clicks and keeps the button and the browser chrome
 * colour in step with what is on screen.
 *
 * The mode in force is read from the page first (data-theme), then from
 * storage, then from the system setting -- so the toggle still works for the
 * current page when storage is blocked.
 */
( function () {
	const KEY = 'ozs-theme';
	const CHROME = { light: '#f8fafc', dark: '#0b0f14' };
	const root = document.documentElement;
	const media = window.matchMedia?.( '(prefers-color-scheme: dark)' ) ?? null;

	function valid( v ) {
		return v === 'dark' || v === 'light' ? v : null;
	}

	function stored() {
		try {
			return valid( localStorage.getItem( KEY ) );
		} catch ( e ) {
			// Storage blocked (private mode, policy): behave as "no stored choice".
			return null;
		}
	}

	function effective() {
		return valid( root.dataset.theme ) || stored() || ( media?.matches ? 'dark' : 'light' );
	}

	function sync() {
		const mode = effective();
		document.querySelectorAll( '[data-ozs-theme-toggle]' ).forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', mode === 'dark' ? 'true' : 'false' );
		} );
		// Mobile browser chrome follows the mode actually shown, not just the system.
		document.querySelectorAll( 'meta[name="theme-color"]' ).forEach( function ( m ) {
			m.setAttribute( 'content', CHROME[ mode ] );
		} );
	}

	function set( next ) {
		root.dataset.theme = next;
		try {
			localStorage.setItem( KEY, next );
		} catch ( e ) {
			// Storage blocked: data-theme above still carries the choice for this page.
		}
		sync();
	}

	document.addEventListener( 'click', function ( ev ) {
		const b = ev.target.closest?.( '[data-ozs-theme-toggle]' );
		if ( b ) {
			set( effective() === 'dark' ? 'light' : 'dark' );
		}
	} );

	media?.addEventListener?.( 'change', sync );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', sync );
	} else {
		sync();
	}
} )();
