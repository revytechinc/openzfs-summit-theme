<?php
/**
 * Import the public content of summit.openzfs.org into this WordPress.
 *
 * Run through wp-cli (import-content.sh does this):
 *   wp eval-file import-content.php <export_dir> --user=<admin>
 *
 * <export_dir> is source-snapshot/export as produced by fetch-source.sh.
 * Idempotent: everything is matched on slug / SKU / source URL and updated
 * in place, never duplicated.
 */

if ( ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

// eval-file runs this inside a method: bind shared state to globals.
global $zfs_dir, $zfs_media;
$zfs_dir = isset( $args[0] ) ? rtrim( $args[0], '/' ) : '';
if ( ! $zfs_dir || ! is_file( "$zfs_dir/pages.json" ) ) {
	WP_CLI::error( 'usage: wp eval-file import-content.php <export_dir>' );
}

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

const ZFS_SRC = 'https://summit.openzfs.org';

function zfs_json( $file ) {
	global $zfs_dir;
	$data = json_decode( file_get_contents( "$zfs_dir/$file" ), true );
	if ( null === $data ) {
		WP_CLI::error( "cannot parse $file" );
	}
	return $data;
}

function zfs_log( $msg ) {
	WP_CLI::log( $msg );
}

/* ------------------------------------------------------------------ */
/* 1. Site options                                                     */
/* ------------------------------------------------------------------ */
$root = zfs_json( 'root.json' );
update_option( 'blogname', 'OpenZFS Developer Summit' );
update_option( 'blogdescription', (string) $root['description'] );
// Source renders event times as -05:00 in late October => US Central.
update_option( 'timezone_string', 'America/Chicago' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );

/* ------------------------------------------------------------------ */
/* 2. Media                                                            */
/* ------------------------------------------------------------------ */
$zfs_media = array(); // source URL => attachment ID (global)

function zfs_attachment_by_source( $url ) {
	$q = get_posts(
		array(
			'post_type'   => 'attachment',
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
			'meta_key'    => '_zfs_source_url',
			'meta_value'  => $url,
		)
	);
	return $q ? (int) $q[0] : 0;
}

/** Remove a temp file only if it really lives in the system temp dir. */
function zfs_remove_temp_file( $tmp ) {
	$real = realpath( $tmp );
	$base = realpath( sys_get_temp_dir() );
	if ( $real && $base && 0 === strpos( $real, $base . DIRECTORY_SEPARATOR ) && is_file( $real ) ) {
		wp_delete_file( $real );
	}
}

/** Sideload one exported file; returns the attachment ID or 0. */
function zfs_sideload( $url, $title ) {
	global $zfs_dir;
	$id   = 0;
	$file = "$zfs_dir/media/" . basename( $url );
	if ( ! is_file( $file ) ) {
		zfs_log( "  media missing locally, skipped: $url" );
	} else {
		// A temp file we create ourselves; media_handle_sideload() moves it.
		$tmp = wp_tempnam( basename( $url ) );
		copy( $file, $tmp );
		$res = media_handle_sideload(
			array(
				'name'     => basename( $url ),
				'tmp_name' => $tmp,
			),
			0,
			$title ? $title : null
		);
		if ( is_wp_error( $res ) ) {
			zfs_log( '  media import failed for ' . basename( $url ) . ': ' . $res->get_error_message() );
			zfs_remove_temp_file( $tmp );
		} else {
			$id = (int) $res;
			update_post_meta( $id, '_zfs_source_url', $url );
			zfs_log( "  imported media #$id " . basename( $url ) );
		}
	}
	return $id;
}

function zfs_import_media( $url, $title = '', $alt = '' ) {
	global $zfs_media;
	if ( ! isset( $zfs_media[ $url ] ) ) {
		$id = zfs_attachment_by_source( $url );
		if ( ! $id ) {
			$id = zfs_sideload( $url, $title );
		}
		if ( $id && $title ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => $title ) );
		}
		if ( $id && '' !== $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		if ( $id ) {
			$zfs_media[ $url ] = (int) $id;
		}
	}
	return isset( $zfs_media[ $url ] ) ? $zfs_media[ $url ] : 0;
}

zfs_log( 'Media:' );
foreach ( zfs_json( 'media.json' ) as $m ) {
	zfs_import_media( $m['source_url'], html_entity_decode( $m['title']['rendered'] ), (string) $m['alt_text'] );
}
// Sponsor logos of the front page live outside uploads (/wp-content/logos/).
$front_html = file_get_contents( "$zfs_dir/front-page.html" );
preg_match_all( '#<img[^>]+src="(' . preg_quote( ZFS_SRC, '#' ) . '/wp-content/logos/[^"]+)"[^>]*alt="([^"]*)"#', $front_html, $mm, PREG_SET_ORDER );
foreach ( $mm as $m ) {
	zfs_import_media( $m[1], $m[2], $m[2] );
}
// Anything else fetch-source.sh downloaded (product images etc.).
foreach ( file( "$zfs_dir/media-urls.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $u ) {
	zfs_import_media( $u );
}

/** Rewrite every source upload/logo URL (incl. -WxH thumbnails) to local. */
function zfs_rewrite_urls( $html ) {
	global $zfs_media;
	return preg_replace_callback(
		'#' . preg_quote( ZFS_SRC, '#' ) . '/wp-content/(?:uploads|logos)/[^"\'\s)]+#',
		function ( $m ) use ( $zfs_media ) {
			$u    = $m[0];
			$base = preg_replace( '/-\d+x\d+(\.\w+)$/', '$1', $u );
			foreach ( array( $u, $base ) as $k ) {
				if ( isset( $zfs_media[ $k ] ) ) {
					return wp_get_attachment_url( $zfs_media[ $k ] );
				}
			}
			return $u;
		},
		$html
	);
}

$logo_url = ZFS_SRC . '/wp-content/uploads/openzfs-developer-summit-portland-logo-teal-alpha-150x150.96dpi.png';
$logo_id  = isset( $zfs_media[ $logo_url ] ) ? $zfs_media[ $logo_url ] : 0;
if ( $logo_id ) {
	// site_logo feeds both the core/site-logo block and (via core filter)
	// get_theme_mod('custom_logo') for whatever theme is active.
	update_option( 'site_logo', $logo_id );
	set_theme_mod( 'custom_logo', $logo_id );
}
if ( ! empty( $root['site_icon'] ) ) {
	zfs_log( 'NOTE: source has a site icon id ' . $root['site_icon'] . ' (not imported)' );
}

/* ------------------------------------------------------------------ */
/* 3. Product taxonomy                                                 */
/* ------------------------------------------------------------------ */
function zfs_term( $name, $slug, $tax ) {
	$t = get_term_by( 'slug', $slug, $tax );
	if ( $t ) {
		return (int) $t->term_id;
	}
	$r = wp_insert_term( $name, $tax, array( 'slug' => $slug ) );
	if ( is_wp_error( $r ) ) {
		WP_CLI::error( "term $tax/$slug: " . $r->get_error_message() );
	}
	return (int) $r['term_id'];
}

$cat_ids = array(); // slug => local id
foreach ( zfs_json( 'product-categories.json' ) as $c ) {
	$cat_ids[ $c['slug'] ] = zfs_term( html_entity_decode( $c['name'] ), $c['slug'], 'product_cat' );
}
// Source category ids used in block queries => local ids.
$src_cat_map = array();
foreach ( zfs_json( 'product-categories.json' ) as $c ) {
	$src_cat_map[ (int) $c['id'] ] = $cat_ids[ $c['slug'] ];
}

// Global "Size" attribute.
$size_order = array( 'xs' => 'XS', 's' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL', '2xl' => '2XL', '3xl' => '3XL', '4xl' => '4XL', '5xl' => '5XL' );
$attr_id    = wc_attribute_taxonomy_id_by_name( 'pa_size' );
if ( ! $attr_id ) {
	$attr_id = wc_create_attribute(
		array(
			'name'         => 'Size',
			'slug'         => 'size',
			'type'         => 'select',
			'order_by'     => 'menu_order',
			'has_archives' => false,
		)
	);
	if ( is_wp_error( $attr_id ) ) {
		WP_CLI::error( 'attribute: ' . $attr_id->get_error_message() );
	}
}
if ( ! taxonomy_exists( 'pa_size' ) ) {
	register_taxonomy( 'pa_size', array( 'product', 'product_variation' ), array( 'hierarchical' => false ) );
}
$size_terms = array();
$i          = 0;
foreach ( $size_order as $slug => $name ) {
	$size_terms[ $slug ] = zfs_term( $name, $slug, 'pa_size' );
	update_term_meta( $size_terms[ $slug ], 'order', $i++ );
}

/* ------------------------------------------------------------------ */
/* 4. Products                                                         */
/* ------------------------------------------------------------------ */
function zfs_product_id_by_slug( $slug ) {
	$p = get_page_by_path( $slug, OBJECT, 'product' );
	return $p ? (int) $p->ID : 0;
}

function zfs_price( $minor ) {
	return wc_format_decimal( ( (int) $minor ) / 100, 2 );
}

$src_products = zfs_json( 'products.json' );
$prod_map     = array(); // source id => local id
$var_count    = 0;

// Order so that bundle components exist before bundles.
usort(
	$src_products,
	function ( $a, $b ) {
		return ( 'easy_product_bundle' === $a['type'] ) <=> ( 'easy_product_bundle' === $b['type'] );
	}
);

zfs_log( 'Products:' );
foreach ( $src_products as $sp ) {
	$id   = zfs_product_id_by_slug( $sp['slug'] );
	$type = $sp['type'];

	if ( ! $id ) {
		$id = wp_insert_post(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => html_entity_decode( $sp['name'] ),
				'post_name'   => $sp['slug'],
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( 'product ' . $sp['slug'] . ': ' . $id->get_error_message() );
		}
	}
	wp_set_object_terms( $id, $type, 'product_type' );
	wc_delete_product_transients( $id );
	$classname = WC_Product_Factory::get_product_classname( $id, $type );
	$product   = new $classname( $id );

	$product->set_name( html_entity_decode( $sp['name'] ) );
	$product->set_slug( $sp['slug'] );
	$product->set_status( 'publish' );
	$product->set_description( $sp['description'] );
	$product->set_short_description( $sp['short_description'] );
	if ( '' !== $sp['sku'] ) {
		$product->set_sku( $sp['sku'] );
	}
	$product->set_category_ids(
		array_map(
			function ( $c ) use ( $cat_ids ) {
				return $cat_ids[ $c['slug'] ];
			},
			$sp['categories']
		)
	);
	$product->set_tag_ids(
		array_map(
			function ( $t ) {
				return zfs_term( html_entity_decode( $t['name'] ), $t['slug'], 'product_tag' );
			},
			$sp['tags']
		)
	);
	$imgs = array();
	foreach ( $sp['images'] as $img ) {
		$aid = zfs_import_media( $img['src'] );
		if ( $aid ) {
			$imgs[] = $aid;
		}
	}
	$product->set_image_id( $imgs ? $imgs[0] : '' );
	$product->set_gallery_image_ids( array_slice( $imgs, 1 ) );
	$product->set_stock_status( 'instock' );

	if ( 'variable' !== $type ) {
		$product->set_regular_price( zfs_price( $sp['prices']['regular_price'] ) );
		$product->set_price( zfs_price( $sp['prices']['price'] ) );
	}
	if ( 'easy_product_bundle' === $type ) {
		// Registration tickets: virtual, one per order (as on source).
		$product->set_virtual( true );
		$product->set_sold_individually( true );
	}

	if ( 'variable' === $type ) {
		$opts = array();
		foreach ( $sp['attributes'] as $a ) {
			foreach ( $a['terms'] as $t ) {
				$opts[] = $size_terms[ $t['slug'] ];
			}
		}
		// Keep XS..5XL order.
		$opts = array_values( array_intersect( array_values( $size_terms ), $opts ) );
		$attr = new WC_Product_Attribute();
		$attr->set_id( $attr_id );
		$attr->set_name( 'pa_size' );
		$attr->set_options( $opts );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$product->set_attributes( array( $attr ) );
	}

	$id                        = $product->save();
	$prod_map[ (int) $sp['id'] ] = $id;
	zfs_log( "  product #$id {$sp['slug']} ($type)" );

	if ( 'variable' === $type ) {
		$existing = array();
		foreach ( $product->get_children() as $cid ) {
			$v = wc_get_product( $cid );
			if ( $v ) {
				$existing[ $v->get_attribute( 'pa_size' ) ? sanitize_title( $v->get_attribute( 'pa_size' ) ) : '' ] = $cid;
			}
		}
		$menu = 0;
		foreach ( $sp['variations'] as $sv ) {
			global $zfs_dir;
			$vj   = json_decode( file_get_contents( "$zfs_dir/variations/{$sv['id']}.json" ), true );
			$size = $sv['attributes'][0]['value'];
			$v    = isset( $existing[ $size ] ) ? new WC_Product_Variation( $existing[ $size ] ) : new WC_Product_Variation();
			$v->set_parent_id( $id );
			$v->set_attributes( array( 'pa_size' => $size ) );
			$v->set_regular_price( zfs_price( $vj['prices']['regular_price'] ) );
			if ( '' !== $vj['sku'] ) {
				// SKUs must be unique; clear a stale owner first.
				$owner = wc_get_product_id_by_sku( $vj['sku'] );
				if ( $owner && $owner !== $v->get_id() ) {
					zfs_log( "  sku {$vj['sku']} already on #$owner, skipped" );
				} else {
					$v->set_sku( $vj['sku'] );
				}
			}
			if ( ! empty( $vj['images'] ) ) {
				$v->set_image_id( zfs_import_media( $vj['images'][0]['src'] ) );
			}
			$v->set_stock_status( $vj['is_in_stock'] ? 'instock' : 'outofstock' );
			$v->set_menu_order( $menu++ );
			$v->set_status( 'publish' );
			$v->save();
			++$var_count;
		}
		WC_Product_Variable::sync( $id );
	}
}

/* Bundles (Product Bundle Builder for WooCommerce) */
$bundles = zfs_json( 'bundles.json' );
if ( class_exists( 'AsanaPlugins\WooCommerce\ProductBundles\Admin\ProductBundle' ) ) {
	foreach ( $bundles as $slug => $data ) {
		$bid = zfs_product_id_by_slug( $slug );
		if ( ! $bid ) {
			continue;
		}
		$b     = $data['bundles'];
		$items = array();
		foreach ( $b['bundles'] as $it ) {
			$src_pid = (int) $it['product']['id'];
			if ( empty( $prod_map[ $src_pid ] ) ) {
				continue;
			}
			$item            = $it;
			unset( $item['product'], $item['can_change_product'] );
			$item['product']  = $prod_map[ $src_pid ];
			$item['products'] = array();
			$item['image_url'] = $item['image_url'] ? zfs_rewrite_urls( $item['image_url'] ) : '';
			$items[]         = $item;
		}
		$_POST = array(
			'asnp_wepb_individual_theme'     => $b['individual_theme'],
			'asnp_wepb_theme'                => $b['theme'],
			'asnp_wepb_theme_size'           => $b['theme_size'],
			'asnp_wepb_fixed_price'          => ! empty( $b['product']['is_fixed_price'] ) ? 'true' : 'false',
			'asnp_wepb_include_parent_price' => $b['product']['include_parent_price'],
			'asnp_wepb_sync_stock_quantity'  => $b['sync_stock_quantity'],
			'asnp_wepb_min_items_quantity'   => $b['min_items_quantity'],
			'asnp_wepb_max_items_quantity'   => $b['max_items_quantity'],
			'asnp_wepb_bundle_title'         => $b['bundle_title'],
			'asnp_wepb_bundle_description'   => $b['bundle_description'],
			'asnp_wepb_hide_items_price'     => $b['hide_items_price'],
			'asnp_wepb_bundle_button_label'  => $b['bundle_button_label'],
			'asnp_wepb_total_discount_type'  => $b['total_discount_type'],
			'asnp_wepb_total_discount'       => $b['total_discount'],
			'asnp_wepb_bundle_items'         => wp_slash( wp_json_encode( $items ) ),
		);
		$admin = new AsanaPlugins\WooCommerce\ProductBundles\Admin\ProductBundle();
		$admin->save_product_data( $bid );
		$_POST = array();
		$bp    = wc_get_product( $bid );
		if ( $bp && '' === $bp->get_price() ) {
			$bp->set_price( $bp->get_regular_price() );
			$bp->save();
		}
		zfs_log( "  bundle #$bid $slug: " . count( $items ) . ' item(s)' );
	}
} else {
	zfs_log( 'WARNING: bundle plugin admin class missing; bundle items not configured' );
}

/* Extra product fields (Extra Product Options for WooCommerce, ThemeHigh) */
if ( class_exists( 'THWEPOF_Utils' ) ) {
	global $zfs_dir;
	$fields  = array(); // name => [def, product ids]
	$order   = array();
	$bundles_slugs = array_keys( $bundles );
	foreach ( $src_products as $sp ) {
		$f = "$zfs_dir/product-pages/{$sp['slug']}.html";
		if ( ! is_file( $f ) ) {
			continue;
		}
		$html = file_get_contents( $f );
		if ( ! preg_match( '#<table class="thwepo-extra-options.*?</table>#s', $html, $tm ) ) {
			continue;
		}
		preg_match_all( '#<tr[^>]*>(.*?)</tr>#s', $tm[0], $rows );
		foreach ( $rows[1] as $row ) {
			if ( ! preg_match( '#<(input|textarea)[^>]*name="([^"]+)"[^>]*>#', $row, $im ) ) {
				continue;
			}
			$name = $im[2];
			preg_match( '#<label class="label-tag[^"]*"\s*>(.*?)</label>#s', $row, $lm );
			preg_match( '#placeholder="([^"]*)"#', $im[0], $pm );
			$fields[ $name ]['type']        = 'textarea' === $im[1] ? 'textarea' : 'inputtext';
			$fields[ $name ]['title']       = html_entity_decode( trim( $lm[1] ) );
			$fields[ $name ]['placeholder'] = isset( $pm[1] ) ? html_entity_decode( $pm[1] ) : '';
			$fields[ $name ]['required']    = false !== strpos( $row, 'validate-required' );
			$fields[ $name ]['above']       = false !== strpos( $row, 'abovefield' );
			$fields[ $name ]['products'][]  = (string) $prod_map[ (int) $sp['id'] ];
			if ( ! in_array( $name, $order, true ) ) {
				$order[] = $name;
			}
		}
	}
	$section = THWEPOF_Utils_Section::prepare_default_section();
	foreach ( $order as $name ) {
		$d     = $fields[ $name ];
		$field = THWEPOF_Utils_Field::create_field( $d['type'] );
		$field->set_property( 'type', $d['type'] );
		$field->set_property( 'name', $name );
		$field->set_property( 'title', $d['title'] );
		$field->set_property( 'placeholder', $d['placeholder'] );
		$field->set_property( 'required', $d['required'] ? 1 : 0 );
		$field->set_property( 'enabled', 1 );
		$field->set_property( 'title_position', $d['above'] ? 'above' : 'left' );
		if ( 'textarea' === $d['type'] ) {
			$field->set_property( 'cols', '300' );
			$field->set_property( 'rows', '30' );
		}
		$rules = array( array( array( array( array( 'subject' => 'product', 'comparison' => 'equals', 'cvalue' => $d['products'] ) ) ) ) );
		$json  = wp_json_encode( $rules );
		$field->set_property( 'conditional_rules_json', rawurlencode( $json ) );
		$field->set_property( 'conditional_rules', THWEPOF_Utils::prepare_conditional_rules( array( 'i_rules' => $json ) ) );
		THWEPOF_Utils_Field::prepare_properties( $field );
		THWEPOF_Utils_Section::add_field( $section, $field );
	}
	THWEPOF_Utils::update_section( $section );
	zfs_log( '  extra product option fields: ' . implode( ',', $order ) );
} else {
	zfs_log( 'WARNING: Extra Product Options plugin not loaded; registration fields not configured' );
}

/* ------------------------------------------------------------------ */
/* 5. Pages                                                            */
/* ------------------------------------------------------------------ */
$src_pages = array();
foreach ( zfs_json( 'pages.json' ) as $p ) {
	$src_pages[ $p['slug'] ] = $p;
}

/**
 * True when an existing page was changed by someone after this importer
 * last wrote it (e.g. the theme work redesigning Home). Such pages are left
 * alone unless ZFS_IMPORT_FORCE=1. A page with no import marker counts as
 * edited unless its content already equals what we would write.
 */
function zfs_content_hash( $content ) {
	// sha256 of the stored content. A marker written by an older md5 version
	// never matches, so such a page reads as edited and is kept (the safe side).
	return hash( 'sha256', (string) $content );
}

function zfs_page_was_edited( $existing, $content ) {
	$current = zfs_content_hash( $existing->post_content );
	$marker  = get_post_meta( $existing->ID, '_zfs_import_hash', true );
	$edited  = $marker ? $marker !== $current : zfs_content_hash( $content ) !== $current;
	return $edited && '1' !== getenv( 'ZFS_IMPORT_FORCE' );
}

function zfs_upsert_page( $slug, $title, $content, $date = null ) {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	$arr      = array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_name'    => $slug,
		'post_title'   => $title,
		'post_content' => $content,
	);
	if ( $date ) {
		$arr['post_date'] = str_replace( 'T', ' ', $date );
	}
	if ( $existing && zfs_page_was_edited( $existing, $content ) ) {
		zfs_log( "  page #{$existing->ID} /$slug/ edited since import, kept (ZFS_IMPORT_FORCE=1 overwrites)" );
		$id = (int) $existing->ID;
	} else {
		if ( $existing ) {
			$arr['ID'] = $existing->ID;
			$id        = wp_update_post( wp_slash( $arr ), true );
		} else {
			$id = wp_insert_post( wp_slash( $arr ), true );
		}
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( "page $slug: " . $id->get_error_message() );
		}
		update_post_meta( $id, '_zfs_import_hash', zfs_content_hash( get_post( $id )->post_content ) );
		zfs_log( "  page #$id /$slug/" );
	}
	return (int) $id;
}

/** Serialized core/heading block (h2 unless $level given). */
function zfs_heading_block( $html, $level = 2, $anchor = '' ) {
	$attrs = 2 === $level ? '' : ' {"level":' . (int) $level . '}';
	$id    = $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '';
	return "<!-- wp:heading$attrs -->\n<h$level class=\"wp-block-heading\"$id>$html</h$level>\n<!-- /wp:heading -->";
}

/** Serialized core/paragraph block. */
function zfs_paragraph_block( $html ) {
	return "<!-- wp:paragraph -->\n<p>$html</p>\n<!-- /wp:paragraph -->";
}

/**
 * Convert simple rendered core-block HTML (h2/p/ul top level elements) into
 * serialized block markup.
 */
function zfs_html_to_blocks( $html ) {
	$out = array();
	preg_match_all( '#<(h[1-6]|p|ul|ol)\b([^>]*)>(.*?)</\1>#s', $html, $els, PREG_SET_ORDER );
	foreach ( $els as $e ) {
		$tag   = $e[1];
		$inner = trim( $e[3] );
		if ( 'p' === $tag ) {
			$out[] = zfs_paragraph_block( $inner );
		} elseif ( 'ul' === $tag || 'ol' === $tag ) {
			preg_match_all( '#<li[^>]*>(.*?)</li>#s', $inner, $lis );
			$items = array();
			foreach ( $lis[1] as $li ) {
				$items[] = "<!-- wp:list-item -->\n<li>" . trim( $li ) . "</li>\n<!-- /wp:list-item -->";
			}
			$attr  = 'ol' === $tag ? ' {"ordered":true}' : '';
			$out[] = "<!-- wp:list$attr -->\n<$tag class=\"wp-block-list\">" . implode( "\n", $items ) . "</$tag>\n<!-- /wp:list -->";
		} else {
			$out[] = zfs_heading_block( $inner, (int) substr( $tag, 1 ) );
		}
	}
	return implode( "\n\n", $out );
}

function zfs_image_block( $att_id, $alt, $width_px = null, $align = null ) {
	$url   = wp_get_attachment_url( $att_id );
	$attrs = array( 'id' => $att_id, 'sizeSlug' => 'full', 'linkDestination' => 'none' );
	$fig   = 'wp-block-image size-full';
	$style = '';
	if ( $align ) {
		$attrs['align'] = $align;
		$fig           .= ' align' . $align;
	}
	if ( $width_px ) {
		$attrs['width'] = $width_px . 'px';
		$fig           .= ' is-resized';
		$style          = ' style="width:' . $width_px . 'px;height:auto"';
	}
	return '<!-- wp:image ' . wp_json_encode( $attrs ) . " -->\n<figure class=\"$fig\"><img src=\"" . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . "\" class=\"wp-image-$att_id\"$style/></figure>\n<!-- /wp:image -->";
}

/** Sponsor tiers from the rendered front-page "Sponsors" widget. */
function zfs_sponsor_tiers( $widget_html ) {
	$tiers = array();
	// Alternating [text, heading, text, heading, ...].
	$parts = preg_split( '#<h2[^>]*>(.*?)</h2>#s', $widget_html, -1, PREG_SPLIT_DELIM_CAPTURE );
	$count = count( $parts );
	for ( $i = 1; $i + 1 < $count; $i += 2 ) {
		$tier = trim( wp_strip_all_tags( $parts[ $i ] ) );
		if ( 'Sponsors' === $tier ) {
			continue;
		}
		$tiers[ $tier ] = zfs_logos_in( $parts[ $i + 1 ] );
	}
	return $tiers;
}

/** Logo <img> tags (src, alt, CSS width) in a chunk of HTML. */
function zfs_logos_in( $html ) {
	$logos = array();
	preg_match_all( '#<img\b[^>]*>#', $html, $imgs );
	foreach ( $imgs[0] as $tag ) {
		$src   = preg_match( '#\bsrc="([^"]+)"#', $tag, $m ) ? $m[1] : '';
		$alt   = preg_match( '#\balt="([^"]*)"#', $tag, $m ) ? html_entity_decode( $m[1] ) : '';
		$width = preg_match( '#width:\s*(\d+)px#', $tag, $m ) ? (int) $m[1] : 0;
		if ( $src && $width ) {
			$logos[] = array( 'src' => $src, 'alt' => $alt, 'width' => $width );
		}
	}
	return $logos;
}

function zfs_sponsor_blocks( $tiers, $center = false ) {
	global $zfs_media;
	$out = array();
	foreach ( $tiers as $tier => $logos ) {
		$out[] = zfs_heading_block( esc_html( $tier ) );
		foreach ( $logos as $l ) {
			if ( ! empty( $zfs_media[ $l['src'] ] ) ) {
				$out[] = zfs_image_block( $zfs_media[ $l['src'] ], $l['alt'], $l['width'], $center ? 'center' : null );
			}
		}
	}
	return implode( "\n\n", $out );
}

zfs_log( 'Pages:' );
// Front-page widget content (source theme renders Home from widgets).
preg_match( '#<section id="block-10"[^>]*>(.*?)</section>#s', $front_html, $about );
preg_match( '#<section id="block-12"[^>]*>(.*?)</section>\s*</div>#s', $front_html, $spons );
$about_html = isset( $about[1] ) ? $about[1] : '';
$spons_html = isset( $spons[1] ) ? $spons[1] : '';

// About: heading, intro paragraph(s) split on blank lines, Summit Days.
$home = array();
if ( preg_match( '#<h2>(.*?)</h2>\s*<p>(.*?)</p>#s', $about_html, $a ) ) {
	$home[] = zfs_heading_block( trim( $a[1] ) );
	foreach ( preg_split( '/\n\s*\n/', trim( $a[2] ) ) as $para ) {
		$home[] = zfs_paragraph_block( trim( $para ) );
	}
}
if ( preg_match( '#<div[^>]*>\s*<h2>(.*?)</h2>(.*?)</p>\s*</div>#s', $about_html, $d ) ) {
	$lines  = trim( $d[2] );
	$home[] = zfs_heading_block( trim( $d[1] ) );
	$home[] = zfs_paragraph_block( preg_replace( '#<br\s*/?>\s*#', '<br>', $lines ) );
}
// Sponsors section: same intro as the Sponsors page + tiered logos.
$spons_intro = '';
if ( preg_match_all( '#<p class="wp-block-paragraph"[^>]*>(.*?)</p>#s', $spons_html, $sp ) ) {
	$spons_intro = implode(
		"\n\n",
		array_map(
			function ( $p ) {
				return zfs_paragraph_block( trim( $p ) );
			},
			$sp[1]
		)
	);
}
$tiers  = zfs_sponsor_tiers( $spons_html );
$home[] = zfs_heading_block( 'Sponsors', 2, 'sponsors' );
$home[] = $spons_intro;
$home[] = zfs_sponsor_blocks( $tiers );
$page_ids = array();
$page_ids['home'] = zfs_upsert_page( 'home', 'Home', zfs_rewrite_urls( implode( "\n\n", $home ) ), $src_pages['home']['date'] );

// Sponsors ("sample-page"): its own intro + logos. The source page's
// images 404 on the source itself, so the current (2026) logos are used.
$sp_src   = $src_pages['sample-page']['content']['rendered'];
$sp_intro = array();
preg_match_all( '#<p class="wp-block-paragraph"[^>]*>(.*?)</p>#s', $sp_src, $spp );
foreach ( $spp[1] as $p ) {
	$sp_intro[] = zfs_paragraph_block( trim( $p ) );
}
// Logos that appear on the source Sponsors page, by name.
$sp_names   = array( 'Delphix', 'AWS', 'Truenas', 'DigialGlue', 'Klara', 'Prominic', 'Bacula', 'OSN' );
$sp_tiers   = array();
foreach ( $tiers as $tier => $logos ) {
	foreach ( $logos as $l ) {
		foreach ( $sp_names as $n ) {
			$key = strtolower( substr( $n, 0, 5 ) );
			if ( 0 === strpos( strtolower( basename( $l['src'] ) ), $key ) ||
				( 'osn' === strtolower( $n ) && 0 === strpos( strtolower( basename( $l['src'] ) ), 'osnexus' ) ) ||
				( 'digialglue' === strtolower( $n ) && 0 === strpos( strtolower( basename( $l['src'] ) ), 'digitalglue' ) ) ) {
				$sp_tiers[ $tier ][] = $l;
				break;
			}
		}
	}
}
$page_ids['sample-page'] = zfs_upsert_page(
	'sample-page',
	'Sponsors',
	zfs_rewrite_urls( implode( "\n\n", $sp_intro ) . "\n\n" . zfs_sponsor_blocks( $sp_tiers, true ) ),
	$src_pages['sample-page']['date']
);

// Terms of Service: verbatim (Japanese), converted to core blocks.
$page_ids['c-terms'] = zfs_upsert_page(
	'c-terms',
	html_entity_decode( $src_pages['c-terms']['title']['rendered'] ),
	zfs_html_to_blocks( $src_pages['c-terms']['content']['rendered'] ),
	$src_pages['c-terms']['date']
);

// Speakers: empty on the source.
$page_ids['speakers'] = zfs_upsert_page( 'speakers', 'Speakers', '', $src_pages['speakers']['date'] );

// Product collection block (by category), as used on the source.
function zfs_product_collection( $cat_id, $cols, $order_by, $query_id, $heading = '', $image = array() ) {
	$attrs = array(
		'queryId'              => $query_id,
		'query'                => array(
			'perPage'                      => 9,
			'pages'                        => 0,
			'offset'                       => 0,
			'postType'                     => 'product',
			'order'                        => 'asc',
			'orderBy'                      => $order_by,
			'search'                       => '',
			'exclude'                      => array(),
			'inherit'                      => false,
			'taxQuery'                     => array( 'product_cat' => array( $cat_id ) ),
			'isProductCollectionBlock'     => true,
			'featured'                     => false,
			'woocommerceOnSale'            => false,
			'woocommerceStockStatus'       => array( 'instock', 'outofstock', 'onbackorder' ),
			'woocommerceAttributes'        => array(),
			'woocommerceHandPickedProducts' => array(),
			'filterable'                   => false,
			'relatedBy'                    => array( 'categories' => true, 'tags' => true ),
		),
		'tagName'              => 'div',
		'displayLayout'        => array( 'type' => 'flex', 'columns' => $cols, 'shrinkColumns' => true ),
		'dimensions'           => array( 'widthType' => 'fill' ),
		'collection'           => 'woocommerce/product-collection/by-category',
		'hideControls'         => array( 'inherit', 'hand-picked', 'filterable' ),
		'queryContextIncludes' => array( 'collection' ),
	);
	$img   = array_merge( array( 'showSaleBadge' => false, 'isDescendentOfQueryLoop' => true, 'aspectRatio' => '1/1' ), $image );
	$h     = $heading ? "<!-- wp:heading {\"style\":{\"spacing\":{\"margin\":{\"bottom\":\"1rem\"}}}} -->\n<h2 class=\"wp-block-heading\" style=\"margin-bottom:1rem\">$heading</h2>\n<!-- /wp:heading -->\n\n" : '';
	$size  = $image ? 'medium' : 'small';
	return '<!-- wp:woocommerce/product-collection ' . wp_json_encode( $attrs ) . " -->\n<div class=\"wp-block-woocommerce-product-collection\">$h<!-- wp:woocommerce/product-template -->\n"
		. '<!-- wp:woocommerce/product-image ' . wp_json_encode( $img ) . " /-->\n\n"
		. '<!-- wp:post-title {"textAlign":"center","level":2,"isLink":true,"style":{"spacing":{"margin":{"bottom":"0.75rem","top":"0"}},"typography":{"lineHeight":"1.4"}},"fontSize":"medium","__woocommerceNamespace":"woocommerce/product-collection/product-title"} /-->' . "\n\n"
		. '<!-- wp:woocommerce/product-price {"isDescendentOfQueryLoop":true,"textAlign":"center","fontSize":"' . $size . "\"} /-->\n\n"
		. '<!-- wp:woocommerce/product-button {"textAlign":"center","isDescendentOfQueryLoop":true,"fontSize":"small"} /-->' . "\n"
		. "<!-- /wp:woocommerce/product-template --></div>\n<!-- /wp:woocommerce/product-collection -->";
}

// Registration: live product collection of the Registration category.
$reg = "<!-- wp:group {\"layout\":{\"type\":\"flex\",\"orientation\":\"vertical\",\"justifyContent\":\"center\"}} -->\n<div class=\"wp-block-group\">"
	. zfs_product_collection( $cat_ids['registration'], 2, 'title', 38, '', array( 'width' => '200px', 'height' => '200px', 'scale' => 'contain' ) )
	. "</div>\n<!-- /wp:group -->";
$page_ids['registration'] = zfs_upsert_page( 'registration', 'Registration', $reg, $src_pages['registration']['date'] );

// Sessions: live My Calendar output (week grid, weekdays, Oct 2026).
$page_ids['sessions'] = zfs_upsert_page(
	'sessions',
	html_entity_decode( $src_pages['sessions']['title']['rendered'] ),
	"<!-- wp:shortcode -->\n[my_calendar format=\"calendar\" time=\"week\" year=\"2026\" month=\"10\" day=\"27\" weekends=\"false\" above=\"toggle,timeframe,nav\" below=\"key,print\"]\n<!-- /wp:shortcode -->",
	$src_pages['sessions']['date']
);

// WooCommerce pages (cart/checkout blocks like the source).
$swag = $cat_ids['swag'];
$cart = '<!-- wp:woocommerce/cart -->
<div class="wp-block-woocommerce-cart alignwide is-loading"><!-- wp:woocommerce/filled-cart-block -->
<div class="wp-block-woocommerce-filled-cart-block"><!-- wp:woocommerce/cart-items-block -->
<div class="wp-block-woocommerce-cart-items-block"><!-- wp:woocommerce/cart-line-items-block -->
<div class="wp-block-woocommerce-cart-line-items-block"></div>
<!-- /wp:woocommerce/cart-line-items-block -->

' . zfs_product_collection( $swag, 5, 'menu_order', 0, 'Optional Merchandise:' ) . '</div>
<!-- /wp:woocommerce/cart-items-block -->

<!-- wp:woocommerce/cart-totals-block -->
<div class="wp-block-woocommerce-cart-totals-block"><!-- wp:woocommerce/cart-order-summary-block -->
<div class="wp-block-woocommerce-cart-order-summary-block"><!-- wp:woocommerce/cart-order-summary-heading-block -->
<div class="wp-block-woocommerce-cart-order-summary-heading-block"></div>
<!-- /wp:woocommerce/cart-order-summary-heading-block -->

<!-- wp:woocommerce/cart-order-summary-coupon-form-block -->
<div class="wp-block-woocommerce-cart-order-summary-coupon-form-block"></div>
<!-- /wp:woocommerce/cart-order-summary-coupon-form-block -->

<!-- wp:woocommerce/cart-order-summary-totals-block -->
<div class="wp-block-woocommerce-cart-order-summary-totals-block"><!-- wp:woocommerce/cart-order-summary-subtotal-block -->
<div class="wp-block-woocommerce-cart-order-summary-subtotal-block"></div>
<!-- /wp:woocommerce/cart-order-summary-subtotal-block -->

<!-- wp:woocommerce/cart-order-summary-fee-block -->
<div class="wp-block-woocommerce-cart-order-summary-fee-block"></div>
<!-- /wp:woocommerce/cart-order-summary-fee-block -->

<!-- wp:woocommerce/cart-order-summary-discount-block -->
<div class="wp-block-woocommerce-cart-order-summary-discount-block"></div>
<!-- /wp:woocommerce/cart-order-summary-discount-block -->

<!-- wp:woocommerce/cart-order-summary-shipping-block -->
<div class="wp-block-woocommerce-cart-order-summary-shipping-block"></div>
<!-- /wp:woocommerce/cart-order-summary-shipping-block -->

<!-- wp:woocommerce/cart-order-summary-taxes-block -->
<div class="wp-block-woocommerce-cart-order-summary-taxes-block"></div>
<!-- /wp:woocommerce/cart-order-summary-taxes-block --></div>
<!-- /wp:woocommerce/cart-order-summary-totals-block --></div>
<!-- /wp:woocommerce/cart-order-summary-block -->

<!-- wp:woocommerce/cart-express-payment-block -->
<div class="wp-block-woocommerce-cart-express-payment-block"></div>
<!-- /wp:woocommerce/cart-express-payment-block -->

<!-- wp:woocommerce/proceed-to-checkout-block -->
<div class="wp-block-woocommerce-proceed-to-checkout-block"></div>
<!-- /wp:woocommerce/proceed-to-checkout-block -->

<!-- wp:woocommerce/cart-accepted-payment-methods-block -->
<div class="wp-block-woocommerce-cart-accepted-payment-methods-block"></div>
<!-- /wp:woocommerce/cart-accepted-payment-methods-block --></div>
<!-- /wp:woocommerce/cart-totals-block --></div>
<!-- /wp:woocommerce/filled-cart-block -->

<!-- wp:woocommerce/empty-cart-block -->
<div class="wp-block-woocommerce-empty-cart-block"><!-- wp:heading {"textAlign":"center","className":"with-empty-cart-icon wc-block-cart__empty-cart__title"} -->
<h2 class="wp-block-heading has-text-align-center with-empty-cart-icon wc-block-cart__empty-cart__title">Your cart is currently empty!</h2>
<!-- /wp:heading -->

<!-- wp:separator {"className":"is-style-dots"} -->
<hr class="wp-block-separator has-alpha-channel-opacity is-style-dots"/>
<!-- /wp:separator -->

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">Please add a product to your cart:</h2>
<!-- /wp:heading -->

' . zfs_product_collection( $swag, 5, 'menu_order', 1 ) . '</div>
<!-- /wp:woocommerce/empty-cart-block --></div>
<!-- /wp:woocommerce/cart -->';
$page_ids['cart'] = zfs_upsert_page( 'cart', 'Cart', $cart, $src_pages['cart']['date'] );

$checkout_id = (int) get_option( 'woocommerce_checkout_page_id' );
$checkout    = $checkout_id ? get_post( $checkout_id ) : null;
$checkout_c  = ( $checkout && false !== strpos( $checkout->post_content, 'wp:woocommerce/checkout' ) )
	? $checkout->post_content
	: "<!-- wp:woocommerce/checkout -->\n<div class=\"wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading\"></div>\n<!-- /wp:woocommerce/checkout -->";
$page_ids['checkout'] = zfs_upsert_page( 'checkout', 'Checkout', $checkout_c, $src_pages['checkout']['date'] );

$acct   = get_page_by_path( 'my-account' );
$acct_c = ( $acct && '' !== trim( $acct->post_content ) ) ? $acct->post_content : "<!-- wp:woocommerce/classic-shortcode {\"shortcode\":\"my-account\"} /-->";
$page_ids['my-account'] = zfs_upsert_page( 'my-account', html_entity_decode( $src_pages['my-account']['title']['rendered'] ), $acct_c, $src_pages['my-account']['date'] );
$page_ids['shop']       = zfs_upsert_page( 'shop', html_entity_decode( $src_pages['shop']['title']['rendered'] ), '', $src_pages['shop']['date'] );

update_option( 'woocommerce_shop_page_id', $page_ids['shop'] );
update_option( 'woocommerce_cart_page_id', $page_ids['cart'] );
update_option( 'woocommerce_checkout_page_id', $page_ids['checkout'] );
update_option( 'woocommerce_myaccount_page_id', $page_ids['my-account'] );
update_option( 'woocommerce_terms_page_id', $page_ids['c-terms'] );

update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $page_ids['home'] );
update_option( 'page_for_posts', 0 );

// Source has no posts: remove the default "Hello world!" post.
$hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
if ( $hello ) {
	wp_delete_post( $hello->ID, true );
}

/* ------------------------------------------------------------------ */
/* 6. Menu                                                             */
/* ------------------------------------------------------------------ */
$menu_items = array(
	array( 'Register', 'registration' ),
	array( 'Merchandise', 'shop' ),
	array( 'Cart', 'cart' ),
	array( 'Home', 'home' ),
);
$menu = wp_get_nav_menu_object( 'Primary' );
$menu_id = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( 'Primary' );
foreach ( (array) wp_get_nav_menu_items( $menu_id ) as $old ) {
	wp_delete_post( $old->ID, true );
}
$pos = 1;
foreach ( $menu_items as $mi ) {
	wp_update_nav_menu_item(
		$menu_id,
		0,
		array(
			'menu-item-title'     => $mi[0],
			'menu-item-object'    => 'page',
			'menu-item-object-id' => $page_ids[ $mi[1] ],
			'menu-item-type'      => 'post_type',
			'menu-item-status'    => 'publish',
			'menu-item-position'  => $pos++,
		)
	);
}
$locs = get_registered_nav_menus();
if ( isset( $locs['primary'] ) ) {
	$l            = (array) get_theme_mod( 'nav_menu_locations', array() );
	$l['primary'] = $menu_id;
	set_theme_mod( 'nav_menu_locations', $l );
	zfs_log( "  menu Primary #$menu_id assigned to 'primary'" );
} else {
	zfs_log( "  menu Primary #$menu_id created; active theme has no 'primary' location yet (assign after theme switch)" );
}

// Block-theme equivalent: a wp_navigation post with the same links.
$nav_links = array();
foreach ( $menu_items as $mi ) {
	$pid         = $page_ids[ $mi[1] ];
	$nav_links[] = '<!-- wp:navigation-link ' . wp_json_encode(
		array(
			'label' => $mi[0],
			'type'  => 'page',
			'id'    => $pid,
			'url'   => get_permalink( $pid ),
			'kind'  => 'post-type',
		)
	) . ' /-->';
}
$nav = get_posts( array( 'post_type' => 'wp_navigation', 'name' => 'primary', 'post_status' => 'any', 'numberposts' => 1 ) );
$nav_arr = array(
	'post_type'    => 'wp_navigation',
	'post_status'  => 'publish',
	'post_title'   => 'Primary',
	'post_name'    => 'primary',
	'post_content' => implode( "\n", $nav_links ),
);
if ( $nav ) {
	$nav_arr['ID'] = $nav[0]->ID;
}
$nav_id = wp_insert_post( wp_slash( $nav_arr ) );

// Footer text as a synced pattern the theme can reference.
$fp     = get_posts( array( 'post_type' => 'wp_block', 'name' => 'footer-copyright', 'post_status' => 'any', 'numberposts' => 1 ) );
$fp_arr = array(
	'post_type'    => 'wp_block',
	'post_status'  => 'publish',
	'post_title'   => 'Footer Copyright',
	'post_name'    => 'footer-copyright',
	'post_content' => zfs_paragraph_block( 'Copyright 2026 OpenZFS' ),
);
if ( $fp ) {
	$fp_arr['ID'] = $fp[0]->ID;
}
$footer_id = wp_insert_post( wp_slash( $fp_arr ) );

/* ------------------------------------------------------------------ */
/* 7. My Calendar event                                                */
/* ------------------------------------------------------------------ */
$events = 0;
if ( function_exists( 'mc_insert_event' ) ) {
	global $wpdb;
	if ( function_exists( 'mc_update_option' ) ) {
		mc_update_option( 'uri_id', $page_ids['sessions'] );
		mc_update_option( 'show_weekends', 'false' );
	}
	$title = 'OpenZFS Summit Day 1';
	$have  = $wpdb->get_var( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}my_calendar WHERE event_title = %s", $title ) );
	$cat   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT category_id FROM {$wpdb->prefix}my_calendar_categories WHERE category_name = %s", 'General' ) );
	if ( ! $have ) {
		$user = wp_get_current_user();
		$post = array(
			'event_nonce_name' => wp_create_nonce( 'event_nonce' ),
			'event_title'      => $title,
			'content'          => '',
			'event_desc'       => '',
			'event_short'      => '',
			'event_begin'      => array( '2026-10-27' ),
			'event_end'        => array( '2026-10-30' ),
			'event_time'       => array( '09:30' ),
			'event_endtime'    => array( '18:00' ),
			'event_category'   => array( $cat ? $cat : 1 ),
			'primary_category' => $cat ? $cat : 1,
			'event_recur'      => 'S',
			'event_repeats'    => '0',
			'event_every'      => '1',
			'event_approved'   => 1,
			'event_author'     => $user->ID,
			'event_host'       => $user->ID,
			'event_group_id'   => 0,
			'location_preset'  => 'none',
			'preset_location'  => '',
			'event_link'       => '',
		);
		$r = mc_insert_event( 'add', $post, 0 );
		if ( empty( $r['event_id'] ) ) {
			zfs_log( '  My Calendar insert failed: ' . wp_strip_all_tags( (string) ( $r['message'] ?? '' ) ) );
		} else {
			zfs_log( "  My Calendar event #{$r['event_id']} created" );
		}
	} else {
		zfs_log( "  My Calendar event #$have already present" );
	}
	// Approve it (the cap check inside My Calendar can leave it pending
	// under wp-cli) and publish its mc-events post.
	$wpdb->update( "{$wpdb->prefix}my_calendar", array( 'event_approved' => 1 ), array( 'event_title' => $title ) );
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT event_post FROM {$wpdb->prefix}my_calendar WHERE event_title = %s", $title ) ) as $ep ) {
		if ( $ep && 'publish' !== get_post_status( $ep ) ) {
			wp_update_post( array( 'ID' => $ep, 'post_status' => 'publish' ) );
		}
	}
	// The source has only this event: drop My Calendar's install demo event.
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT event_id FROM {$wpdb->prefix}my_calendar WHERE event_title <> %s", $title ) ) as $other ) {
		$ep = $wpdb->get_var( $wpdb->prepare( "SELECT event_post FROM {$wpdb->prefix}my_calendar WHERE event_id = %d", $other ) );
		if ( function_exists( 'mc_delete_event' ) ) {
			mc_delete_event( (int) $other );
		} else {
			$wpdb->delete( "{$wpdb->prefix}my_calendar_events", array( 'occur_event_id' => $other ) );
			$wpdb->delete( "{$wpdb->prefix}my_calendar", array( 'event_id' => $other ) );
		}
		if ( $ep && get_post( $ep ) ) {
			wp_delete_post( (int) $ep, true );
		}
		zfs_log( "  removed non-source My Calendar event #$other" );
	}
	$events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}my_calendar" );
} else {
	zfs_log( 'WARNING: My Calendar not loaded; event not created' );
}

flush_rewrite_rules( false );

/* ------------------------------------------------------------------ */
/* Summary                                                             */
/* ------------------------------------------------------------------ */
$summary = array(
	'logo_attachment_id' => $logo_id,
	'media_imported'     => count( array_unique( $zfs_media ) ),
	'pages'              => $page_ids,
	'products'           => $prod_map,
	'variations'         => $var_count,
	'menu_id'            => $menu_id,
	'wp_navigation_id'   => $nav_id,
	'footer_pattern_id'  => $footer_id,
	'mc_events'          => $events,
);
WP_CLI::log( 'SUMMARY ' . wp_json_encode( $summary ) );
