<?php
/**
 * Creates an account as My account does, then builds the footer markup of two page views as the new customer. Runs in a test store process (see CustomPlacementsTest).
 */

// phpcs:disable

use StorePulse\ConversionTracking\Events\PageEvents;

$pipeline = wcct_init()->event_dispatcher->get_pipeline();
$customer = wc_create_new_customer( 'custom-signup@example.com', 'customsignup', 'Pass-123456' );

wp_set_current_user( $customer );

return array(
    'first_view'  => $pipeline->build_footer_markup( PageEvents::current() ),
    'second_view' => $pipeline->build_footer_markup( PageEvents::current() ),
);
