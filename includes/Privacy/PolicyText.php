<?php

namespace StorePulse\ConversionTracking\Privacy;

/**
 * Suggested privacy policy text for Settings > Privacy > Policy Guide.
 */
class PolicyText {

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public static function register() {
        add_action( 'admin_init', array( self::class, 'add' ) );
    }

    /**
     * Add the text to the policy guide.
     *
     * @return void
     */
    public static function add() {
        if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
            wp_add_privacy_policy_content( __( 'Conversion Tracking for WooCommerce', 'woocommerce-conversion-tracking' ), self::content() );
        }
    }

    /**
     * The suggested text, as HTML.
     *
     * @return string
     */
    public static function content(): string {
        $paragraphs = array(
            '<p class="privacy-policy-tutorial">' . esc_html__( 'Conversion Tracking for WooCommerce adds the tags of the advertising and analytics platforms you turn on to your store pages. Keep the platforms below that you use, and link to their privacy policies.', 'woocommerce-conversion-tracking' ) . '</p>',
            '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'woocommerce-conversion-tracking' ) . ' </strong>' . esc_html__( 'We use advertising and analytics tags to measure how our ads and store perform. When you visit our store, these tags may set cookies and send the following to the platforms below: the pages and products you view, searches, products you add to your cart, the start of checkout, account sign-ups, and for purchases the order number, order value, currency and the products bought. The platforms also receive technical details from your browser, such as your IP address, browser type and the page address. Order keys are removed from the page addresses we share.', 'woocommerce-conversion-tracking' ) . '</p>',
            '<p>' . esc_html__( 'Data may be sent to:', 'woocommerce-conversion-tracking' ) . '</p>',
            '<ul>'
            . '<li>' . esc_html__( 'Meta (Facebook and Instagram) through the Meta Pixel; on the page that confirms your order this can include your billing name, email address, phone number, city, state, postcode and customer ID, and on other pages the email address of your account while you are logged in, all hashed before they are sent (advanced matching)', 'woocommerce-conversion-tracking' ) . '</li>'
            . '<li>' . esc_html__( 'Google through Google Analytics 4 and Google Ads', 'woocommerce-conversion-tracking' ) . '</li>'
            . '<li>' . esc_html__( 'X (Twitter) through the X Pixel', 'woocommerce-conversion-tracking' ) . '</li>'
            . '<li>' . esc_html__( 'Any other service whose script we add as a custom script; with "Include customer details in scripts" on, these scripts can receive your customer ID, email address and name on the page that confirms your own order', 'woocommerce-conversion-tracking' ) . '</li>'
            . '</ul>',
            '<p>' . esc_html__( 'If we ask for your cookie choices, the tags wait for your consent before they send anything that needs it.', 'woocommerce-conversion-tracking' ) . '</p>',
            '<p>' . esc_html__( 'Our store keeps a note on each order of the platforms its purchase was sent to, so a purchase is counted once, and a log of recent orders for up to 24 hours to check that tracking works. The log holds no customer details.', 'woocommerce-conversion-tracking' ) . '</p>',
        );

        return implode( "\n", $paragraphs );
    }
}
