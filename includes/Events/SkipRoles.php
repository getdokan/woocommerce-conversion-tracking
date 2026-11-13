<?php

namespace StorePulse\ConversionTracking\Events;

use StorePulse\ConversionTracking\Install;
use StorePulse\ConversionTracking\Settings\Store;

/**
 * Roles a platform never tracks ("Skip these roles"), filterable through wcct_skip_roles.
 *
 * Read from the 3.0 settings field platforms.<id>.skip_roles: a preset (admin-shop, admin, none) or a list of role slugs.
 */
class SkipRoles {

    /**
     * Presets the settings screen saves, by value.
     */
    const PRESETS = array(
        'admin-shop' => array( 'administrator', 'shop_manager' ),
        'admin'      => array( 'administrator' ),
        'none'       => array(),
    );

    /**
     * Preset when nothing is saved, on a new install.
     */
    const DEFAULT_PRESET = 'admin-shop';

    /**
     * Preset when nothing is saved and 2.x settings exist: upgraders keep tracking everyone.
     */
    const UPGRADE_PRESET = 'none';

    /**
     * Settings platform per integration ID, where they differ.
     */
    const SETTINGS_PLATFORM = array(
        'ga4'     => 'google',
        'adwords' => 'google',
    );

    /**
     * Integrations without the setting; they track everyone.
     */
    const NOT_SKIPPED = array( 'custom' );

    /**
     * Skipped roles for an integration.
     *
     * @param string $integration_id Integration ID.
     *
     * @return string[]
     */
    public static function for_platform( $integration_id ) {
        if ( in_array( $integration_id, self::NOT_SKIPPED, true ) ) {
            return array();
        }

        $roles = self::saved( $integration_id );

        if ( null === $roles ) {
            $roles = self::PRESETS[ self::default_preset( Install::is_upgrade() ) ];
        }

        /**
         * Roles a platform never tracks.
         *
         * @since 3.0.0
         *
         * @param string[] $roles          Role slugs.
         * @param string   $integration_id Integration ID.
         */
        $roles = apply_filters( 'wcct_skip_roles', $roles, $integration_id );

        $clean = self::normalise( $roles );

        return null === $clean ? array() : $clean;
    }

    /**
     * Whether a user with these roles is skipped.
     *
     * @param string[] $user_roles Roles of the user.
     * @param string[] $skipped    Skipped roles.
     *
     * @return bool
     */
    public static function matches( $user_roles, array $skipped ) {
        return (bool) array_intersect( array_filter( (array) $user_roles, 'is_string' ), $skipped );
    }

    /**
     * Whether the current visitor is in a role the integration skips. Visitors who are not logged in never are.
     *
     * @param string $integration_id Integration ID.
     *
     * @return bool
     */
    public static function skips_current_user( $integration_id ) {
        if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
            return false;
        }

        $skipped = self::for_platform( $integration_id );

        return $skipped && self::matches( wp_get_current_user()->roles, $skipped );
    }

    /**
     * Role slugs from a preset name or a list; null when the value is neither.
     *
     * @param mixed $value Preset name or role slugs.
     *
     * @return string[]|null
     */
    public static function normalise( $value ) {
        if ( is_string( $value ) ) {
            return isset( self::PRESETS[ $value ] ) ? self::PRESETS[ $value ] : null;
        }

        if ( ! is_array( $value ) ) {
            return null;
        }

        $roles = array();

        foreach ( $value as $role ) {
            if ( is_string( $role ) && '' !== trim( $role ) ) {
                $roles[] = trim( $role );
            }
        }

        return array_values( array_unique( $roles ) );
    }

    /**
     * Preset used when nothing is saved: upgraders keep tracking everyone, new installs skip admins and shop managers.
     *
     * @param bool $is_upgrade Whether the store came from 2.x.
     *
     * @return string
     */
    public static function default_preset( $is_upgrade ) {
        return $is_upgrade ? self::UPGRADE_PRESET : self::DEFAULT_PRESET;
    }

    /**
     * The saved value for an integration, or null when none is saved.
     *
     * @param string $integration_id Integration ID.
     *
     * @return string[]|null
     */
    private static function saved( $integration_id ) {
        $settings = Store::load();
        $platform = isset( self::SETTINGS_PLATFORM[ $integration_id ] ) ? self::SETTINGS_PLATFORM[ $integration_id ] : $integration_id;

        if ( ! isset( $settings['platforms'][ $platform ] ) || ! is_array( $settings['platforms'][ $platform ] ) || ! array_key_exists( 'skip_roles', $settings['platforms'][ $platform ] ) ) {
            return null;
        }

        return self::normalise( $settings['platforms'][ $platform ]['skip_roles'] );
    }
}
