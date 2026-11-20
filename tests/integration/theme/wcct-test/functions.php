<?php
// Classic theme with WooCommerce support, so WooCommerce's PHP templates and their hooks are used.
add_action(
    'after_setup_theme',
    function () {
        add_theme_support( 'woocommerce' );
    }
);
