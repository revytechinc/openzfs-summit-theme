<?php
/**
 * Rebuild the Home and Sponsors pages from this theme's block patterns and
 * the sponsor logos already in the media library. The copy is the source
 * site's text, verbatim; only the structure changes.
 *
 * Run inside the jail, as the web user, with this theme active:
 *   wp --path=/usr/local/www/zfssummit eval-file compose-pages.php
 *
 * Idempotent: it overwrites the content of the two pages each time.
 *
 * @package openzfs-summit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$registry = WP_Block_Patterns_Registry::get_instance();

$pattern = static function ( string $slug ) use ( $registry ): string {
	$p = $registry->get_registered( 'openzfs-summit/' . $slug );
	if ( ! $p ) {
		WP_CLI::error( "pattern openzfs-summit/$slug is not registered - is the theme active?" );
	}
	return $p['content'];
};

/** Find an attachment by the source file's basename (without extension). */
$attachment = static function ( string $basename ): ?WP_Post {
	$q = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'no_found_rows'  => true,
		'meta_query'     => array(
			array(
				'key'     => '_wp_attached_file',
				'value'   => '/' . $basename . '.',
				'compare' => 'LIKE',
			),
		),
	) );
	if ( ! $q->posts ) {
		$q = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'name'           => sanitize_title( $basename ),
		) );
	}
	return $q->posts[0] ?? null;
};

$tiers = array(
	'diamond' => array( 'Diamond Sponsor', array( 'Delphix2026-650px' => 'Perforce Delphix' ) ),
	'gold'    => array( 'Gold Sponsor', array( 'AWS2026-650px' => 'AWS' ) ),
	'silver'  => array( 'Silver Sponsor', array( 'TrueNAS2026-650px' => 'TrueNAS' ) ),
	'bronze'  => array(
		'Bronze Sponsors',
		array(
			'Bacula2026-650px'      => 'Bacula',
			'DigitalGlue2026-650px' => 'DigitalGlue',
			'Klara2026-650px'       => 'Klara',
			'OSNexus2026-650px'     => 'OSNexus',
			'Prominic2026-650px'    => 'Prominic',
			'VDURA2026-650px'       => 'VDURA',
		),
	),
);

$missing = array();
$tier_markup = '';
foreach ( $tiers as $slug => list( $label, $logos ) ) {
	$figs = '';
	foreach ( $logos as $file => $alt ) {
		$a = $attachment( $file );
		if ( ! $a ) {
			$missing[] = $file;
			continue;
		}
		$id   = (int) $a->ID;
		$url  = esc_url( wp_get_attachment_url( $id ) );
		$alt  = esc_attr( $alt );
		$figs .= "\n\t\t<!-- wp:image {\"id\":$id,\"sizeSlug\":\"full\",\"linkDestination\":\"none\"} -->\n"
			. "\t\t<figure class=\"wp-block-image size-full\"><img src=\"$url\" alt=\"$alt\" class=\"wp-image-$id\"/></figure>\n"
			. "\t\t<!-- /wp:image -->";
	}
	$label = esc_html( $label );
	$tier_markup .= <<<HTML

<!-- wp:group {"className":"ozs-tier ozs-tier-$slug","layout":{"type":"default"}} -->
<div class="wp-block-group ozs-tier ozs-tier-$slug">
	<!-- wp:heading {"level":3,"className":"ozs-tier-label"} -->
	<h3 class="wp-block-heading ozs-tier-label">$label</h3>
	<!-- /wp:heading -->
	<!-- wp:group {"className":"ozs-logos","layout":{"type":"default"}} -->
	<div class="wp-block-group ozs-logos">$figs
	</div>
	<!-- /wp:group -->
</div>
<!-- /wp:group -->
HTML;
}
// A missing logo must stop the run before anything is written: silently
// dropping a sponsor would publish a page that no longer matches the site.
if ( $missing ) {
	WP_CLI::error( 'sponsor logos not found in the media library: ' . implode( ', ', $missing ) . ' -- run the content import first; nothing was changed' );
}

$how_to = <<<'HTML'

<!-- wp:paragraph -->
<p>Please email <a href="mailto:editor@callfortesting.org">Michael Dexter &lt;editor@callfortesting.org&gt;</a> for details.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>OpenZFS is a member project of <a href="https://www.spi-inc.org">Software in the Public Interest</a>, a 501(c)(3) public-benefit non-profit organization, making your sponsorship tax-deductible. Individual and one-time sponsors are invited to <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&amp;hosted_button_id=X6KB4BFPRFAG6" target="_blank" rel="noopener nofollow">donate via PayPal.</a></p>
<!-- /wp:paragraph -->
HTML;

$sponsors_inner = $pattern( 'sponsors-intro' ) . $how_to . $tier_markup;

$sponsors_section = <<<HTML
<!-- wp:group {"align":"full","anchor":"sponsors","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained","contentSize":"960px","wideSize":"1200px"}} -->
<div class="wp-block-group alignfull" id="sponsors" style="padding-top:var(--wp--preset--spacing--60);padding-bottom:var(--wp--preset--spacing--60)">
$sponsors_inner
</div>
<!-- /wp:group -->
HTML;

$home = implode( "\n\n", array(
	$pattern( 'hero' ),
	$pattern( 'about' ),
	$pattern( 'summit-days' ),
	$sponsors_section,
) );

$update = static function ( string $slug, string $content, ?string $template = null ): void {
	$page = get_page_by_path( $slug, OBJECT, 'page' );
	if ( ! $page ) {
		WP_CLI::warning( "page '$slug' not found; skipped" );
		return;
	}
	$args = array(
		'ID'           => $page->ID,
		'post_content' => wp_slash( $content ),
	);
	$r = wp_update_post( $args, true );
	if ( is_wp_error( $r ) ) {
		WP_CLI::error( $r->get_error_message() );
	}
	if ( null !== $template ) {
		update_post_meta( $page->ID, '_wp_page_template', $template );
	}
	WP_CLI::log( "updated page '$slug' (#{$page->ID})" );
};

$update( 'home', $home );
$update( 'sample-page', $sponsors_section, 'page-wide' );

// Registration: frame the ticket product collection as ticket cards, under a
// visible title (its content carries no heading of its own).
$reg = get_page_by_path( 'registration', OBJECT, 'page' );
if ( $reg ) {
	update_post_meta( $reg->ID, '_wp_page_template', 'page-with-title' );

	// Add the ozs-tickets class through the block parser, never by editing
	// the serialized JSON: that keeps existing attributes (and any existing
	// className) intact. The collection may be nested inside a group.
	// Adds the class to one product-collection block; 'present' or 'added'.
	$add_class = static function ( array &$block ): string {
		$classes = preg_split( '/\s+/', trim( $block['attrs']['className'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
		if ( in_array( 'ozs-tickets', $classes, true ) ) {
			return 'present';
		}
		$classes[]                   = 'ozs-tickets';
		$block['attrs']['className'] = implode( ' ', $classes );
		// The saved wrapper markup carries the class too; first tag only.
		$re                 = '/class="wp-block-woocommerce-product-collection/';
		$rep                = 'class="wp-block-woocommerce-product-collection ozs-tickets';
		$block['innerHTML'] = preg_replace( $re, $rep, $block['innerHTML'], 1 );
		$chunks             = array_filter( $block['innerContent'], 'is_string' );
		foreach ( array_keys( $chunks ) as $i ) {
			if ( preg_match( $re, $block['innerContent'][ $i ] ) ) {
				$block['innerContent'][ $i ] = preg_replace( $re, $rep, $block['innerContent'][ $i ], 1 );
				break;
			}
		}
		return 'added';
	};

	// Depth-first search for the collection; 'none' when there is none.
	$mark = static function ( array &$blocks ) use ( &$mark, $add_class ): string {
		$state = 'none';
		foreach ( $blocks as &$block ) {
			if ( 'woocommerce/product-collection' === $block['blockName'] ) {
				$state = $add_class( $block );
			} elseif ( ! empty( $block['innerBlocks'] ) ) {
				$state = $mark( $block['innerBlocks'] );
			}
			if ( 'none' !== $state ) {
				break;
			}
		}
		unset( $block );
		return $state;
	};

	$blocks = parse_blocks( $reg->post_content );
	$state  = $mark( $blocks );
	if ( 'added' === $state ) {
		$r = wp_update_post(
			array(
				'ID'           => $reg->ID,
				'post_content' => wp_slash( serialize_blocks( $blocks ) ),
			),
			true
		);
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( 'registration: ' . $r->get_error_message() );
		}
		WP_CLI::log( "registration: product collection marked as ticket cards (#{$reg->ID})" );
	} elseif ( 'present' === $state ) {
		WP_CLI::log( "registration: ticket cards already marked (#{$reg->ID})" );
	} else {
		WP_CLI::warning( 'registration: no product-collection block found; ticket-card styling not applied' );
	}
}

// Site icon (favicon + touch icons): the summit mark, shipped with the theme.
// The previous site's /favicon.ico was a blank white square.
if ( ! get_option( 'site_icon' ) ) {
	require_once ABSPATH . 'wp-admin/includes/image.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	$src = get_theme_file_path( 'assets/img/site-icon-512.png' );
	$tmp = wp_tempnam( 'site-icon-512.png' );
	if ( ! copy( $src, $tmp ) ) {
		WP_CLI::error( "could not stage $src" );
	}
	// Generate WordPress's own site-icon crops (32, 180, 192, 270, 512).
	require_once ABSPATH . 'wp-admin/includes/class-wp-site-icon.php';
	$site_icon = new WP_Site_Icon();
	add_filter( 'intermediate_image_sizes_advanced', array( $site_icon, 'additional_sizes' ) );
	$icon_id = media_handle_sideload(
		array(
			'name'     => 'openzfs-summit-site-icon.png',
			'tmp_name' => $tmp,
		),
		0,
		'OpenZFS Summit site icon'
	);
	if ( is_wp_error( $icon_id ) ) {
		WP_CLI::error( 'site icon: ' . $icon_id->get_error_message() );
	}
	update_post_meta( $icon_id, '_wp_attachment_context', 'site-icon' );
	update_option( 'site_icon', $icon_id );
	WP_CLI::log( "site icon set (#$icon_id)" );
}

WP_CLI::success( 'pages composed' );
