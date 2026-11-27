<?php
/**
 * wcct_settings values in the shapes 2.x saved them (fake IDs), for the 3.0 migration tests (#122).
 *
 * 2.x's settings page posts every integration's form, so switched-off integrations are saved too; unchecked events are absent and checked ones are 'on'.
 */

// phpcs:disable

$off = array(
    'facebook' => array( 'enabled' => false, 0 => array( 'pixel_id' => '' ) ),
    'adwords'  => array( 'enabled' => false, 0 => array( 'account_id' => '', 'events' => array( 'Purchase-label' => '' ) ) ),
    'ga4'      => array( 'enabled' => false, 0 => array( 'measurement_id' => '' ) ),
    'twitter'  => array( 'enabled' => false, 0 => array( 'events' => array( 'universal_tag_id' => '' ) ) ),
    'custom'   => array( 'enabled' => false, 'checkout' => '', 'registration' => '' ),
);

return array(
    // 2.1.6 with every Lite integration on and every Free event checked.
    'all-on'                    => array(
        'facebook' => array( 'enabled' => true, 0 => array( 'pixel_id' => '123456789012345', 'events' => array( 'AddToCart' => 'on', 'InitiateCheckout' => 'on', 'Purchase' => 'on', 'Registration' => 'on' ) ) ),
        'adwords'  => array( 'enabled' => true, 0 => array( 'account_id' => 'AW-123456789', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'AbCdEfGh12' ) ) ),
        'ga4'      => array( 'enabled' => true, 0 => array( 'measurement_id' => 'G-ABCDE12345', 'events' => array( 'view_item' => 'on', 'add_to_cart' => 'on', 'begin_checkout' => 'on', 'purchase' => 'on' ) ) ),
        'twitter'  => array( 'enabled' => true, 0 => array( 'events' => array( 'Purchase' => 'on', 'universal_tag_id' => 'o8x2k' ) ) ),
        'custom'   => array( 'enabled' => true, 'checkout' => "<script>ct('{order_number}', '{order_total}', '{customer_email}');</script>", 'registration' => "<script>ct('signup');</script>" ),
    ),

    // Only Meta on, the rest posted switched off and empty.
    'meta-only'                 => array_merge(
        $off,
        array( 'facebook' => array( 'enabled' => true, 0 => array( 'pixel_id' => ' 123456789012345 ', 'events' => array( 'AddToCart' => 'on', 'Purchase' => 'on' ) ) ) )
    ),

    // GA4 alone, with the search and sign_up events 3.0's 2.x-style page saves.
    'ga4-only'                  => array_merge(
        $off,
        array( 'ga4' => array( 'enabled' => true, 0 => array( 'measurement_id' => 'G-ABCDE12345', 'events' => array( 'purchase' => 'on', 'search' => 'on', 'sign_up' => 'on' ) ) ) )
    ),

    // Ads alone, GA4 off with an ID left in it.
    'ads-only'                  => array_merge(
        $off,
        array(
            'adwords' => array( 'enabled' => true, 0 => array( 'account_id' => 'AW-123456789', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'AbCdEfGh12' ) ) ),
            'ga4'     => array( 'enabled' => false, 0 => array( 'measurement_id' => 'G-OLD0000001', 'events' => array( 'purchase' => 'on' ) ) ),
        )
    ),

    // The whole send_to pasted into Account ID, label field left empty.
    'ads-id-with-label'         => array(
        'adwords' => array( 'enabled' => true, 0 => array( 'account_id' => 'AW-123456789/AbC-dEf_12', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => '' ) ) ),
    ),

    // send_to pasted into Account ID in lower case, and a label typed as well.
    'ads-id-and-label'          => array(
        'adwords' => array( 'enabled' => true, 0 => array( 'account_id' => ' aw-987654321 / OldLabel ', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'NewLabel' ) ) ),
    ),

    // send_to pasted into the label field.
    'ads-label-with-id'         => array(
        'adwords' => array( 'enabled' => true, 0 => array( 'account_id' => 'AW-555555555', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'AW-555555555/Lbl55' ) ) ),
    ),

    // A label with a slash of its own, and an Ads ID ending in a slash: neither is split.
    'ads-odd-slashes'           => array(
        'adwords' => array( 'enabled' => true, 0 => array( 'account_id' => 'AW-123456789/', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'abc/def' ) ) ),
    ),

    // The 2.0 upgrade saved the 1.x scripts flat at the top level, where 2.x never read them.
    'flat-2-0'                  => array(
        'enabled'      => 1,
        'cart'         => '<script>cart()</script>',
        'checkout'     => "<script>ct('{order_total}', '{customer_email}');</script>",
        'registration' => '<script>reg()</script>',
    ),

    // X from Pro 1.x: the universal tag, single-event labels, key casing as Pro saved it.
    'x-pro-labels'              => array(
        'twitter' => array( 'enabled' => true, 0 => array( 'events' => array( 'Purchase' => 'on', 'universal_tag_id' => 'o8x2k', 'AddToCart' => 'on', 'AddtoCart-label' => 'l5abc', 'Registration' => 'on', 'registration-label' => 'l5def' ) ) ),
    ),

    // X switched off with its tag kept.
    'x-off-with-tag'            => array(
        'twitter' => array( 'enabled' => false, 0 => array( 'events' => array( 'Purchase' => 'on', 'universal_tag_id' => 'o8x2k' ) ) ),
    ),

    // X with the tag but Purchase unchecked: nothing to re-set up.
    'x-tag-only'                => array(
        'twitter' => array( 'enabled' => true, 0 => array( 'events' => array( 'universal_tag_id' => 'o8x2k' ) ) ),
    ),

    // Integrations switched off with their IDs kept read as paused.
    'off-with-ids'              => array(
        'facebook' => array( 'enabled' => false, 0 => array( 'pixel_id' => '123456789012345', 'events' => array( 'Purchase' => 'on' ) ) ),
        'adwords'  => array( 'enabled' => false, 0 => array( 'account_id' => 'AW-123456789', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'AbCdEfGh12' ) ) ),
        'ga4'      => array( 'enabled' => false, 0 => array( 'measurement_id' => 'G-ABCDE12345', 'events' => array( 'purchase' => 'on' ) ) ),
        'custom'   => array( 'enabled' => false, 'checkout' => '<script>ct()</script>', 'registration' => '' ),
    ),

    // enabled as the strings an older form or a hand edit left; 2.x read 'on' as off (wc_string_to_bool()).
    'string-switches'           => array(
        'facebook' => array( 'enabled' => '1', 0 => array( 'pixel_id' => '123456789012345', 'events' => array( 'Purchase' => 'on' ) ) ),
        'ga4'      => array( 'enabled' => 'true', 0 => array( 'measurement_id' => 'G-ABCDE12345', 'events' => array( 'purchase' => 'on' ) ) ),
        'adwords'  => array( 'enabled' => 'on', 0 => array( 'account_id' => 'AW-123456789', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'AbCdEfGh12' ) ) ),
        'twitter'  => array( 'enabled' => 'false', 0 => array( 'events' => array( 'Purchase' => 'on', 'universal_tag_id' => 'o8x2k' ) ) ),
    ),

    // Switched on with nothing filled in.
    'on-with-empty-ids'         => array(
        'facebook' => array( 'enabled' => true, 0 => array( 'pixel_id' => '', 'events' => array( 'Purchase' => 'on' ) ) ),
        'ga4'      => array( 'enabled' => true, 0 => array( 'measurement_id' => '' ) ),
        'adwords'  => array( 'enabled' => true ),
        'twitter'  => array( 'enabled' => true ),
        'custom'   => array( 'enabled' => true ),
    ),

    // Custom: a customer placeholder only in the registration script.
    'custom-signup-placeholder' => array(
        'custom' => array( 'enabled' => true, 'checkout' => "<script>ct('{order_total}');</script>", 'registration' => "<script>ct('{customer_id}');</script>" ),
    ),

    // Custom: order placeholders only.
    'custom-no-customer'        => array(
        'custom' => array( 'enabled' => true, 'checkout' => "<script>ct('{order_number}', '{order_total}', '{used_coupons}');</script>", 'registration' => '' ),
    ),

    // Pro 1.x keys Lite doesn't map (extra Meta pixels with a gap, Microsoft, Perfect Audience); Pro's own view adds them.
    'pro-shapes'                => array(
        'facebook'         => array(
            'enabled' => true,
            0         => array( 'pixel_id' => '123456789012345', 'events' => array( 'Purchase' => 'on', 'ViewContent' => 'on', 'Search' => 'on' ) ),
            2         => array( 'pixel_id' => '222222222222222', 'events' => array( 'Purchase' => 'on' ) ),
        ),
        'adwords'          => array( 'enabled' => true, 0 => array( 'account_id' => 'AW-123456789', 'events' => array( 'Purchase' => 'on', 'Purchase-label' => 'AbCdEfGh12', 'Registration' => 'on', 'registration-label' => 'RegLbl1' ) ) ),
        'bing_ads'         => array( 'enabled' => true, 0 => array( 'tag_id' => '12345678', 'events' => array( 'Purchase' => 'on' ) ) ),
        'perfect_audience' => array( 'enabled' => true, 0 => array( 'advertiser_id' => '1a2b', 'events' => array( 'Purchase' => 'on' ) ) ),
    ),

    // Bad data: wrong types everywhere.
    'malformed'                 => array(
        'facebook' => 'garbage',
        'adwords'  => array( 0 => 'x', 'enabled' => array() ),
        'ga4'      => null,
        'twitter'  => array( 'enabled' => true, 0 => array( 'events' => 'nope' ) ),
        'custom'   => array( 'enabled' => true, 'checkout' => array( 'x' ), 'registration' => 5 ),
        7          => 'stray',
    ),

    // Saved once with nothing in it.
    'empty'                     => array(),
);
