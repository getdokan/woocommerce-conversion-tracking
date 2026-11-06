<?php

use StorePulse\ConversionTracking\Consent\WpConsentApi;

/**
 * WP Consent API with a fixed consent type and per-category answers.
 */
class WCCT_Test_Fake_Consent_Api extends WpConsentApi {

    public $available;
    public $type;
    public $consents;
    public $asked = array();

    public function __construct( $available = true, $type = '', array $consents = array() ) {
        $this->available = $available;
        $this->type      = $type;
        $this->consents  = $consents;
    }

    public function is_available() {
        return $this->available;
    }

    public function get_consent_type() {
        return $this->type;
    }

    public function has_consent( $category ) {
        $this->asked[] = $category;

        return ! empty( $this->consents[ $category ] );
    }

    public function get_cookie_prefix() {
        return 'wp_consent';
    }
}
