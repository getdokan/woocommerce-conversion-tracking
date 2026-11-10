<?php

use StorePulse\ConversionTracking\Events\Event;
use StorePulse\ConversionTracking\Events\EventFactory;
use StorePulse\ConversionTracking\Events\PurchaseConfirmation;
use StorePulse\ConversionTracking\Events\PurchaseGate;
use StorePulse\ConversionTracking\Events\ValueMode;
use StorePulse\ConversionTracking\Platforms\GoogleTag;
use StorePulse\ConversionTracking\Platforms\Pause;

/**
 * Abstract Class
 */
abstract class WCCT_Integration {

    /**
     * Store integration's id
     *
     * @var [type]
     */
    protected $id;

    /**
     * Store integration's name
     *
     * @var string
     */
    protected $name;

    /**
     * Check enable
     *
     * @var boolean
     */
    protected $enabled;

    /**
     * Store integration's settings
     *
     * @var array
     */
    protected $settings = array();

    /**
     * Store all supported features
     *
     * @var array
     */
    protected $supports = array();

    /**
     * How the integration sends events; only 'browser' in 3.0
     *
     * @var array
     */
    protected $transports = array( 'browser' );

    /**
     * Pipeline event name => the method that renders it
     *
     * @var array
     */
    protected static $event_methods = array(
        'add_to_cart'     => 'add_to_cart',
        'begin_checkout'  => 'initiate_checkout',
        'purchase'        => 'checkout',
        'view_product'    => 'product_view',
        'view_category'   => 'category_view',
        'search'          => 'search',
        'sign_up'         => 'registration',
        'add_to_wishlist' => 'add_to_wishlist',
    );

    /**
     * Event being rendered by get_event_script(), null otherwise
     *
     * @var \StorePulse\ConversionTracking\Events\Event|null
     */
    protected $current_event = null;

    /**
     * Order IDs whose Purchase this request already rendered
     *
     * @var array
     */
    private $purchases_rendered = array();

    /**
     * WP Consent API category this integration's tags need
     *
     * @var string
     */
    protected $consent_category = 'marketing';

    /**
     * Get settings for all integration
     *
     * @return array
     */
    abstract public function get_settings();

    /**
     * Enqueue integration script
     *
     * @return void
     */
    abstract public function enqueue_script();

    /**
     * Get the integration id
     *
     * @return [type] [description]
     */
    public function get_id() {
        return $this->id;
    }

    /**
     * Get the integration name
     *
     * @return string
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Whether the 2.x settings page switch is on
     *
     * @return boolean
     */
    public function is_switched_on() {
        return $this->is_enabled();
    }

    /**
     * Whether the integration is on (its pause is checked apart, see is_active())
     *
     * @return boolean
     */
    public function is_enabled() {
        $settings = $this->get_integration_settings();

        // A malformed option (say, a string, or an array as the switch) reads as off instead of a fatal.
        return is_array( $settings ) && isset( $settings['enabled'] ) && is_scalar( $settings['enabled'] ) && wc_string_to_bool( $settings['enabled'] );
    }

    /**
     * Whether tracking is paused, for this integration or its settings platform (ga4 and adwords follow google)
     *
     * @return boolean
     */
    public function is_paused() {
        return Pause::is_paused( (string) $this->id );
    }

    /**
     * Enabled and not paused
     *
     * @return boolean
     */
    public function is_active() {
        return $this->is_enabled() && ! $this->is_paused();
    }

    /**
     * Whether the logged-in visitor is in a role this integration skips
     *
     * @return boolean
     */
    public function skips_current_user() {
        return \StorePulse\ConversionTracking\Events\SkipRoles::skips_current_user( $this->id );
    }

    /**
     * How the integration sends events
     *
     * @return array
     */
    public function get_transports() {
        return $this->transports;
    }

    /**
     * JavaScript for this page's footer script, without script tags
     *
     * @param \StorePulse\ConversionTracking\Events\Event[] $events Events decided on page load.
     *
     * @return string
     */
    public function get_footer_script( array $events ) {
        $code = '';

        foreach ( $events as $event ) {
            $script = $this->get_event_script( $event );
            $code  .= '' !== $script ? $script . "\n" : '';
        }

        return $code;
    }

    /**
     * Raw HTML printed after the footer scripts, for integrations whose output isn't JavaScript
     *
     * @param \StorePulse\ConversionTracking\Events\Event[] $events Events decided on page load.
     *
     * @return string
     */
    public function get_footer_markup( array $events ) {
        return '';
    }

    /**
     * JavaScript that sends one event, from the method named in $event_methods, or an empty string
     *
     * @param \StorePulse\ConversionTracking\Events\Event $event Event.
     *
     * @return string
     */
    public function get_event_script( $event ) {
        $name = $event->get_name();

        if ( ! $this->sends_event( $name ) ) {
            return '';
        }

        $method   = static::$event_methods[ $name ];
        $purchase = Event::PURCHASE === $name;

        // Once per order and platform, also when two hooks raise it on one page; skipped statuses never send.
        if ( $purchase && ( isset( $this->purchases_rendered[ $event->get_order_id() ] ) || ! PurchaseGate::should_send( $event->get_order(), $this->id ) ) ) {
            return '';
        }

        $this->current_event = $event;
        $code                = $this->$method( $purchase ? $event->get_order_id() : '' );
        $this->current_event = null;

        if ( ! is_string( $code ) ) {
            return '';
        }

        if ( $purchase && '' !== trim( $code ) ) {
            $this->purchases_rendered[ $event->get_order_id() ] = true;
        }

        return $purchase && '' !== trim( $code ) ? $this->with_purchase_confirmation( $code, $event->get_order() ) : $code;
    }

    /**
     * Purchase code with the confirmation that writes the sent flag once the platform library has taken it
     *
     * @param string    $code  Purchase code.
     * @param \WC_Order $order Order.
     *
     * @return string
     */
    protected function with_purchase_confirmation( $code, $order ) {
        $check = $this->purchase_sent_check();

        if ( false === $check ) {
            return PurchaseConfirmation::helper() . "\n" . $code;
        }

        return PurchaseConfirmation::helper() . "\n" . $code . "\n" . PurchaseConfirmation::confirm_script( $this->id, $order, (string) $check, $this->purchase_consent_category() );
    }

    /**
     * Consent category the Purchase needs before it counts as sent, when consent mode is on; empty for none
     *
     * @return string
     */
    protected function purchase_consent_category() {
        return '';
    }

    /**
     * JS expression, true once the platform library has taken the Purchase; '' confirms at once, false when the code calls purchase_sent_callback()
     *
     * @return string|false
     */
    protected function purchase_sent_check() {
        return '';
    }

    /**
     * JS expression for the function that confirms this Purchase, for library callbacks
     *
     * @param \WC_Order $order Order.
     *
     * @return string
     */
    protected function purchase_sent_callback( $order ) {
        return PurchaseConfirmation::callback( $this->id, $order, $this->checkin_consent_category() );
    }

    /**
     * Consent category whose state the check-in reports: the Purchase's own, else the integration's
     *
     * @return string
     */
    protected function checkin_consent_category() {
        $category = $this->purchase_consent_category();

        return '' !== $category ? $category : $this->get_consent_category();
    }

    /**
     * JS expression, true once the platform library has loaded; the onboarding check reads it
     *
     * @return string
     */
    public function library_loaded_check() {
        $check = $this->purchase_sent_check();

        return is_string( $check ) && '' !== trim( $check ) ? $check : 'true';
    }

    /**
     * The Purchase event being rendered, or one built from the order
     *
     * @param \WC_Order $order Order.
     *
     * @return Event
     */
    protected function purchase_event( $order ) {
        if ( $this->current_event && Event::PURCHASE === $this->current_event->get_name() ) {
            return $this->current_event;
        }

        return EventFactory::purchase( $order );
    }

    /**
     * Purchase value in this platform's value mode, as a decimal string
     *
     * @param \WC_Order $order Order.
     *
     * @return string
     */
    protected function purchase_value( $order ) {
        $event = $this->purchase_event( $order );

        return number_format( $event->get_value_for( ValueMode::for_platform( $this->id ), $this->id ), $event->get_decimals(), '.', '' );
    }

    /**
     * Whether the integration has a method for a pipeline event
     *
     * @param string $name Event name.
     *
     * @return boolean
     */
    public function sends_event( $name ) {
        $method = is_string( $name ) && isset( static::$event_methods[ $name ] ) ? static::$event_methods[ $name ] : '';

        return $method && $this->supports( $method ) && method_exists( $this, $method );
    }

    /**
     * WP Consent API category this integration's tags need
     *
     * @return string
     */
    public function get_consent_category() {
        return $this->consent_category;
    }

    /**
     * Whether consent allows this integration to track, for server-side sends only; see wcct_consent_allows()
     *
     * @return boolean
     */
    public function consent_allows() {
        return wcct_consent_allows( $this->get_consent_category() );
    }

    /**
     * JavaScript calling a callback with (granted, first) now and on every consent change; empty with consent mode off
     *
     * @param string $body Callback body.
     *
     * @return string
     */
    protected function consent_sync_js( $body ) {
        return \StorePulse\ConversionTracking\Consent\ConsentMode::sync_js( $this->get_consent_category(), $body );
    }

    /**
     * JavaScript running code once consent allows this integration; empty with consent mode off
     *
     * @param string $body Code.
     *
     * @return string
     */
    protected function consent_when_js( $body ) {
        return \StorePulse\ConversionTracking\Consent\ConsentMode::when_js( $this->get_consent_category(), $body );
    }

    /**
     * JavaScript loading a script once consent allows this integration, then running code; empty with consent mode off
     *
     * @param string $src  Script URL.
     * @param string $body Code to run after it loaded.
     *
     * @return string
     */
    protected function consent_script_js( $src, $body = '' ) {
        return \StorePulse\ConversionTracking\Consent\ConsentMode::script_js( $this->get_consent_category(), $src, $body );
    }

    /**
     * JavaScript holding a global function's calls until consent allows this integration; empty with consent mode off
     *
     * @param string $name Global function name.
     *
     * @return string
     */
    protected function consent_wrap_js( $name ) {
        return \StorePulse\ConversionTracking\Consent\ConsentMode::wrap_js( $this->get_consent_category(), $name );
    }

    /**
     * Whether consent mode is on, so tags wait for consent
     *
     * @return boolean
     */
    protected function consent_mode_on() {
        return \StorePulse\ConversionTracking\Consent\ConsentMode::is_enabled();
    }

    /**
     * Print JavaScript in its own script tag; prints nothing for empty code
     *
     * @param string $js JavaScript.
     *
     * @return void
     */
    protected function print_consent_script( $js ) {
        if ( '' !== $js ) {
            echo '<script data-no-optimize="1">' . $js . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from JSON-encoded values.
        }
    }

    /**
     * Check if an event is enabled
     *
     * @param  string $event
     *
     * @return boolean
     */
    public function event_enabled( $event ) {
        $settings = $this->get_integration_settings();

        if ( isset( $settings[0]['events'] ) && array_key_exists( $event, $settings[0]['events'] ) && $settings[0]['events'][ $event ] == 'on' ) {
            return true;
        }

        return false;
    }

    /**
     * Integration settings get options
     *
     * @return array|false
     */
    public function get_integration_settings() {
        $integration_settings = get_option( 'wcct_settings', array() );

        if ( isset( $integration_settings[ $this->id ] ) ) {
            return $integration_settings[ $this->id ];
        }

        return false;
    }

    /**
     * Check feattures
     *
     * @param  array $feature
     *
     * @return boolean
     */
    public function supports( $feature ) {
        if ( in_array( $feature, $this->supports ) ) {
            return true;
        }

        return false;
    }

    /**
     * Helper function to iterate through a cart and gather all content ids, per the platform's Product ID sent setting
     *
     * @param  array $cart
     *
     * @return array
     */
    protected function get_content_ids_from_cart( $cart ) {
        return \StorePulse\ConversionTracking\Events\ProductId::for_cart( $cart, $this->id );
    }

    /**
     * gtag config IDs this integration adds to the shared Google tag; empty for none
     *
     * @return string[]
     */
    public function get_gtag_ids() {
        return array();
    }

    /**
     * Extra gtag config parameters by config ID; empty for none
     *
     * @return array
     */
    public function get_gtag_config() {
        return array();
    }

    /**
     * Print the shared Google tag once per page: one loader, then one config per ID of every active integration
     *
     * @return void
     */
    protected function print_google_tag() {
        $integrations = function_exists( 'wcct_init' ) && isset( wcct_init()->manager ) ? wcct_init()->manager->get_active_integrations() : array();

        if ( ! in_array( $this, $integrations, true ) ) {
            $integrations[] = $this;
        }

        GoogleTag::print_once( $integrations );
    }

    /**
     * Print the gtag.js loader, once per page
     *
     * Integrations that use gtag.js call this instead of printing the
     * loader themselves. Code that prints its own loader can fire the
     * wcct_gtag_loader_printed action so the loader is not printed again.
     *
     * @param  string $tag_id ID for the loader URL, for example G-XXXXXXXXXX
     *
     * @return boolean True when the loader was printed by this call, false
     *                 when it was already on the page. Only the first caller
     *                 should send gtag('js').
     */
    protected function print_gtag_loader( $tag_id ) {
        if ( did_action( 'wcct_gtag_loader_printed' ) ) {
            return false;
        }
        ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $tag_id ); ?>"></script>
        <?php
        do_action( 'wcct_gtag_loader_printed', $tag_id );

        return true;
    }
}
