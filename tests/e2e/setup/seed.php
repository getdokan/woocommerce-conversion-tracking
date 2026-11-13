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

// AJAX buttons on the shop page, no redirect to the cart, and sign-up on My account and at checkout.
update_option( 'woocommerce_enable_ajax_add_to_cart', 'yes' );
update_option( 'woocommerce_cart_redirect_after_add', 'no' );
update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
update_option( 'woocommerce_registration_generate_username', 'yes' );
update_option( 'woocommerce_registration_generate_password', 'yes' );

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

// Block add to cart: a Product Collection with the Product Button block, which adds through the Store API.
if ( ! get_page_by_path( 'block-shop' ) ) {
    wp_insert_post(
        array(
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'Block shop',
            'post_name'    => 'block-shop',
            'post_content' => '<!-- wp:woocommerce/product-collection {"queryId":1,"query":{"perPage":4,"pages":0,"offset":0,"postType":"product","order":"asc","orderBy":"title","search":"","exclude":[],"inherit":false,"taxQuery":{},"isProductCollectionBlock":true,"woocommerceOnSale":false,"woocommerceStockStatus":["instock","outofstock","onbackorder"],"woocommerceAttributes":[],"woocommerceHandPickedProducts":[]},"tagName":"div","displayLayout":{"type":"flex","columns":3},"collection":"woocommerce/product-collection/product-catalog"} --><div class="wp-block-woocommerce-product-collection"><!-- wp:woocommerce/product-template --><!-- wp:post-title {"isLink":true,"__woocommerceNamespace":"woocommerce/product-collection/product-title"} /--><!-- wp:woocommerce/product-price {"isDescendentOfQueryLoop":true} /--><!-- wp:woocommerce/product-button {"isDescendentOfQueryLoop":true} /--><!-- /wp:woocommerce/product-template --></div><!-- /wp:woocommerce/product-collection -->',
        )
    );
}

// Tracking settings, in the shape the settings page saves. Test IDs only.
$ct_e2e_settings = array(
    'facebook' => array(
        'enabled' => true,
        array(
            'pixel_id' => $ct_e2e_ids['meta']['pixelId'],
            'events'   => array(
                'ViewContent'      => 'on',
                'AddToCart'        => 'on',
                'InitiateCheckout' => 'on',
                'Purchase'         => 'on',
                'Registration'     => 'on',
                // Pro's Meta reads the sign-up switch under this key.
                'CompleteRegistration' => 'on',
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
                'search'         => 'on',
                'sign_up'        => 'on',
            ),
        ),
    ),
    'adwords'  => array(
        'enabled' => true,
        array(
            'account_id' => $ct_e2e_ids['ads']['conversionId'],
            'events'     => array(
                'Purchase'       => 'on',
                'Purchase-label' => $ct_e2e_ids['ads']['purchaseLabel'],
            ),
        ),
    ),
    'twitter'  => array(
        'enabled' => true,
        array(
            'pixel_id' => $ct_e2e_ids['x']['pixelId'],
            'events'   => array(
                'Purchase'          => 'on',
                'purchase_event_id' => $ct_e2e_ids['x']['purchaseEventId'],
            ),
        ),
    ),
);

// With Pro: X's optional events and Microsoft, so their requests are checked too. Start checkout IDs are shared with specs/begin-checkout.spec.ts.
$ct_e2e_checkout_ids = json_decode( file_get_contents( __DIR__ . '/../config/checkout-ids.json' ), true );

// A Pro left in active_plugins after its mapping was removed doesn't count, nor a Pro older than 3.0, which Free pauses.
if ( is_plugin_active( 'wc-conversion-tracking-pro/woocommerce-conversion-tracking-pro.php' ) && file_exists( WP_PLUGIN_DIR . '/wc-conversion-tracking-pro/woocommerce-conversion-tracking-pro.php' ) && \StorePulse\ConversionTracking\Compat\ProVersion::is_active() ) {
    $ct_e2e_settings['twitter'][0]['events'] += array(
        'AddToCart'         => 'on',
        'cart_event_id'     => 'tw-cte2x-ctadd',
        'InitiateCheckout'  => 'on',
        'checkout_event_id' => $ct_e2e_checkout_ids['xCheckoutEventId'],
        'Registration'      => 'on',
        'signup_event_id'   => 'tw-cte2x-ctreg',
    );
    // Google Registration is an Ads conversion with its own label (#87).
    $ct_e2e_settings['adwords'][0]['events'] += array(
        'Registration'       => 'on',
        'registration-label' => $ct_e2e_ids['ads']['registrationLabel'],
    );
    $ct_e2e_settings['bing_ads'] = array(
        'enabled' => true,
        array(
            'tag_id' => $ct_e2e_checkout_ids['microsoftTagId'],
            'events' => array(
                'AddToCart'     => 'on',
                'BeginCheckout' => 'on',
                'Purchase'      => 'on',
            ),
        ),
    );
}

// A 2.x store, migrated below. Seeding again starts over, as no 2.x write reaches the 3.0 settings.
foreach ( array( 'wcct_settings_v3', 'wcct_migration_done', 'wcct_migration_lock', 'wcct_migration_error', 'wcct_pro_migration_done', 'wcct_pro_migration_lock', 'wcct_migration_flags', 'wcct_pre3_snapshot', 'wcct_paused_platforms', 'wcct_paused_since' ) as $ct_e2e_option ) {
    delete_option( $ct_e2e_option );
}

update_option( 'wcct_settings', $ct_e2e_settings );

// The store runs on the 3.0 settings from here: the migration writes them now instead of on the first page view.
\StorePulse\ConversionTracking\Migration\SettingsMigration::maybe_run();

if ( ! is_array( get_option( 'wcct_settings_v3', null ) ) ) {
    WP_CLI::error( 'The 3.0 settings were not written.' );
}

// A shop manager for the Integration access specs; only administrators have access until a spec grants it.
if ( ! get_user_by( 'login', 'ct_e2e_shop_manager' ) ) {
    wp_insert_user(
        array(
            'user_login' => 'ct_e2e_shop_manager',
            'user_pass'  => 'password',
            'user_email' => 'shop-manager@example.test',
            'role'       => 'shop_manager',
        )
    );
}

delete_option( 'wcct_permissions' );

WP_CLI::success( sprintf( 'Seeded the e2e store (product #%d).', $ct_e2e_product->get_id() ) );
