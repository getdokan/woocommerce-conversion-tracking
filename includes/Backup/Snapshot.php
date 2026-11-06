<?php

namespace StorePulse\ConversionTracking\Backup;

use StorePulse\ConversionTracking\Security\Vault;

/**
 * The pre-3.0 settings as a downloadable JSON document, with passwords and secrets removed.
 */
class Snapshot {

    /**
     * Frozen pre-3.0 copy: option name => value as 2.x stored it. Written once by the 3.0 migration (#122/#123) before it changes anything, never updated after.
     */
    const SNAPSHOT_OPTION = 'wcct_pre3_snapshot';

    /**
     * Filter for the 2.x options read while there is no frozen copy; Pro adds its own.
     */
    const FILTER = 'wcct_backup_options';

    /**
     * Without a frozen copy, a store has a backup only if this 2.x option exists.
     */
    const REQUIRED_OPTION = 'wcct_settings';

    /**
     * Format name written into the file.
     */
    const FORMAT = 'wcct-settings-backup';

    /**
     * Keys whose values are removed wherever they appear (the 2.x feed login keeps its password in plain text).
     */
    const SECRET_KEY_PATTERN = '/^(pass|passwd|password|pwd|passphrase|secret|token|api_key)$|[_\-](password|passwd|pwd|passphrase|secret|token|key)$/i';

    /**
     * Names of the 2.x options read while there is no frozen copy.
     *
     * @return string[]
     */
    public static function options(): array {
        /**
         * Filter the 2.x options the settings backup holds.
         *
         * @since 3.0.0
         *
         * @param string[] $options Option names.
         */
        $options = apply_filters( self::FILTER, array( self::REQUIRED_OPTION ) );

        return array_values( array_unique( array_filter( (array) $options, 'is_string' ) ) );
    }

    /**
     * The backed-up options, scrubbed: the frozen copy when the migration wrote one, else the live 2.x options.
     *
     * @param callable $read Receives an option name, returns its value or null when missing.
     *
     * @return array|null { source: snapshot|live, options: name => value }, null when the store has nothing from before 3.0.
     */
    public static function load( callable $read ) {
        $snapshot = call_user_func( $read, self::SNAPSHOT_OPTION );

        if ( is_array( $snapshot ) && $snapshot ) {
            $values = self::collect(
                array_keys( $snapshot ),
                function ( $option ) use ( $snapshot ) {
                    return $snapshot[ $option ];
                }
            );

            return $values ? array( 'source' => 'snapshot', 'options' => $values ) : null;
        }

        $values = self::collect( self::options(), $read );

        // Options 3.0 writes too (say, wcct_permissions) don't make a backup on their own.
        if ( ! array_key_exists( self::REQUIRED_OPTION, $values ) ) {
            return null;
        }

        return array(
            'source'  => 'live',
            'options' => $values,
        );
    }

    /**
     * The stored values of the options that exist, secrets removed.
     *
     * @param array    $options Option names.
     * @param callable $read    Receives an option name, returns its value or null when missing.
     *
     * @return array Option name => value.
     */
    public static function collect( array $options, callable $read ): array {
        $values = array();

        foreach ( $options as $option ) {
            // Encrypted secrets never leave the store, whatever lists them.
            if ( ! is_string( $option ) || 0 === strpos( $option, Vault::OPTION_PREFIX ) || preg_match( self::SECRET_KEY_PATTERN, $option ) ) {
                continue;
            }

            $value = call_user_func( $read, $option );

            if ( null !== $value ) {
                $values[ $option ] = self::scrub( $value );
            }
        }

        return $values;
    }

    /**
     * The backup document.
     *
     * @param array  $backup  Output of load().
     * @param string $version Plugin version writing it.
     * @param int    $time    Unix time.
     *
     * @return array
     */
    public static function document( array $backup, string $version, int $time ): array {
        return array(
            'format'         => self::FORMAT,
            'format_version' => 1,
            'plugin_version' => $version,
            'created_at'     => gmdate( 'c', $time ),
            'source'         => $backup['source'],
            'options'        => $backup['options'],
        );
    }

    /**
     * Download file name.
     *
     * @param int $time Unix time.
     *
     * @return string
     */
    public static function filename( int $time ): string {
        return 'conversion-tracking-settings-before-3.0-' . gmdate( 'Y-m-d', $time ) . '.json';
    }

    /**
     * Remove every password and secret key, at any depth.
     *
     * @param mixed $value Value.
     *
     * @return mixed
     */
    public static function scrub( $value ) {
        if ( is_object( $value ) ) {
            $value = get_object_vars( $value );
        }

        if ( ! is_array( $value ) ) {
            return $value;
        }

        foreach ( $value as $key => $item ) {
            if ( is_string( $key ) && preg_match( self::SECRET_KEY_PATTERN, $key ) ) {
                unset( $value[ $key ] );
                continue;
            }

            $value[ $key ] = self::scrub( $item );
        }

        return $value;
    }
}
