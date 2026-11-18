<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Doctor\Recheck;

// phpcs:disable
if ( ! function_exists( 'is_multisite' ) ) {
    function is_multisite() {
        return ! empty( $GLOBALS['wcct_test_multisite'] );
    }
}

if ( ! function_exists( 'get_site_option' ) ) {
    function get_site_option( $name, $default = false ) {
        return isset( $GLOBALS['wcct_test_site_options'][ $name ] ) ? $GLOBALS['wcct_test_site_options'][ $name ] : $default;
    }
}

// phpcs:enable

/**
 * Records re-checks instead of running the Doctor.
 */
class WCCT_Test_Recheck extends Recheck {

    /**
     * Re-checks run.
     *
     * @var int
     */
    public $ran = 0;

    public function refresh() {
        ++$this->ran;
    }
}

/**
 * Tests for the re-check on the admin page load after the active plugins change, such as a deactivation (#140).
 */
class RecheckTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['wcct_test_options']      = array( 'active_plugins' => array( 'woocommerce/woocommerce.php', 'pixelyoursite/pixelyoursite.php' ) );
        $GLOBALS['wcct_test_site_options'] = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_site_options'], $GLOBALS['wcct_test_multisite'] );
    }

    /**
     * How many re-checks an admin page load runs.
     */
    private function load() {
        $recheck = new WCCT_Test_Recheck();

        $recheck->maybe_refresh();

        return $recheck->ran;
    }

    public function test_the_first_load_only_records_the_plugins_and_an_unchanged_list_re_checks_nothing() {
        $this->assertSame( 0, $this->load() );
        $this->assertNotEmpty( $GLOBALS['wcct_test_options'][ Recheck::PLUGINS ] );
        $this->assertSame( 0, $this->load() );

        // The same plugins in another order.
        $GLOBALS['wcct_test_options']['active_plugins'] = array_reverse( $GLOBALS['wcct_test_options']['active_plugins'] );

        $this->assertSame( 0, $this->load() );
    }

    public function test_a_deactivation_re_checks_once_on_the_next_load() {
        $this->load();

        $GLOBALS['wcct_test_options']['active_plugins'] = array( 'woocommerce/woocommerce.php' );

        $this->assertSame( 1, $this->load() );
        $this->assertSame( 0, $this->load() );
    }

    public function test_a_network_deactivation_re_checks_every_site_once_wherever_it_was_done() {
        $GLOBALS['wcct_test_multisite']    = true;
        $GLOBALS['wcct_test_site_options'] = array( 'active_sitewide_plugins' => array( 'pixelyoursite/pixelyoursite.php' => 1700000000 ) );
        $sites                             = array();

        // Two sites, each with its own options, active on the second only.
        foreach ( array( 'main', 'sub' ) as $site ) {
            $GLOBALS['wcct_test_options'] = array( 'active_plugins' => array( 'woocommerce/woocommerce.php' ) );
            $this->load();
            $sites[ $site ] = $GLOBALS['wcct_test_options'];
        }

        $GLOBALS['wcct_test_site_options']['active_sitewide_plugins'] = array();

        foreach ( $sites as $site => $options ) {
            $GLOBALS['wcct_test_options'] = $options;

            $this->assertSame( 1, $this->load(), $site );
            $this->assertSame( 0, $this->load(), $site );
        }
    }
}
