<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * The registration redirect is left alone: sign-ups are detected with a user meta flag, not a query argument
 */
class RegistrationRedirectTest extends TestCase {

    public function test_the_registration_redirect_has_no_new_customer_flag() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/registration-redirect.php',
                'rollback' => true,
            )
        );

        $this->assertNull( $result['error'] );
        $this->assertSame( '{home}/my-account/', WCCT_Test_Fixture::normalise( $result['value'], $result['home'] ) );
    }
}
