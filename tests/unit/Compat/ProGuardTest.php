<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Compat\ProGuard;

/**
 * Stand-in for old Pro's main class
 */
class WCCT_Test_Old_Pro {
    public function includes() {}
    public function add_integrations( $integrations ) {
        return $integrations;
    }
    public static function boot() {}
}

/**
 * Stand-in for a third party hooked on the same filter
 */
class WCCT_Test_Other_Plugin {
    public function add_integrations( $integrations ) {
        return $integrations;
    }
}

/**
 * Which callbacks Free removes from the hooks old Pro loads from
 */
class ProGuardTest extends TestCase {

    public function test_only_the_pro_class_callbacks_are_picked() {
        $pro      = new WCCT_Test_Old_Pro();
        $other    = new WCCT_Test_Other_Plugin();
        $closure  = function ( $value ) {
            return $value;
        };
        $callbacks = array(
            10  => array(
                'a' => array( 'function' => array( $pro, 'includes' ), 'accepted_args' => 1 ),
                'b' => array( 'function' => array( $other, 'add_integrations' ), 'accepted_args' => 1 ),
                'c' => array( 'function' => $closure, 'accepted_args' => 1 ),
                'd' => array( 'function' => 'strtolower', 'accepted_args' => 1 ),
            ),
            120 => array(
                'e' => array( 'function' => array( $pro, 'add_integrations' ), 'accepted_args' => 1 ),
                'f' => array( 'function' => array( 'WCCT_Test_Old_Pro', 'boot' ), 'accepted_args' => 1 ),
                'g' => array( 'function' => '\WCCT_Test_Old_Pro::boot', 'accepted_args' => 1 ),
            ),
        );

        $this->assertSame(
            array(
                array( 10, array( $pro, 'includes' ) ),
                array( 120, array( $pro, 'add_integrations' ) ),
                array( 120, array( 'WCCT_Test_Old_Pro', 'boot' ) ),
                array( 120, '\WCCT_Test_Old_Pro::boot' ),
            ),
            ProGuard::owned_callbacks( $callbacks, 'WCCT_Test_Old_Pro' )
        );
    }

    public function test_the_default_owner_is_pros_main_class() {
        $this->assertSame( 'WeDevs_WC_Conversion_Tracking_Pro', ProGuard::PRO_CLASS );
        $this->assertSame( array( 'wcct_loaded', 'wcct_integrations', 'wcct_platforms' ), ProGuard::HOOKS );
    }

    /**
     * @dataProvider bad_lists
     */
    public function test_bad_lists_pick_nothing( $callbacks ) {
        $this->assertSame( array(), ProGuard::owned_callbacks( $callbacks, 'WCCT_Test_Old_Pro' ) );
    }

    public function bad_lists() {
        return array(
            'null'             => array( null ),
            'string'           => array( 'x' ),
            'entry without fn' => array( array( 10 => array( array( 'accepted_args' => 1 ) ) ) ),
            'empty callable'   => array( array( 10 => array( array( 'function' => array() ) ) ) ),
        );
    }
}
