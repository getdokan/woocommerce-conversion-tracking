<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * One sign-up event per new account, on the user's next logged-in page view
 */
class SignUpTest extends TestCase {

    public function test_every_new_account_sends_one_sign_up() {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/sign-up.php',
                'rollback' => true,
                'options'  => array( 'wcct_settings' => WCCT_Test_Settings::lite_all() ),
            )
        );

        $this->assertNull( $result['error'] );
        $this->assertTrue( $result['value']['customer_flagged'] );
        $this->assertTrue( $result['value']['wp_user_flagged'] );
        $this->assertSame( array(), $result['value']['logged_out'] );
        $this->assertSame( array( 'sign_up' ), $result['value']['first_view'] );
        $this->assertSame( array(), $result['value']['second_view'] );
        $this->assertTrue( $result['value']['cleared'] );
        $this->assertSame( array( 'sign_up' ), $result['value']['wp_user_first_login'] );
    }
}
