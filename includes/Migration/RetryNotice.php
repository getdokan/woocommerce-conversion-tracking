<?php

namespace StorePulse\ConversionTracking\Migration;


/**
 * Admin notice with "Retry migration" after the 3.0 settings migration failed; tracking keeps the 2.x settings meanwhile.
 */
class RetryNotice {

    /**
     * admin-post action and nonce action.
     */
    const ACTION = 'wcct_retry_migration';

    /**
     * Hook the notice and its form handler.
     *
     * @return void
     */
    public static function register() {
        add_action( 'admin_notices', array( __CLASS__, 'render' ) );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
    }

    /**
     * Whether the current user should see the notice.
     *
     * @return bool
     */
    public static function visible(): bool {
        // Pending first: a finished store answers from the autoloaded marker.
        return function_exists( 'wcct_manage_cap' ) && current_user_can( wcct_manage_cap() ) && false !== get_option( SettingsMigration::ERROR_OPTION, false ) && SettingsMigration::retry_pending();
    }

    /**
     * Print the notice.
     *
     * @return void
     */
    public static function render() {
        if ( ! self::visible() ) {
            return;
        }

        $error   = get_option( SettingsMigration::ERROR_OPTION, array() );
        $message = is_array( $error ) && isset( $error['message'] ) && is_scalar( $error['message'] ) ? (string) $error['message'] : '';
        ?>
        <div class="notice notice-error" id="wcct-migration-failed">
            <p><?php esc_html_e( 'Conversion Tracking couldn\'t move your settings to version 3.0. Tracking keeps running with your previous settings until it does.', 'woocommerce-conversion-tracking' ); ?></p>
            <?php if ( '' !== $message ) : ?>
                <p>
                    <?php
                    /* translators: %s: error message */
                    echo esc_html( sprintf( __( 'Error: %s', 'woocommerce-conversion-tracking' ), $message ) );
                    ?>
                </p>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                <?php wp_nonce_field( self::ACTION ); ?>
                <p><button type="submit" class="button button-primary"><?php esc_html_e( 'Retry migration', 'woocommerce-conversion-tracking' ); ?></button></p>
            </form>
        </div>
        <?php
    }

    /**
     * Run the migration again, then go back to the page the notice was on.
     *
     * @return void
     */
    public static function handle() {
        if ( ! function_exists( 'wcct_manage_cap' ) || ! current_user_can( wcct_manage_cap() ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'woocommerce-conversion-tracking' ), '', array( 'response' => 403 ) );
        }

        check_admin_referer( self::ACTION );

        SettingsMigration::retry();

        $back = wp_get_referer();

        wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=conversion-tracking' ) );
        exit;
    }
}
