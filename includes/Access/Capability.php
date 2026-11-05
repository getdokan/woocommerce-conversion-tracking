<?php

namespace StorePulse\ConversionTracking\Access;

/**
 * The manage_wcct capability, granted at runtime without writing to roles.
 */
class Capability {

    /**
     * Capability that opens the Conversion Tracking settings.
     */
    const NAME = 'manage_wcct';

    /**
     * Hook the grant into every capability check.
     *
     * @return void
     */
    public function register() {
        add_filter( 'user_has_cap', array( $this, 'filter_user_caps' ) );
    }

    /**
     * Callback for user_has_cap.
     *
     * @param array $allcaps The user's capabilities.
     *
     * @return array
     */
    public function filter_user_caps( $allcaps ) {
        return is_array( $allcaps ) ? self::grant( $allcaps ) : $allcaps;
    }

    /**
     * Give manage_wcct to anyone who can manage options.
     *
     * @param array $allcaps The user's capabilities.
     *
     * @return array
     */
    public static function grant( array $allcaps ): array {
        if ( ! empty( $allcaps['manage_options'] ) ) {
            $allcaps[ self::NAME ] = true;
        }

        return $allcaps;
    }
}
