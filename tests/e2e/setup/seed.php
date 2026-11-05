<?php
/**
 * Seeds the store, the products and the tracking settings. Run by setup.sh with wp eval-file.
 */

// phpcs:disable

$ct_e2e_ids = json_decode( file_get_contents( __DIR__ . '/../config/test-ids.json' ), true );

// Store basics: open to guests, US store in USD, no taxes.
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_enable_checkout_login_reminder', 'no' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );

// Cash on delivery is the non-card payment method the specs use.
update_option(
    'woocommerce_cod_settings',
    array_merge(
        (array) get_option( 'woocommerce_cod_settings', array() ),
        array(
            'enabled'            => 'yes',
            'title'              => 'Cash on delivery',
            'enable_for_methods' => array(),
            'enable_for_virtual' => 'yes',
        )
    )
);

// A wp-env restart with a changed config can drop uploads; put the placeholder image back so pages have no 404s.
$ct_e2e_placeholder      = (int) get_option( 'woocommerce_placeholder_image' );
$ct_e2e_placeholder_file = $ct_e2e_placeholder ? get_attached_file( $ct_e2e_placeholder ) : '';

if ( $ct_e2e_placeholder_file && ! file_exists( $ct_e2e_placeholder_file ) ) {
    require_once ABSPATH . 'wp-admin/includes/image.php';

    wp_mkdir_p( dirname( $ct_e2e_placeholder_file ) );
    copy( WC()->plugin_path() . '/assets/images/placeholder-attachment.webp', $ct_e2e_placeholder_file );
    wp_update_attachment_metadata( $ct_e2e_placeholder, wp_generate_attachment_metadata( $ct_e2e_placeholder, $ct_e2e_placeholder_file ) );
}

// A virtual product, so no shipping setup is needed.
$ct_e2e_product_id = wc_get_product_id_by_sku( 'CT-E2E-SIMPLE' );
$ct_e2e_product    = $ct_e2e_product_id ? wc_get_product( $ct_e2e_product_id ) : new WC_Product_Simple();
$ct_e2e_product->set_props(
    array(
        'name'          => 'CT E2E Product',
        'slug'          => 'ct-e2e-product',
        'sku'           => 'CT-E2E-SIMPLE',
        'regular_price' => '25.00',
        'virtual'       => true,
        'status'        => 'publish',
    )
);
$ct_e2e_product->save();

// Block checkout is WooCommerce's checkout page; classic checkout is a separate shortcode page.
$ct_e2e_checkout = get_post( wc_get_page_id( 'checkout' ) );

if ( ! $ct_e2e_checkout || ! has_block( 'woocommerce/checkout', $ct_e2e_checkout ) ) {
    WP_CLI::error( 'The WooCommerce checkout page does not use the checkout block.' );
}

if ( ! get_page_by_path( 'classic-checkout' ) ) {
    wp_insert_post(
        array(
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Classic checkout',
            'post_name'    => 'classic-checkout',
            'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
        )
    );
}

// Tracking settings, in the shape the settings page saves. Test IDs only.
update_option(
    'wcct_settings',
    array(
        'facebook' => array(
            'enabled' => true,
            array(
                'pixel_id' => $ct_e2e_ids['meta']['pixelId'],
                'events'   => array(
                    'AddToCart'        => 'on',
                    'InitiateCheckout' => 'on',
                    'Purchase'         => 'on',
                ),
            ),
        ),
        'ga4'      => array(
            'enabled' => true,
            array(
                'measurement_id' => $ct_e2e_ids['ga4']['measurementId'],
                'events'         => array(
                    'view_item'      => 'on',
                    'add_to_cart'    => 'on',
                    'begin_checkout' => 'on',
                    'purchase'       => 'on',
                ),
            ),
        ),
    )
);

WP_CLI::success( sprintf( 'Seeded the e2e store (product #%d).', $ct_e2e_product->get_id() ) );
