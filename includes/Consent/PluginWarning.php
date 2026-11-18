<?php

namespace StorePulse\ConversionTracking\Consent;

use StorePulse\ConversionTracking\Doctor\Checks\ConsentPluginCheck;

/**
 * The warning beside "Respect cookie choices" while no cookie plugin passes on the visitor's choice (#135).
 */
class PluginWarning {

    /**
     * Platform whose tags follow Google consent mode, so they aren't "sent to all visitors".
     */
    const GOOGLE = 'google';

    /**
     * The warning for the settings UI, null when a cookie plugin is connected.
     *
     * @param array $labels Platform ID => label, for the platforms that show the switch.
     *
     * @return array|null { title, messages: platform ID => text ('' key: every platform), link: { label, url } }
     */
    public static function get( array $labels ) {
        $gap = ConsentMode::plugin_gap();

        // Only when the silent tool itself owns Google consent mode does it tell Google the visitor's choice.
        return self::build( $gap, $labels, in_array( ConsentMode::external_source(), $gap['tools'], true ) );
    }

    /**
     * The warning for a gap.
     *
     * @param array $gap          See ConsentMode::plugin_gap().
     * @param array $labels       Platform ID => label.
     * @param bool  $google_owned Whether a silent tool owns Google consent mode.
     *
     * @return array|null
     */
    public static function build( array $gap, array $labels, $google_owned ) {
        if ( '' === $gap['kind'] ) {
            return null;
        }

        $silent = 'silent' === $gap['kind'];
        $names  = implode( ', ', array_map( array( ConsentData::class, 'source_name' ), $gap['tools'] ) );

        // Whole sentences per case, so each translates as one.
        $all      = $silent
            ? __( 'It is active but doesn\'t report visitors\' choices to the WP Consent API. Until it does, your tags run for all visitors; Google measures visitors in Europe without cookies.', 'woocommerce-conversion-tracking' )
            : __( 'This setting needs a supported cookie consent plugin. Until one is active, your tags run for all visitors; Google measures visitors in Europe without cookies.', 'woocommerce-conversion-tracking' );
        $google   = $silent
            ? __( 'It is active but doesn\'t report visitors\' choices to the WP Consent API. Until it does, Google gets no consent for visitors in Europe and measures them without cookies. Other visitors are tracked as usual.', 'woocommerce-conversion-tracking' )
            : __( 'This setting needs a supported cookie consent plugin. Until one is active, Google gets no consent for visitors in Europe and measures them without cookies. Other visitors are tracked as usual.', 'woocommerce-conversion-tracking' );
        $platform = $silent
            /* translators: %s: platform name, for example Meta */
            ? __( 'It is active but doesn\'t report visitors\' choices to the WP Consent API. Until it does, %s events are sent to all visitors.', 'woocommerce-conversion-tracking' )
            /* translators: %s: platform name, for example Meta */
            : __( 'This setting needs a supported cookie consent plugin. Until one is active, %s events are sent to all visitors.', 'woocommerce-conversion-tracking' );

        // The '' key is for onboarding: every platform at once. Google is left out when the tool tells Google itself.
        $messages = array(
            '' => $silent && $google_owned
                ? __( 'It is active but doesn\'t report visitors\' choices to the WP Consent API. Until it does, your tags other than Google\'s run for all visitors.', 'woocommerce-conversion-tracking' )
                : $all,
        );

        foreach ( $labels as $id => $label ) {
            if ( self::GOOGLE !== $id ) {
                $messages[ $id ] = sprintf( $platform, $label );
            } elseif ( ! ( $silent && $google_owned ) ) {
                // Not when a tool owns Google consent mode: it tells Google itself.
                $messages[ $id ] = $google;
            }
        }

        return array(
            'title'    => $silent
                /* translators: %s: consent tool names, for example Real Cookie Banner */
                ? sprintf( __( '%s isn\'t passing cookie choices.', 'woocommerce-conversion-tracking' ), $names )
                : __( 'No cookie plugin found.', 'woocommerce-conversion-tracking' ),
            'messages' => $messages,
            'link'     => array(
                'label' => __( 'See supported cookie plugins', 'woocommerce-conversion-tracking' ),
                'url'   => ConsentPluginCheck::PLUGINS_URL,
            ),
        );
    }
}
