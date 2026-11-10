<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * Customer data for advanced matching: normalised per Meta's rules and SHA-256 hashed, never in plain text.
 */
class UserData {

    /**
     * Fields that are hashed, in output order.
     */
    const FIELDS = array( 'em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'external_id' );

    /**
     * Normalise one field.
     *
     * @param string $field   Field key, one of FIELDS.
     * @param mixed  $value   Raw value.
     * @param string $country ISO country code of the address, for the postcode rule.
     *
     * @return string Normalised value, empty when there is nothing to send.
     */
    public static function normalise( $field, $value, $country = '' ) {
        if ( ! is_scalar( $value ) || is_bool( $value ) ) {
            return '';
        }

        $value = self::lower( trim( (string) $value ) );

        if ( 'ph' === $field ) {
            return self::phone( $value, self::calling_code( $country ) );
        }

        switch ( $field ) {
            case 'em':
                return false !== strpos( $value, '@' ) ? preg_replace( '/\s+/', '', $value ) : '';

            case 'fn':
            case 'ln':
                return trim( preg_replace( '/\s+/u', ' ', preg_replace( '/[^\p{L}\p{M}\s]/u', '', $value ) ) );

            case 'ct':
            case 'st':
                return preg_replace( '/[^\p{L}\p{M}\p{N}]/u', '', $value );

            case 'zp':
                return self::postcode( $value, $country );

            case 'external_id':
                return $value;
        }

        return '';
    }

    /**
     * SHA-256 hex of the normalised value.
     *
     * @param string $field   Field key.
     * @param mixed  $value   Raw value.
     * @param string $country ISO country code of the address.
     *
     * @return string Hash, empty when there is nothing to send.
     */
    public static function hash( $field, $value, $country = '' ) {
        $value = self::normalise( $field, $value, $country );

        return '' === $value ? '' : hash( 'sha256', $value );
    }

    /**
     * Hash every known field of a raw set; empty and unknown fields are dropped.
     *
     * @param array  $raw     Field key => raw value.
     * @param string $country ISO country code of the address.
     *
     * @return array Field key => hash.
     */
    public static function hash_fields( array $raw, $country = '' ) {
        $hashed = array();

        foreach ( self::FIELDS as $field ) {
            $hash = isset( $raw[ $field ] ) ? self::hash( $field, $raw[ $field ], $country ) : '';

            if ( '' !== $hash ) {
                $hashed[ $field ] = $hash;
            }
        }

        return $hashed;
    }

    /**
     * Hashed billing details and customer ID of an order.
     *
     * @param \WC_Order $order Order.
     *
     * @return array
     */
    public static function from_order( $order ) {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_billing_email' ) ) {
            return array();
        }

        $customer_id = (int) $order->get_customer_id();

        return self::hash_fields(
            array(
                'em'          => $order->get_billing_email(),
                'ph'          => $order->get_billing_phone(),
                'fn'          => $order->get_billing_first_name(),
                'ln'          => $order->get_billing_last_name(),
                'ct'          => $order->get_billing_city(),
                'st'          => $order->get_billing_state(),
                'zp'          => $order->get_billing_postcode(),
                'external_id' => $customer_id > 0 ? $customer_id : '',
            ),
            $order->get_billing_country()
        );
    }

    /**
     * Hashed billing details and ID of a customer.
     *
     * @param \WC_Customer $customer Customer.
     *
     * @return array
     */
    public static function from_customer( $customer ) {
        if ( ! is_object( $customer ) || ! method_exists( $customer, 'get_billing_email' ) ) {
            return array();
        }

        $email = $customer->get_billing_email();

        return self::hash_fields(
            array(
                'em'          => '' !== (string) $email ? $email : $customer->get_email(),
                'ph'          => $customer->get_billing_phone(),
                'fn'          => $customer->get_billing_first_name(),
                'ln'          => $customer->get_billing_last_name(),
                'ct'          => $customer->get_billing_city(),
                'st'          => $customer->get_billing_state(),
                'zp'          => $customer->get_billing_postcode(),
                'external_id' => $customer->get_id() > 0 ? $customer->get_id() : '',
            ),
            $customer->get_billing_country()
        );
    }

    /**
     * Hashed email, name and ID of a WordPress user.
     *
     * @param \WP_User $user User.
     *
     * @return array
     */
    public static function from_user( $user ) {
        if ( ! is_object( $user ) || empty( $user->ID ) ) {
            return array();
        }

        return self::hash_fields(
            array(
                'em'          => isset( $user->user_email ) ? $user->user_email : '',
                'fn'          => isset( $user->first_name ) ? $user->first_name : '',
                'ln'          => isset( $user->last_name ) ? $user->last_name : '',
                'external_id' => (int) $user->ID,
            )
        );
    }

    /**
     * Phone as digits with the country code: an international number keeps its own, a national one gets the calling code.
     *
     * @param string $value        Raw phone number.
     * @param string $calling_code Calling code of the address country, with or without +; empty when unknown.
     *
     * @return string
     */
    public static function phone( $value, $calling_code = '' ) {
        $value  = trim( (string) $value );
        $digits = preg_replace( '/\D+/', '', $value );

        if ( '' === $digits ) {
            return '';
        }

        if ( 0 === strpos( $value, '+' ) || 0 === strpos( $digits, '00' ) ) {
            return ltrim( $digits, '0' );
        }

        $digits = ltrim( $digits, '0' );
        $code   = preg_replace( '/\D+/', '', (string) $calling_code );

        // A number that already starts with the calling code and is long enough for one keeps it.
        if ( '' === $code || '' === $digits || ( 0 === strpos( $digits, $code ) && strlen( $digits ) >= 11 ) ) {
            return $digits;
        }

        return $code . $digits;
    }

    /**
     * WooCommerce's calling code for a country, or an empty string.
     *
     * @param string $country ISO country code.
     *
     * @return string
     */
    private static function calling_code( $country ) {
        if ( '' === (string) $country || ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->countries ) || ! method_exists( WC()->countries, 'get_country_calling_code' ) ) {
            return '';
        }

        $code = WC()->countries->get_country_calling_code( strtoupper( (string) $country ) );

        return is_string( $code ) ? $code : '';
    }

    /**
     * Postcode: no spaces or dashes; the first 5 digits in the US, the outward code in the UK.
     *
     * @param string $value   Lower-cased postcode.
     * @param string $country ISO country code.
     *
     * @return string
     */
    private static function postcode( $value, $country ) {
        $country = strtoupper( (string) $country );

        if ( 'US' === $country ) {
            return substr( preg_replace( '/\D+/', '', $value ), 0, 5 );
        }

        if ( 'GB' === $country ) {
            $parts = preg_split( '/\s+/', $value );
            $value = count( $parts ) > 1 ? $parts[0] : preg_replace( '/[^a-z0-9]/', '', $value );
            $value = count( $parts ) > 1 || strlen( $value ) < 5 ? $value : substr( $value, 0, -3 );
        }

        return preg_replace( '/[^\p{L}\p{N}]/u', '', $value );
    }

    /**
     * Lower case, multibyte-safe when mbstring is there.
     *
     * @param string $value Value.
     *
     * @return string
     */
    private static function lower( $value ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
    }
}
