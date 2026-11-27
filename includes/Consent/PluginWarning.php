<?php

namespace StorePulse\ConversionTracking\Consent;


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
        return self::build( $gap, $labels, in_array( ConsentMode::external_source(), $gap['tools'], true ), ConsentMode::api()->is_available() );
    }

    /**
     * The warning for a gap; each sentence is whole, so it translates as one.
     *
     * @param array $gap          See ConsentMode::plugin_gap().
     * @param array $labels       Platform ID => label.
     * @param bool  $google_owned Whether a silent tool owns Google consent mode.
     * @param bool  $api          Whether the WP Consent API is active.
     *
     * @return array|null
     */
    public static function build( array $gap, array $labels, $google_owned, $api = true ) {
        if ( '' === $gap['kind'] ) {
            return null;
        }

        $silent = 'silent' === $gap['kind'];
        $count  = count( $gap['tools'] );
        $names  = implode( ', ', array_map( array( ConsentData::class, 'source_name' ), $gap['tools'] ) );

        if ( ! $silent ) {
            $title = __( 'No cookie plugin found.', 'woocommerce-conversion-tracking' );
            $lead  = __( 'This setting needs a supported cookie consent plugin.', 'woocommerce-conversion-tracking' );
        } else {
            /* translators: %s: consent tool names, for example Real Cookie Banner */
            $title = sprintf( _n( '%s isn\'t passing cookie choices.', '%s aren\'t passing cookie choices.', $count, 'woocommerce-conversion-tracking' ), $names );
            // Without the WP Consent API no tool can pass them: installing it is the fix, as the Doctor says.
            $lead = $api
                ? _n( 'It is active but doesn\'t report visitors\' choices to the WP Consent API.', 'They are active but don\'t report visitors\' choices to the WP Consent API.', $count, 'woocommerce-conversion-tracking' )
                : _n( 'It is active, but the WP Consent API plugin isn\'t, so its choices don\'t reach your tags. Install the WP Consent API and connect it.', 'They are active, but the WP Consent API plugin isn\'t, so their choices don\'t reach your tags. Install the WP Consent API and connect them.', $count, 'woocommerce-conversion-tracking' );
        }

        $google = $silent
            ? __( 'Until then, Google gets no consent for visitors in Europe and measures them without cookies. Other visitors are tracked as usual.', 'woocommerce-conversion-tracking' )
            : __( 'Until one is active, Google gets no consent for visitors in Europe and measures them without cookies. Other visitors are tracked as usual.', 'woocommerce-conversion-tracking' );
        $other  = $silent
            /* translators: %s: platform name, for example Meta */
            ? __( 'Until then, %s events are sent to all visitors.', 'woocommerce-conversion-tracking' )
            /* translators: %s: platform name, for example Meta */
            : __( 'Until one is active, %s events are sent to all visitors.', 'woocommerce-conversion-tracking' );

        // Onboarding's, for every platform at once; Google is left out when the tool tells Google itself.
        if ( ! $silent ) {
            $all = __( 'Until one is active, your tags run for all visitors; Google measures visitors in Europe without cookies.', 'woocommerce-conversion-tracking' );
        } elseif ( $google_owned ) {
            $all = __( 'Until then, your tags other than Google\'s run for all visitors.', 'woocommerce-conversion-tracking' );
        } else {
            $all = __( 'Until then, your tags run for all visitors; Google measures visitors in Europe without cookies.', 'woocommerce-conversion-tracking' );
        }

        $messages = array( '' => $lead . ' ' . $all );

        foreach ( $labels as $id => $label ) {
            if ( self::GOOGLE !== $id ) {
                $messages[ $id ] = $lead . ' ' . sprintf( $other, $label );
            } elseif ( ! ( $silent && $google_owned ) ) {
                // Not when the tool owns Google consent mode: it tells Google itself.
                $messages[ $id ] = $lead . ' ' . $google;
            }
        }

        return array(
            'title'    => $title,
            'messages' => $messages,
            'link'     => array(
                'label' => $silent && ! $api
                    ? __( 'Get the WP Consent API', 'woocommerce-conversion-tracking' )
                    : __( 'See supported cookie plugins', 'woocommerce-conversion-tracking' ),
                'url'   => ConsentData::PLUGINS_URL,
            ),
        );
    }
}
