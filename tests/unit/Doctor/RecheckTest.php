<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Doctor\Recheck;

// phpcs:disable
if ( ! function_exists( 'is_multisite' ) ) {
    function is_multisite() {
        return ! empty( $GLOBALS['wcct_test_multisite'] );
    }
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
    function wp_doing_ajax() {
        return ! empty( $GLOBALS['wcct_test_doing_ajax'] );
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

    /**
     * Whether each re-check could light the badge.
     *
     * @var bool[]
     */
    public $badges = array();

    public function refresh( $badge = true ) {
        ++$this->ran;
        $this->badges[] = $badge;
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
        unset( $GLOBALS['wcct_test_options'], $GLOBALS['wcct_test_site_options'], $GLOBALS['wcct_test_multisite'], $GLOBALS['wcct_test_doing_ajax'], $GLOBALS['pagenow'] );
    }

    /**
     * An admin page load: plugins_loaded, then admin_init.
     *
     * @param callable|null $between Runs between the two.
     */
    private function page( $between = null ) {
        $recheck = new WCCT_Test_Recheck();

        $recheck->snapshot();

        if ( $between ) {
            $between();
        }

        $recheck->maybe_refresh();

        return $recheck;
    }

    /**
     * How many re-checks an admin page load runs.
     */
    private function load() {
        return $this->page()->ran;
    }

    public function test_the_first_load_only_records_the_plugins_and_an_unchanged_list_re_checks_nothing() {
        $this->assertSame( 0, $this->load() );
        $this->assertNotEmpty( $GLOBALS['wcct_test_options'][ Recheck::PLUGINS ] );
        $this->assertSame( 0, $this->load() );

        // The same plugins in another order.
        $GLOBALS['wcct_test_options']['active_plugins'] = array_reverse( $GLOBALS['wcct_test_options']['active_plugins'] );

        $this->assertSame( 0, $this->load() );
    }

    public function test_a_deactivation_re_checks_once_on_the_next_load_without_lighting_the_badge() {
        $this->load();

        $GLOBALS['wcct_test_options']['active_plugins'] = array( 'woocommerce/woocommerce.php' );

        $this->assertSame( array( false ), $this->page()->badges );
        $this->assertSame( 0, $this->load() );
    }

    public function test_it_compares_the_plugins_the_request_loaded_not_the_list_changed_during_it() {
        $this->load();

        // A plugin that deactivates another early in admin_init: this request still runs it.
        $this->assertSame(
            0,
            $this->page(
                function () {
                    $GLOBALS['wcct_test_options']['active_plugins'] = array( 'woocommerce/woocommerce.php' );
                }
            )->ran
        );
        $this->assertSame( 1, $this->load(), 'The next page load, without it' );
    }

    public function test_ajax_and_admin_post_requests_are_skipped() {
        $this->load();

        $GLOBALS['wcct_test_options']['active_plugins'] = array( 'woocommerce/woocommerce.php' );
        $GLOBALS['wcct_test_doing_ajax']                = true;

        $this->assertSame( 0, $this->load() );

        $GLOBALS['wcct_test_doing_ajax'] = false;
        $GLOBALS['pagenow']              = 'admin-post.php';

        $this->assertSame( 0, $this->load() );

        $GLOBALS['pagenow'] = 'index.php';

        $this->assertSame( 1, $this->load() );
    }

    public function test_nothing_runs_before_plugins_loaded() {
        $GLOBALS['wcct_test_options'][ Recheck::PLUGINS ] = 'old';

        $recheck = new WCCT_Test_Recheck();
        $recheck->maybe_refresh();

        $this->assertSame( 0, $recheck->ran );
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
