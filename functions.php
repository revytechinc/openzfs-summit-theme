<?php
/**
 * OpenZFS Summit theme bootstrap.
 *
 * @package openzfs-summit
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const OZS_VERSION = '1.0.0';

/**
 * Theme supports. Block themes get most of these from theme.json; WooCommerce
 * still keys its gallery behaviour off these flags.
 */
function ozs_setup(): void {
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'editor-styles' );
	add_editor_style( 'style.css' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'custom-logo', array(
		'height'      => 150,
		'width'       => 150,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'woocommerce' );
	add_theme_support( 'wc-product-gallery-zoom' );
	add_theme_support( 'wc-product-gallery-lightbox' );
	add_theme_support( 'wc-product-gallery-slider' );
	register_nav_menus( array(
		'primary' => __( 'Primary', 'openzfs-summit' ),
		'footer'  => __( 'Footer', 'openzfs-summit' ),
	) );
}
add_action( 'after_setup_theme', 'ozs_setup' );

function ozs_enqueue(): void {
	wp_enqueue_style(
		'openzfs-summit',
		get_stylesheet_uri(),
		array(),
		(string) filemtime( get_stylesheet_directory() . '/style.css' )
	);
}
add_action( 'wp_enqueue_scripts', 'ozs_enqueue', 20 );

/**
 * Apply the visitor's stored colour-scheme choice before first paint, so a
 * visitor who chose dark never sees a light flash. Printed first in <head>.
 * The value is whitelisted, so a tampered localStorage entry cannot inject.
 */
function ozs_color_scheme_boot(): void {
	?>
<meta name="color-scheme" content="light dark">
<script>(function(){try{var t=localStorage.getItem('ozs-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}})();</script>
	<?php
}
add_action( 'wp_head', 'ozs_color_scheme_boot', 0 );

/**
 * theme-color follows the scheme so mobile browser chrome matches.
 */
function ozs_theme_color(): void {
	echo '<meta name="theme-color" media="(prefers-color-scheme: light)" content="#f8fafc">' . "\n";
	echo '<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b0f14">' . "\n";
}
add_action( 'wp_head', 'ozs_theme_color', 1 );

function ozs_register_blocks(): void {
	register_block_type( __DIR__ . '/blocks/theme-toggle' );
}
add_action( 'init', 'ozs_register_blocks' );

function ozs_block_styles(): void {
	register_block_style( 'core/paragraph', array(
		'name'  => 'ozs-mono',
		'label' => __( 'Monospace', 'openzfs-summit' ),
	) );
	register_block_style( 'core/group', array(
		'name'         => 'ozs-card',
		'label'        => __( 'Card', 'openzfs-summit' ),
		'inline_style' => '.wp-block-group.is-style-ozs-card{background:var(--wp--preset--color--surface);border:1px solid var(--wp--preset--color--border);border-radius:12px;box-shadow:var(--wp--preset--shadow--card);padding:1.5rem}',
	) );
}
add_action( 'init', 'ozs_block_styles' );

function ozs_pattern_categories(): void {
	register_block_pattern_category( 'openzfs-summit', array(
		'label' => __( 'OpenZFS Summit', 'openzfs-summit' ),
	) );
}
add_action( 'init', 'ozs_pattern_categories' );

/**
 * The Merchandise page lists merchandise only. Tickets (Registration) and the
 * T-shirt that comes with a ticket (Complimentary) are sold through the
 * Registration page, as on the previous site, so they stay catalogue-visible
 * (the Registration page's product collection needs that) and are left out of
 * the main shop archive here instead.
 */
function ozs_shop_excludes( WP_Query $q ): void { // NOSONAR -- php:S100: WordPress Coding Standards require prefixed snake_case function names.
	if ( ! function_exists( 'is_shop' ) || ! is_shop() ) {
		return;
	}
	$tax   = (array) $q->get( 'tax_query' );
	$tax[] = array(
		'taxonomy' => 'product_cat',
		'field'    => 'slug',
		'terms'    => (array) apply_filters( 'ozs_shop_excluded_categories', array( 'registration', 'complimentary' ) ),
		'operator' => 'NOT IN',
	);
	$q->set( 'tax_query', $tax );
}
add_action( 'woocommerce_product_query', 'ozs_shop_excludes' );

/**
 * Keep WooCommerce's classic stylesheets off: the blocks bring their own
 * styles and this theme maps them onto its tokens.
 */
add_filter( 'woocommerce_enqueue_styles', function ( $styles ) {
	// Another filter may already have returned false or '' (all styles off).
	if ( is_array( $styles ) ) {
		unset( $styles['woocommerce-general'] );
	}
	return $styles;
} );
