<?php

namespace StorePulse\ConversionTracking\Log;

use StorePulse\ConversionTracking\Events\OrderReceived;

/**
 * Server flag: the thank-you page rendered for the order, whatever the browser does next.
 */
class ThankYouSeen {

    /**
     * Returns the active platforms.
     *
     * @var callable
     */
    private $platform_source;

    /**
     * Flag over the platforms a callable returns.
     *
     * @param callable $platform_source Returns the active platforms.
     */
    public function __construct( callable $platform_source ) {
        $this->platform_source = $platform_source;
    }

    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function init_hooks() {
        add_action( 'template_redirect', array( $this, 'record' ) );
    }

    /**
     * Write the flag once, on the first render of a thank-you page WooCommerce shows the order on.
     *
     * @return void
     */
    public function record() {
        if ( is_admin() || empty( call_user_func( $this->platform_source ) ) ) {
            return;
        }

        $order = OrderReceived::get_order();

        if ( ! $order || CheckIn::thankyou_seen( $order ) ) {
            return;
        }

        $order->update_meta_data( CheckIn::THANKYOU_META, time() );
        // Meta only: no order save, so no order.updated webhooks.
        $order->save_meta_data();
    }
}
