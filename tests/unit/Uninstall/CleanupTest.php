<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Uninstall\Cleanup;

/**
 * Tests for the list of data Free's uninstall removes
 */
class CleanupTest extends TestCase {

    const SLUG = 'woocommerce-conversion-tracking';

    /**
     * Every option Free writes today, landed or in progress (#111, #112).
     */
    const FREE_OPTIONS = array(
        'wcct_settings',
        'wcct_settings_v3',
        'wcct_version',
        'wcct_installed',
        'wcct_dismissable_notice',
        'wcct_paused_platforms',
        'wcct_consent_mode',
        'wcct_install_type',
        'wcct_migration_done',
        'wcct_migration_lock',
        'wcct_migration_flags',
        'wcct_migration_error',
        'wcct_pre3_snapshot',
        'wcct_onboarding',
        'wcct_count_once_since',
        'wcct_secret_capi_token',
        'wcct_order_log_db_version',
        'wcct_verify',
        'wcct_last_purchase_facebook',
        'wcct_last_purchase_bing_ads',
        'wcct_doctor_results',
        'wcct_doctor_dismissed',
        'wcct_doctor_new',
        '_transient_wcct_upgrade_to_20',
        '_transient_timeout_wcct_upgrade_to_20',
        '_site_transient_wcct_example',
        'woocommerce_wc_conv_tracking_settings',
        'woocommerce-conversion-tracking_allow_tracking',
        'woocommerce-conversion-tracking_tracking_notice',
        'woocommerce-conversion-tracking_tracking_last_send',
        'woocommerce-conversion-tracking_tracking_skipped',
    );

    /**
     * Every option Pro writes.
     */
    const PRO_OPTIONS = array(
        'wcct_permissions',
        'wcct_data_feed',
        'wcct_feed_status',
        'wcct_feed_build',
        'wcct_feed_login',
        'wcct_secret_feed_password',
        'wcct_pro_version',
        'wcct_pro_migration_done',
        'wcct_pro_migration_lock',
        '_transient_wcct_feed_example',
    );

    public function test_without_pro_every_option_is_deleted() {
        $names = array_merge( self::FREE_OPTIONS, self::PRO_OPTIONS );

        $this->assertSame( $names, Cleanup::options( $names, false, self::SLUG ) );
    }

    public function test_with_pro_installed_pro_options_stay() {
        $names = array_merge( self::FREE_OPTIONS, self::PRO_OPTIONS );

        $this->assertSame( self::FREE_OPTIONS, Cleanup::options( $names, true, self::SLUG ) );
    }

    public function test_other_plugins_options_are_never_deleted() {
        $names = array( 'woocommerce_version', 'wc_conv_settings', 'xwcct_settings', '_transient_woocommerce_x', 'other-plugin_allow_tracking', 'blogname', 42 );

        $this->assertSame( array(), Cleanup::options( $names, false, self::SLUG ) );
    }

    public function test_tracker_options_follow_the_folder_name() {
        $this->assertSame( array( 'ct-renamed_allow_tracking' ), Cleanup::options( array( 'ct-renamed_allow_tracking', 'woocommerce-conversion-tracking_allow_tracking' ), false, 'ct-renamed' ) );
        $this->assertSame( array(), Cleanup::tracker_options( '' ) );
    }

    public function test_sent_flags_stay_and_the_thankyou_flag_goes() {
        $this->assertTrue( Cleanup::removes_order_meta( '_wcct_thankyou_seen' ) );

        foreach ( array_merge( array( '_wcct_sent_facebook', '_wcct_sent_google', '_wcct_sent_bing_ads' ), Cleanup::KEPT_ORDER_META ) as $key ) {
            $this->assertFalse( Cleanup::removes_order_meta( $key ), $key );
        }
    }

    public function test_table_user_meta_and_product_meta() {
        $this->assertSame( array( 'wcct_order_log' ), Cleanup::TABLES );
        $this->assertSame( '\_wcct\_%', Cleanup::USER_META_PATTERN );
        $this->assertSame( array( '_wc_conv_track' ), Cleanup::PRODUCT_META );
    }

    public function test_pro_action_group_and_feed_folder_stay_while_pro_is_installed() {
        $this->assertSame( array( 'wcct' ), Cleanup::action_groups( true ) );
        $this->assertSame( array( 'wcct', 'wcct-product-feed' ), Cleanup::action_groups( false ) );
        $this->assertSame( array(), Cleanup::upload_dirs( true ) );
        $this->assertSame( array( 'wcct-feed' ), Cleanup::upload_dirs( false ) );
    }

    public function test_cron_hooks() {
        $hooks = array( 'wcct_prune_order_log', 'wcct_feed_daily_build', 'woocommerce-conversion-tracking_tracker_send_event', 'woocommerce_cleanup_sessions', 'other_tracker_send_event', null );

        $this->assertSame( array( 'wcct_prune_order_log', 'wcct_feed_daily_build', 'woocommerce-conversion-tracking_tracker_send_event' ), Cleanup::cron_hooks( $hooks, false, self::SLUG ) );
        $this->assertSame( array( 'wcct_prune_order_log', 'woocommerce-conversion-tracking_tracker_send_event' ), Cleanup::cron_hooks( $hooks, true, self::SLUG ) );
    }
}
