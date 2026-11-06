<?php

namespace StorePulse\ConversionTracking\Settings;

use StorePulse\ConversionTracking\Access\CustomScripts;
use StorePulse\ConversionTracking\Events\ValueMode;
use StorePulse\ConversionTracking\Install;

/**
 * The 3.0 settings schema: global fields plus one plugin-ui element list per platform.
 */
class Schema {

    /**
     * Filter Pro and extensions use to add platforms and fields.
     */
    const FILTER = 'wcct_settings_schema';

    /**
     * Variants that only display something and hold no value.
     */
    const DISPLAY_VARIANTS = array( 'html', 'notice', 'info', 'info_preview', 'base_field_label', 'copy_field' );

    /**
     * Keys used on the server only, never sent to the browser.
     */
    const SERVER_KEYS = array( 'capability', 'sanitize_callback' );

    /**
     * The filtered schema.
     *
     * @return array { global: array, platforms: array } with platforms keyed by ID and sorted by priority.
     */
    public static function get(): array {
        $lite = self::lite();

        /**
         * Filter the settings schema.
         *
         * @since 3.0.0
         *
         * @param array $schema { global: plugin-ui elements, platforms: id => { id, label, integrations, pro, priority, credentials, schema } }.
         */
        $schema = apply_filters( self::FILTER, $lite );

        return self::normalize( is_array( $schema ) ? $schema : $lite );
    }

    /**
     * Drop malformed platforms and sort the rest by priority.
     *
     * @param array $schema Raw schema.
     *
     * @return array
     */
    public static function normalize( array $schema ): array {
        $platforms = array();

        foreach ( isset( $schema['platforms'] ) && is_array( $schema['platforms'] ) ? $schema['platforms'] : array() as $id => $platform ) {
            if ( ! is_string( $id ) || '' === $id || ! is_array( $platform ) || ! isset( $platform['schema'] ) || ! is_array( $platform['schema'] ) ) {
                continue;
            }

            $platforms[ $id ] = array_merge(
                array(
                    'label'        => $id,
                    'integrations' => array( $id ),
                    'pro'          => false,
                    'priority'     => 100,
                    'credentials'  => array(),
                ),
                $platform,
                array( 'id' => $id )
            );
        }

        uasort(
            $platforms,
            function ( $a, $b ) {
                return (int) $a['priority'] - (int) $b['priority'];
            }
        );

        return array(
            'global'    => isset( $schema['global'] ) && is_array( $schema['global'] ) ? $schema['global'] : array(),
            'platforms' => $platforms,
        );
    }

    /**
     * Fields that hold a value, keyed by ID.
     *
     * @param array $elements plugin-ui elements.
     *
     * @return array
     */
    public static function fields( array $elements ): array {
        $fields = array();

        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) || ! isset( $element['type'], $element['id'] ) || 'field' !== $element['type'] ) {
                continue;
            }

            if ( in_array( isset( $element['variant'] ) ? $element['variant'] : '', self::DISPLAY_VARIANTS, true ) ) {
                continue;
            }

            $fields[ $element['id'] ] = $element;
        }

        return $fields;
    }

    /**
     * Default value of a field.
     *
     * @param array $field Field.
     *
     * @return mixed
     */
    public static function default_value( array $field ) {
        return array_key_exists( 'default', $field ) ? $field['default'] : '';
    }

    /**
     * The schema as sent to the browser: server-only keys removed, fields the user can't change read-only.
     *
     * @param array    $schema Normalized schema.
     * @param callable $can    Receives a capability, returns whether the current user has it.
     *
     * @return array
     */
    public static function for_output( array $schema, callable $can ): array {
        $platforms = array();

        foreach ( $schema['platforms'] as $platform ) {
            $platforms[] = array(
                'id'           => $platform['id'],
                'label'        => $platform['label'],
                'integrations' => array_values( (array) $platform['integrations'] ),
                'pro'          => (bool) $platform['pro'],
                'credentials'  => self::output_elements( (array) $platform['credentials'], $can ),
                'schema'       => self::output_elements( $platform['schema'], $can ),
            );
        }

        return array(
            'global'    => self::output_elements( $schema['global'], $can ),
            'platforms' => $platforms,
        );
    }

    /**
     * Prepare a list of elements for the browser.
     *
     * @param array    $elements Elements.
     * @param callable $can      Capability check.
     *
     * @return array
     */
    private static function output_elements( array $elements, callable $can ): array {
        $output = array();

        foreach ( $elements as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }

            if ( ! empty( $element['capability'] ) && ! call_user_func( $can, $element['capability'] ) ) {
                $element['readonly'] = true;
                $element['disabled'] = true;
            }

            $output[] = array_diff_key( $element, array_flip( self::SERVER_KEYS ) );
        }

        return $output;
    }

    /**
     * A section element for a platform page.
     *
     * @param string $platform Platform ID.
     * @param string $id       Section ID, unique on the page.
     * @param string $label    Title.
     * @param array  $extra    More element keys.
     *
     * @return array
     */
    public static function section( string $platform, string $id, string $label, array $extra = array() ): array {
        return array_merge(
            array(
                'id'      => $id,
                'type'    => 'section',
                'page_id' => $platform,
                'label'   => $label,
            ),
            $extra
        );
    }

    /**
     * Whether an element with this ID exists.
     *
     * @param array  $elements Elements.
     * @param string $id       Element ID.
     *
     * @return bool
     */
    public static function has_element( array $elements, string $id ): bool {
        foreach ( $elements as $element ) {
            if ( is_array( $element ) && isset( $element['id'] ) && $id === $element['id'] ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unlock the Pro options of a multicheck field.
     *
     * @param array  $elements Elements.
     * @param string $field_id Field ID.
     *
     * @return array
     */
    public static function unlock_options( array $elements, string $field_id ): array {
        foreach ( $elements as $index => $element ) {
            if ( ! is_array( $element ) || ! isset( $element['id'], $element['options'] ) || $field_id !== $element['id'] ) {
                continue;
            }

            foreach ( $element['options'] as $key => $option ) {
                $elements[ $index ]['options'][ $key ]['locked'] = false;
            }
        }

        return $elements;
    }

    /**
     * Lite's schema, before the filter.
     *
     * @return array
     */
    public static function lite(): array {
        return array(
            'global'    => self::global_elements(),
            'platforms' => array(
                'facebook' => array(
                    'label'        => __( 'Meta', 'woocommerce-conversion-tracking' ),
                    'integrations' => array( 'facebook' ),
                    'priority'     => 10,
                    'schema'       => self::facebook(),
                ),
                'google'   => array(
                    'label'        => __( 'Google', 'woocommerce-conversion-tracking' ),
                    'integrations' => array( 'ga4', 'adwords' ),
                    'priority'     => 20,
                    'schema'       => self::google(),
                ),
                'twitter'  => array(
                    'label'        => __( 'X', 'woocommerce-conversion-tracking' ),
                    'integrations' => array( 'twitter' ),
                    'priority'     => 30,
                    'schema'       => self::twitter(),
                ),
                'custom'   => array(
                    'label'        => __( 'Custom script', 'woocommerce-conversion-tracking' ),
                    'integrations' => array( 'custom' ),
                    'priority'     => 90,
                    'schema'       => self::custom(),
                ),
            ),
        );
    }

    /**
     * Settings that apply to every platform.
     *
     * @return array
     */
    private static function global_elements(): array {
        return array(
            array(
                'id'    => 'general',
                'type'  => 'page',
                'label' => __( 'General', 'woocommerce-conversion-tracking' ),
            ),
            self::section( 'general', 'general_privacy', __( 'Privacy', 'woocommerce-conversion-tracking' ) ),
            array(
                'id'          => 'consent_mode',
                'type'        => 'field',
                'variant'     => 'switch',
                'section_id'  => 'general_privacy',
                'label'       => __( 'Respect cookie choices', 'woocommerce-conversion-tracking' ),
                'description' => __( 'Required if you sell to Europe. Works with your cookie plugin.', 'woocommerce-conversion-tracking' ),
                'default'     => false,
            ),
        );
    }

    /**
     * Meta.
     *
     * @return array
     */
    private static function facebook(): array {
        return array(
            self::page( 'facebook', __( 'Meta', 'woocommerce-conversion-tracking' ) ),
            self::section( 'facebook', 'facebook_connection', __( 'Connection', 'woocommerce-conversion-tracking' ) ),
            self::id_field(
                'facebook_connection',
                'pixel_id',
                __( 'Pixel ID', 'woocommerce-conversion-tracking' ),
                __( '15 or 16 digits, numbers only', 'woocommerce-conversion-tracking' ),
                __( 'Numbers only, 15 or 16 digits. If it starts with "EAA", that\'s an access token, not a Pixel ID.', 'woocommerce-conversion-tracking' ),
                '^[0-9]{15,16}$',
                10
            ),
            self::section( 'facebook', 'facebook_events', __( 'Events', 'woocommerce-conversion-tracking' ) ),
            self::events_field(
                'facebook_events',
                array(
                    self::event( 'view_product', __( 'View product', 'woocommerce-conversion-tracking' ), __( 'Every product page.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true ) ),
                    self::event( 'add_to_cart', __( 'Add to cart', 'woocommerce-conversion-tracking' ), __( 'Classic buttons, quick adds on the shop page and block buttons.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true ) ),
                    self::event( 'begin_checkout', __( 'Start checkout', 'woocommerce-conversion-tracking' ), __( 'Classic and block checkout.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true ) ),
                    self::event( 'purchase', __( 'Purchase', 'woocommerce-conversion-tracking' ), __( 'Once per order. Failed and cancelled orders are skipped.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true ) ),
                    self::event( 'search', __( 'Search', 'woocommerce-conversion-tracking' ), __( 'Store search results.', 'woocommerce-conversion-tracking' ) ),
                    self::event( 'sign_up', __( 'Sign up', 'woocommerce-conversion-tracking' ), __( 'A new WordPress or WooCommerce account.', 'woocommerce-conversion-tracking' ) ),
                    self::event( 'view_category', __( 'View category', 'woocommerce-conversion-tracking' ), __( 'Product category pages.', 'woocommerce-conversion-tracking' ), array( 'pro' => true ) ),
                    self::event( 'add_to_wishlist', __( 'Add to wishlist', 'woocommerce-conversion-tracking' ), __( 'When a shopper saves a product to a wishlist.', 'woocommerce-conversion-tracking' ), array( 'pro' => true ) ),
                )
            ),
            self::section( 'facebook', 'facebook_advanced', __( 'Advanced settings', 'woocommerce-conversion-tracking' ), array( 'collapsible' => true, 'collapsed' => true ) ),
            self::value_mode_field( 'facebook_advanced', 'facebook' ),
        );
    }

    /**
     * Google, one platform over the ga4 and adwords integrations.
     *
     * @return array
     */
    private static function google(): array {
        $analytics = array( self::depends( 'mode', array( Google::MODE_ANALYTICS, Google::MODE_BOTH ) ) );
        $ads       = array( self::depends( 'mode', array( Google::MODE_ADS, Google::MODE_BOTH ) ) );

        return array(
            self::page( 'google', __( 'Google', 'woocommerce-conversion-tracking' ) ),
            self::section( 'google', 'google_connection', __( 'Connection', 'woocommerce-conversion-tracking' ) ),
            array(
                'id'         => 'mode',
                'type'       => 'field',
                'variant'    => 'radio_capsule',
                'section_id' => 'google_connection',
                'label'      => __( 'What do you use?', 'woocommerce-conversion-tracking' ),
                'default'    => Google::MODE_BOTH,
                'priority'   => 10,
                'options'    => array(
                    array(
                        'value'       => Google::MODE_ANALYTICS,
                        'label'       => __( 'Google Analytics', 'woocommerce-conversion-tracking' ),
                        'description' => __( 'See where sales come from. Free.', 'woocommerce-conversion-tracking' ),
                    ),
                    array(
                        'value'       => Google::MODE_ADS,
                        'label'       => __( 'Google Ads', 'woocommerce-conversion-tracking' ),
                        'description' => __( 'Count purchases as conversions for your campaigns.', 'woocommerce-conversion-tracking' ),
                    ),
                    array(
                        'value'       => Google::MODE_BOTH,
                        'label'       => __( 'Both', 'woocommerce-conversion-tracking' ),
                        'description' => __( 'Most stores. One loader, no double counting.', 'woocommerce-conversion-tracking' ),
                    ),
                ),
            ),
            self::id_field(
                'google_connection',
                'measurement_id',
                __( 'Analytics Measurement ID', 'woocommerce-conversion-tracking' ),
                __( 'Starts with G-', 'woocommerce-conversion-tracking' ),
                __( 'Measurement IDs start with G- followed by letters and numbers. Find it under Admin, then Data streams.', 'woocommerce-conversion-tracking' ),
                '^G-[A-Z0-9]+$',
                20,
                array(
                    'case'         => 'upper',
                    'dependencies' => $analytics,
                )
            ),
            self::id_field(
                'google_connection',
                'ads_id',
                __( 'Ads conversion ID', 'woocommerce-conversion-tracking' ),
                __( 'Starts with AW-', 'woocommerce-conversion-tracking' ),
                '',
                '^AW-[0-9]+$',
                30,
                array(
                    'case'         => 'upper',
                    'dependencies' => $ads,
                )
            ),
            self::id_field(
                'google_connection',
                'purchase_label',
                __( 'Ads conversion label', 'woocommerce-conversion-tracking' ),
                __( 'Letters and numbers after the slash', 'woocommerce-conversion-tracking' ),
                '',
                '^[A-Za-z0-9_-]+$',
                40,
                array( 'dependencies' => $ads )
            ),
            self::section( 'google', 'google_events', __( 'Events', 'woocommerce-conversion-tracking' ) ),
            self::events_field(
                'google_events',
                array(
                    self::event( 'view_product', __( 'View product', 'woocommerce-conversion-tracking' ), __( 'Analytics view_item on every product page.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true ) ),
                    self::event( 'add_to_cart', __( 'Add to cart', 'woocommerce-conversion-tracking' ), __( 'Analytics add_to_cart, classic and block buttons.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true ) ),
                    self::event( 'begin_checkout', __( 'Start checkout', 'woocommerce-conversion-tracking' ), __( 'Analytics begin_checkout, classic and block checkout.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true ) ),
                    self::event( 'purchase', __( 'Purchase', 'woocommerce-conversion-tracking' ), __( 'Analytics purchase and the Ads conversion, once per order.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true ) ),
                    self::event( 'search', __( 'Search', 'woocommerce-conversion-tracking' ), __( 'Analytics search on store search results.', 'woocommerce-conversion-tracking' ) ),
                    self::event( 'sign_up', __( 'Registration', 'woocommerce-conversion-tracking' ), __( 'Counted as an Ads conversion when a shopper creates an account.', 'woocommerce-conversion-tracking' ), array( 'pro' => true ) ),
                )
            ),
            self::section( 'google', 'google_advanced', __( 'Advanced settings', 'woocommerce-conversion-tracking' ), array( 'collapsible' => true, 'collapsed' => true ) ),
            self::id_field(
                'google_advanced',
                'tag_id',
                __( 'Google tag ID (optional)', 'woocommerce-conversion-tracking' ),
                __( 'Starts with GT-', 'woocommerce-conversion-tracking' ),
                __( 'Only if your Analytics stream uses a combined Google tag. Leave empty to use the G- ID above.', 'woocommerce-conversion-tracking' ),
                '^GT-[A-Z0-9]+$',
                10,
                array( 'case' => 'upper' )
            ),
            self::value_mode_field( 'google_advanced', 'adwords', $ads ),
        );
    }

    /**
     * X.
     *
     * @return array
     */
    private static function twitter(): array {
        $event_id = __( 'Starts with tw-', 'woocommerce-conversion-tracking' );
        $pattern  = '^tw-[a-z0-9]+-[a-z0-9]+$';
        $lower    = array( 'case' => 'lower' );

        return array(
            self::page( 'twitter', __( 'X', 'woocommerce-conversion-tracking' ) ),
            self::section( 'twitter', 'twitter_connection', __( 'Connection', 'woocommerce-conversion-tracking' ) ),
            self::id_field( 'twitter_connection', 'pixel_id', __( 'Pixel ID', 'woocommerce-conversion-tracking' ), __( '5 letters and numbers, like o8x2k', 'woocommerce-conversion-tracking' ), __( 'The base tag on every page.', 'woocommerce-conversion-tracking' ), '^[a-z0-9]{5}$', 10, $lower ),
            self::id_field( 'twitter_connection', 'purchase_event_id', __( 'Purchase event ID', 'woocommerce-conversion-tracking' ), $event_id, '', $pattern, 20, $lower ),
            self::id_field( 'twitter_connection', 'cart_event_id', __( 'Add to cart event ID (optional)', 'woocommerce-conversion-tracking' ), $event_id, '', $pattern, 30, $lower ),
            self::id_field( 'twitter_connection', 'checkout_event_id', __( 'Start checkout event ID (optional)', 'woocommerce-conversion-tracking' ), $event_id, '', $pattern, 40, $lower ),
            self::section( 'twitter', 'twitter_events', __( 'Events', 'woocommerce-conversion-tracking' ) ),
            self::events_field(
                'twitter_events',
                array(
                    self::event( 'page_view', __( 'Page view', 'woocommerce-conversion-tracking' ), __( 'The base tag on every page.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true, 'always' => true ) ),
                    self::event( 'add_to_cart', __( 'Add to cart', 'woocommerce-conversion-tracking' ), __( 'Classic and block buttons.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true, 'needs' => 'cart_event_id' ) ),
                    self::event( 'begin_checkout', __( 'Start checkout', 'woocommerce-conversion-tracking' ), __( 'Classic and block checkout.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true, 'needs' => 'checkout_event_id' ) ),
                    self::event( 'purchase', __( 'Purchase', 'woocommerce-conversion-tracking' ), __( 'Uses the Purchase event ID above. Once per order.', 'woocommerce-conversion-tracking' ), array( 'recommended' => true ) ),
                    self::event( 'sign_up', __( 'Registration', 'woocommerce-conversion-tracking' ), __( 'A new account on your store.', 'woocommerce-conversion-tracking' ), array( 'pro' => true, 'needs' => 'signup_event_id' ) ),
                )
            ),
            self::section( 'twitter', 'twitter_advanced', __( 'Advanced settings', 'woocommerce-conversion-tracking' ), array( 'collapsible' => true, 'collapsed' => true ) ),
            self::value_mode_field( 'twitter_advanced', 'twitter' ),
        );
    }

    /**
     * Custom scripts; only users with unfiltered_html can change them.
     *
     * @return array
     */
    private static function custom(): array {
        $elements = array(
            self::page( 'custom', __( 'Custom script', 'woocommerce-conversion-tracking' ) ),
        );

        $placements = array(
            'thankyou' => array( __( 'Thank-you page', 'woocommerce-conversion-tracking' ), __( 'Script for thank-you page', 'woocommerce-conversion-tracking' ) ),
            'header'   => array( __( 'Every page, in the header', 'woocommerce-conversion-tracking' ), __( 'Script for every page, in the header', 'woocommerce-conversion-tracking' ) ),
            'signup'   => array( __( 'Sign-up', 'woocommerce-conversion-tracking' ), __( 'Script for sign-up', 'woocommerce-conversion-tracking' ) ),
        );

        foreach ( $placements as $placement => $labels ) {
            $section    = 'custom_' . $placement;
            $elements[] = self::section( 'custom', $section, $labels[0] );
            $elements[] = array(
                'id'         => $placement . '_enabled',
                'type'       => 'field',
                'variant'    => 'switch',
                'section_id' => $section,
                'label'      => $labels[0],
                'default'    => false,
                'priority'   => 10,
                'capability' => CustomScripts::CAPABILITY,
            );
            $elements[] = array(
                'id'          => $placement . '_script',
                'type'        => 'field',
                'variant'     => 'textarea',
                'format'      => 'script',
                'section_id'  => $section,
                'label'       => $labels[1],
                'placeholder' => __( 'Paste your tag here', 'woocommerce-conversion-tracking' ),
                'default'     => '',
                'priority'    => 20,
                'capability'  => CustomScripts::CAPABILITY,
            );
        }

        $elements[] = self::section( 'custom', 'custom_privacy', __( 'Customer details', 'woocommerce-conversion-tracking' ) );
        $elements[] = array(
            'id'          => 'customer_details',
            'type'        => 'field',
            'variant'     => 'switch',
            'section_id'  => 'custom_privacy',
            'label'       => __( 'Include customer details in scripts', 'woocommerce-conversion-tracking' ),
            'description' => __( 'Shares the shopper\'s ID, email and name on a verified order. Values wait for consent when Respect cookie choices is on. Only administrators can edit scripts.', 'woocommerce-conversion-tracking' ),
            'default'     => false,
            'capability'  => CustomScripts::CAPABILITY,
        );

        return $elements;
    }

    /**
     * A platform page element.
     *
     * @param string $id    Platform ID.
     * @param string $label Title.
     *
     * @return array
     */
    private static function page( string $id, string $label ): array {
        return array(
            'id'    => $id,
            'type'  => 'page',
            'label' => $label,
        );
    }

    /**
     * A text field that holds a platform ID or label.
     *
     * @param string $section     Section ID.
     * @param string $id          Field ID.
     * @param string $label       Label.
     * @param string $placeholder Placeholder.
     * @param string $description Help text.
     * @param string $pattern     Format the value must match once cleaned (empty clears the field); extra 'case' (upper, lower) is applied first.
     * @param int    $priority    Order in the section.
     * @param array  $extra       More element keys.
     *
     * @return array
     */
    public static function id_field( string $section, string $id, string $label, string $placeholder, string $description, string $pattern, int $priority, array $extra = array() ): array {
        return array_merge(
            array(
                'id'          => $id,
                'type'        => 'field',
                'variant'     => 'text',
                'format'      => 'id',
                'section_id'  => $section,
                'label'       => $label,
                'placeholder' => $placeholder,
                'description' => $description,
                'pattern'     => $pattern,
                'default'     => '',
                'priority'    => $priority,
            ),
            $extra
        );
    }

    /**
     * The events multicheck; Recommended events are on by default.
     *
     * @param string $section Section ID.
     * @param array  $events  Options from event().
     *
     * @return array
     */
    public static function events_field( string $section, array $events ): array {
        $defaults = array();

        foreach ( $events as $event ) {
            if ( $event['recommended'] && ! $event['locked'] ) {
                $defaults[] = $event['value'];
            }
        }

        return array(
            'id'         => 'events',
            'type'       => 'field',
            'variant'    => 'multicheck',
            'section_id' => $section,
            'label'      => __( 'Events', 'woocommerce-conversion-tracking' ),
            'default'    => $defaults,
            'options'    => $events,
        );
    }

    /**
     * One events option.
     *
     * @param string $id          Event ID shared by every platform, as the event pipeline names it.
     * @param string $label       Label.
     * @param string $description Description.
     * @param array  $flags       recommended, pro (locked until Pro unlocks it), always (no switch), needs (field holding its event ID).
     *
     * @return array
     */
    public static function event( string $id, string $label, string $description, array $flags = array() ): array {
        $pro = ! empty( $flags['pro'] );

        return array(
            'value'       => $id,
            'label'       => $label,
            'description' => $description,
            'recommended' => ! empty( $flags['recommended'] ),
            'pro'         => $pro,
            'locked'      => $pro,
            'always'      => ! empty( $flags['always'] ),
            'needs'       => isset( $flags['needs'] ) ? $flags['needs'] : '',
        );
    }

    /**
     * "Order value includes" for a platform's Purchase; the default follows the install type (D8).
     *
     * @param string $section      Section ID.
     * @param string $integration  Integration ID the mode is for.
     * @param array  $dependencies Field dependencies.
     *
     * @return array
     */
    public static function value_mode_field( string $section, string $integration, array $dependencies = array() ): array {
        $field = array(
            'id'          => 'value_mode',
            'type'        => 'field',
            'variant'     => 'select',
            'section_id'  => $section,
            'label'       => __( 'Order value includes', 'woocommerce-conversion-tracking' ),
            'description' => __( 'Sent with Purchase. Items only keeps ad results in line with product revenue. Fees count as items; tax is never part of items.', 'woocommerce-conversion-tracking' ),
            'default'     => ValueMode::default_for( $integration, Install::is_upgrade() ),
            'priority'    => 50,
            'options'     => array(
                array(
                    'value' => ValueMode::ITEMS,
                    'label' => __( 'Items only (recommended)', 'woocommerce-conversion-tracking' ),
                ),
                array(
                    'value' => ValueMode::SHIPPING,
                    'label' => __( 'Items and shipping', 'woocommerce-conversion-tracking' ),
                ),
                array(
                    'value' => ValueMode::ALL,
                    'label' => __( 'Items, tax and shipping', 'woocommerce-conversion-tracking' ),
                ),
            ),
        );

        if ( $dependencies ) {
            $field['dependencies'] = $dependencies;
        }

        return $field;
    }

    /**
     * Show a field only while another field's value is one of these.
     *
     * @param string $key    Field ID.
     * @param array  $values Values.
     *
     * @return array
     */
    public static function depends( string $key, array $values ): array {
        return array(
            'key'        => $key,
            'value'      => $values,
            'comparison' => 'in',
        );
    }
}
