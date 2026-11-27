<?php
/**
 * Returns where WooCommerce sends a shopper after registering. Runs in a test store process (see RegistrationRedirectTest).
 */

// phpcs:disable

return apply_filters( 'woocommerce_registration_redirect', wc_get_page_permalink( 'myaccount' ) );
