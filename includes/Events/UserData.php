<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * Customer data for Meta's advanced matching and Google's enhanced conversions: normalised per platform and SHA-256 hashed, never in plain text.
 */
class UserData {

    /**
     * Fields that are hashed, in output order.
     */
    const FIELDS = array( 'em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country', 'external_id' );

    /**
     * Calling codes (IT, SM, VA) whose national numbers keep their leading 0.
     */
    const KEEP_LEADING_ZERO = array( '39', '378', '379' );

    /**
     * Normalise one field.
     *
     * @param string $field   Field key, one of FIELDS.
     * @param mixed  $value   Raw value.
     * @param string $country ISO country code of the address, for the phone and postcode rules.
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

            case 'country':
                return preg_match( '/^[a-z]{2}$/', $value ) ? $value : '';

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
                'country'     => $order->get_billing_country(),
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
                'country'     => $customer->get_billing_country(),
                'external_id' => $customer->get_id() > 0 ? $customer->get_id() : '',
            ),
            $customer->get_billing_country()
        );
    }

    /**
     * Hashed email, name, billing country and ID of a WordPress user.
     *
     * @param \WP_User $user User.
     *
     * @return array
     */
    public static function from_user( $user ) {
        if ( ! is_object( $user ) || empty( $user->ID ) ) {
            return array();
        }

        // The billing country WooCommerce keeps for a user who has bought or saved an address.
        $country = function_exists( 'get_user_meta' ) ? get_user_meta( (int) $user->ID, 'billing_country', true ) : '';

        return self::hash_fields(
            array(
                'em'          => isset( $user->user_email ) ? $user->user_email : '',
                'fn'          => isset( $user->first_name ) ? $user->first_name : '',
                'ln'          => isset( $user->last_name ) ? $user->last_name : '',
                'country'     => $country,
                'external_id' => (int) $user->ID,
            ),
            is_string( $country ) ? $country : ''
        );
    }

    /**
     * Google's user_data of an order: SHA-256 of the email and phone normalised per Google's enhanced conversions rules.
     *
     * @param \WC_Order $order Order.
     *
     * @return array { sha256_email_address?, sha256_phone_number? }
     */
    public static function google_from_order( $order ) {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_billing_email' ) ) {
            return array();
        }

        $country = method_exists( $order, 'get_billing_country' ) ? (string) $order->get_billing_country() : '';
        $email   = self::google_email( $order->get_billing_email() );
        $phone   = method_exists( $order, 'get_billing_phone' ) ? self::google_phone( $order->get_billing_phone(), self::calling_code( $country ) ) : '';

        return array_filter(
            array(
                'sha256_email_address' => '' !== $email ? hash( 'sha256', $email ) : '',
                'sha256_phone_number'  => '' !== $phone ? hash( 'sha256', $phone ) : '',
            )
        );
    }

    /**
     * Email for Google: trimmed, lower case, without the dots before @ on gmail.com and googlemail.com.
     *
     * @param mixed $value Raw email.
     *
     * @return string Empty when it isn't an email.
     */
    public static function google_email( $value ) {
        $email = self::normalise( 'em', $value );
        $at    = strrpos( $email, '@' );

        if ( false === $at || 0 === $at ) {
            return '';
        }

        $local  = substr( $email, 0, $at );
        $domain = substr( $email, $at + 1 );

        if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
            $local = str_replace( '.', '', $local );
        }

        return '' !== $local && '' !== $domain ? $local . '@' . $domain : '';
    }

    /**
     * Phone for Google in E.164 (+ and the country code); empty when the country code is unknown or it isn't a valid number.
     *
     * @param mixed  $value        Raw phone number.
     * @param string $calling_code Calling code of the address country; empty when unknown.
     *
     * @return string
     */
    public static function google_phone( $value, $calling_code = '' ) {
        if ( ! is_scalar( $value ) || is_bool( $value ) ) {
            return '';
        }

        $phone = '+' . self::phone( $value, $calling_code );

        return preg_match( '/^\+[1-9][0-9]{6,14}$/', $phone ) ? $phone : '';
    }

    /**
     * Phone as digits with the country code: an international number keeps its own, a national one gets the calling code.
     *
     * @param string $value        Raw phone number.
     * @param string $calling_code Calling code of the address country, with or without +; empty when unknown.
     *
     * @return string Empty for a national number when the calling code is unknown.
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

        $code = preg_replace( '/\D+/', '', (string) $calling_code );

        // Without a calling code a national number has no country code, so it isn't sent.
        if ( '' === $code ) {
            return '';
        }

        // Italy, San Marino and Vatican City keep the leading 0 of national numbers in E.164.
        $digits = in_array( $code, self::KEEP_LEADING_ZERO, true ) ? $digits : ltrim( $digits, '0' );

        // A number that already starts with the calling code and is long enough for one keeps it.
        if ( '' === $digits || ( 0 === strpos( $digits, $code ) && strlen( $digits ) >= 11 ) ) {
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
     * Postcode: lower case without spaces or dashes; the first 5 digits in the US.
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
