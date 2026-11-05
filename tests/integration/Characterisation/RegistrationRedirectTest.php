<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * Characterisation test for how the registration page view is marked
 */
class RegistrationRedirectTest extends TestCase {

    public function test_the_registration_redirect_carries_the_new_customer_flag() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/registration-redirect.php',
                'rollback' => true,
            )
        );

        $this->assertNull( $result['error'] );
        $this->assertSame( '{home}/my-account/?_wc_user_reg=true', WCCT_Test_Fixture::normalise( $result['value'], $result['home'] ) );
    }
}
