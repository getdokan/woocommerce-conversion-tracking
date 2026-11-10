<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\UserData;

/**
 * Tests for the advanced matching normalisation and hashing
 */
class UserDataTest extends TestCase {

    public function test_email_is_trimmed_and_lower_cased() {
        $this->assertSame( 'jane.doe@example.com', UserData::normalise( 'em', '  Jane.Doe@Example.COM ' ) );
        $this->assertSame( '', UserData::normalise( 'em', 'not an email' ) );
    }

    public function test_phone_keeps_digits_without_leading_zeros() {
        $this->assertSame( '15105550100', UserData::normalise( 'ph', '+1 (510) 555-0100' ) );
        $this->assertSame( '447700900123', UserData::normalise( 'ph', '0044 7700 900123' ) );
        $this->assertSame( '5105550100', UserData::normalise( 'ph', '(510) 555-0100' ) );
        $this->assertSame( '', UserData::normalise( 'ph', 'n/a' ) );
    }

    public function test_a_national_phone_number_gets_the_calling_code() {
        $this->assertSame( '15105550100', UserData::phone( '(510) 555-0100', '+1' ) );
        $this->assertSame( '447700900123', UserData::phone( '07700 900123', '+44' ) );
        $this->assertSame( '15105550100', UserData::phone( '1 510 555 0100', '+1' ) );
        $this->assertSame( '4915112345678', UserData::phone( '+49 151 12345678', '+44' ) );
        $this->assertSame( '4915112345678', UserData::phone( '0049 151 12345678', '1' ) );
        $this->assertSame( '5550100', UserData::phone( '555-0100', '' ) );
        $this->assertSame( '', UserData::phone( '---', '+1' ) );
    }

    public function test_italy_san_marino_and_vatican_keep_the_leading_zero() {
        $this->assertSame( '390612345678', UserData::phone( '06 1234 5678', '+39' ) );
        $this->assertSame( '3780549123456', UserData::phone( '0549 123456', '+378' ) );
        $this->assertSame( '379066981234', UserData::phone( '06 698 1234', '+379' ) );
        $this->assertSame( '390612345678', UserData::phone( '+39 06 1234 5678', '' ) );
        $this->assertSame( '393471234567', UserData::phone( '347 123 4567', '+39' ) );
        $this->assertSame( '+390612345678', UserData::google_phone( '06 1234 5678', '+39' ) );
        $this->assertSame( '+3780549123456', UserData::google_phone( '0549 123456', '378' ) );
    }

    public function test_names_are_lower_case_without_punctuation() {
        $this->assertSame( 'mary ann', UserData::normalise( 'fn', '  Mary  Ann. ' ) );
        $this->assertSame( "oconnor", UserData::normalise( 'ln', "O'Connor" ) );
        $this->assertSame( 'müller', UserData::normalise( 'ln', 'Müller' ) );
    }

    public function test_city_and_state_are_lower_case_without_spaces() {
        $this->assertSame( 'sanfrancisco', UserData::normalise( 'ct', 'San Francisco' ) );
        $this->assertSame( 'ca', UserData::normalise( 'st', ' CA ' ) );
        $this->assertSame( 'newsouthwales', UserData::normalise( 'st', 'New South-Wales' ) );
    }

    public function test_postcode_rules_per_country() {
        $this->assertSame( '94105', UserData::normalise( 'zp', '94105-1234', 'US' ) );
        $this->assertSame( 'sw1a', UserData::normalise( 'zp', 'SW1A 1AA', 'GB' ) );
        $this->assertSame( 'sw1a', UserData::normalise( 'zp', 'SW1A1AA', 'gb' ) );
        $this->assertSame( 'm1', UserData::normalise( 'zp', 'M1 1AE', 'GB' ) );
        $this->assertSame( '1234ab', UserData::normalise( 'zp', '1234 AB', 'NL' ) );
    }

    public function test_external_id_is_trimmed() {
        $this->assertSame( '501', UserData::normalise( 'external_id', 501 ) );
    }

    public function test_hash_is_sha256_hex_of_the_normalised_value() {
        $this->assertSame( hash( 'sha256', 'jane@example.com' ), UserData::hash( 'em', ' Jane@Example.com ' ) );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', UserData::hash( 'ph', '510 555 0100' ) );
        $this->assertSame( '', UserData::hash( 'em', '' ) );
    }

    public function test_hash_fields_drops_empty_unknown_and_bad_values() {
        $hashed = UserData::hash_fields(
            array(
                'em'      => 'jane@example.com',
                'ph'      => '',
                'fn'      => array( 'x' ),
                'country' => 'us',
                'zp'      => '94105',
            ),
            'US'
        );

        $this->assertSame( array( 'em', 'zp' ), array_keys( $hashed ) );
        $this->assertSame( hash( 'sha256', '94105' ), $hashed['zp'] );
    }

    public function test_google_email_drops_the_dots_of_gmail_addresses_only() {
        $this->assertSame( 'janedoe@gmail.com', UserData::google_email( ' Jane.Doe@GMAIL.com ' ) );
        $this->assertSame( 'janedoe@googlemail.com', UserData::google_email( 'jane.doe@googlemail.com' ) );
        $this->assertSame( 'jane.doe@example.com', UserData::google_email( 'Jane.Doe@Example.com' ) );
        $this->assertSame( 'jane+shop@gmail.com', UserData::google_email( 'jane+shop@gmail.com' ) );
        $this->assertSame( '', UserData::google_email( 'not an email' ) );
        $this->assertSame( '', UserData::google_email( '@gmail.com' ) );
        $this->assertSame( '', UserData::google_email( array( 'a@b.co' ) ) );
    }

    public function test_google_phone_is_e164_with_the_plus() {
        $this->assertSame( '+14155550100', UserData::google_phone( '(415) 555-0100', '+1' ) );
        $this->assertSame( '+447700900123', UserData::google_phone( '07700 900123', '44' ) );
        $this->assertSame( '+4915112345678', UserData::google_phone( '+49 151 12345678', '' ) );
        $this->assertSame( '+4915112345678', UserData::google_phone( '0049 151 12345678', '+1' ) );
    }

    public function test_google_phone_is_empty_without_a_country_code_or_digits() {
        $this->assertSame( '', UserData::google_phone( '(415) 555-0100', '' ) );
        $this->assertSame( '', UserData::google_phone( 'n/a', '+1' ) );
        $this->assertSame( '', UserData::google_phone( '+1 23', '' ) );
        $this->assertSame( '', UserData::google_phone( '+1 2345 6789 0123 4567', '' ) );
        $this->assertSame( '', UserData::google_phone( true, '+1' ) );
    }

    public function test_google_user_data_hashes_email_and_phone_only() {
        $order = new UserDataTestOrder( '  Casey.Tester@Gmail.com ', '+1 415 555 0100' );

        $this->assertSame(
            array(
                'sha256_email_address' => hash( 'sha256', 'caseytester@gmail.com' ),
                'sha256_phone_number'  => hash( 'sha256', '+14155550100' ),
            ),
            UserData::google_from_order( $order )
        );
        $this->assertSame( array( 'sha256_email_address' ), array_keys( UserData::google_from_order( new UserDataTestOrder( 'a@b.co', '415 555 0100' ) ) ) );
        $this->assertSame( array(), UserData::google_from_order( new UserDataTestOrder( '', '' ) ) );
        $this->assertSame( array(), UserData::google_from_order( null ) );
    }

    public function test_no_plain_text_survives() {
        $hashed = UserData::from_user( (object) array( 'ID' => 3, 'user_email' => 'a@b.co', 'first_name' => 'Sam', 'last_name' => 'Buyer' ) );

        $this->assertSame( array( 'em', 'fn', 'ln', 'external_id' ), array_keys( $hashed ) );
        $this->assertStringNotContainsString( 'a@b.co', implode( ',', $hashed ) );
        $this->assertSame( array(), UserData::from_user( null ) );
        $this->assertSame( array(), UserData::from_order( null ) );
    }
}

/**
 * An order with a billing email and phone only.
 */
class UserDataTestOrder {

    private $email;
    private $phone;

    public function __construct( $email, $phone ) {
        $this->email = $email;
        $this->phone = $phone;
    }

    public function get_id() {
        return 7;
    }

    public function get_billing_email() {
        return $this->email;
    }

    public function get_billing_phone() {
        return $this->phone;
    }

    public function get_billing_country() {
        return 'US';
    }
}
