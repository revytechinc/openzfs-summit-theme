/**
 * Editor side of openzfs-summit/theme-toggle. No build step: plain wp.* globals.
 * The block has no settings; the editor shows a static preview of the button.
 */
( function ( wp ) {
	const el = wp.element.createElement;
	wp.blocks.registerBlockType( 'openzfs-summit/theme-toggle', {
		edit() {
			const props = wp.blockEditor.useBlockProps( { className: 'ozs-theme-toggle', role: 'img', 'aria-label': 'Light/dark toggle' } );
			return el(
				'span',
				props,
				el(
					'svg',
					{ viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, width: 18, height: 18, 'aria-hidden': true, focusable: 'false' },
					el( 'path', { d: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z' } )
				)
			);
		},
		save() {
			return null;
		},
	} );
} )( window.wp );
