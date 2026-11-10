<?php

namespace StorePulse\ConversionTracking\Dashboard;

use StorePulse\ConversionTracking\Alerts\Alerts;
use StorePulse\ConversionTracking\Alerts\Badge;
use StorePulse\ConversionTracking\Alerts\Types;
use StorePulse\ConversionTracking\Doctor\Doctor;
use StorePulse\ConversionTracking\Log\Activity;
use StorePulse\ConversionTracking\Log\LastPurchase;
use StorePulse\ConversionTracking\Log\OrderLog;
use StorePulse\ConversionTracking\Log\Statuses;
use StorePulse\ConversionTracking\Settings\Schema;
use StorePulse\ConversionTracking\Settings\Store;
use StorePulse\ConversionTracking\Settings\Values;

/**
 * The Dashboard's numbers for the last 24 hours, from the Activity statuses: counts, the hourly chart, platform cards and what to do next.
 */
class Metrics {

    /**
     * Hours in the chart.
     */
    const HOURS = 24;

    /**
     * Seconds in an hour.
     */
    const HOUR = 3600;

    /**
     * Platform card states.
     */
    const WORKING    = 'working';
    const ATTENTION  = 'attention';
    const WAITING    = 'waiting';
    const PAUSED     = 'paused';
    const NOT_SET_UP = 'not_set_up';

    /**
     * Where the cookie choices (Respect cookie choices) live in the app.
     */
    const COOKIE_CHOICES = '#/platforms?section=cookie-choices';

    /**
     * Not confirmed orders the card lists.
     */
    const MAX_ORDERS = 20;

    /**
     * Doctor findings listed under what to do next.
     */
    const MAX_DOCTOR_STEPS = 5;

    /**
     * Counts of evaluated orders. Purchases tracked are the checked-in paid orders (confirmed or not confirmed); No check-in, pending, waiting and skipped orders are counted apart.
     *
     * @param array[] $orders Results of Statuses::evaluate().
     *
     * @return array purchases, sent, not_confirmed, no_checkin, pending, waiting, platforms (ID => confirmed, not_confirmed), not_confirmed_orders (id, number, platforms).
     */
    public static function tally( array $orders ) {
        $tally = array(
            'purchases'            => 0,
            'sent'                 => 0,
            'not_confirmed'        => 0,
            'no_checkin'           => 0,
            'pending'              => 0,
            'waiting'              => 0,
            'platforms'            => array(),
            'not_confirmed_orders' => array(),
        );

        foreach ( $orders as $order ) {
            $status = isset( $order['status'] ) ? $order['status'] : '';

            switch ( $status ) {
                case Statuses::CONFIRMED:
                    ++$tally['purchases'];
                    ++$tally['sent'];
                    break;
                case Statuses::NOT_CONFIRMED:
                    ++$tally['purchases'];
                    ++$tally['not_confirmed'];
                    break;
                case Statuses::NO_CHECKIN:
                    ++$tally['no_checkin'];
                    break;
                case Statuses::PENDING:
                    ++$tally['pending'];
                    break;
                case Statuses::WAITING:
                    ++$tally['waiting'];
                    break;
            }

            $missed = array();

            foreach ( isset( $order['platforms'] ) && is_array( $order['platforms'] ) ? $order['platforms'] : array() as $platform ) {
                // A platform that didn't check in while others did is listed, never judged.
                if ( ! empty( $platform['unknown'] ) || ! in_array( $platform['status'], array( Statuses::CONFIRMED, Statuses::NOT_CONFIRMED ), true ) ) {
                    continue;
                }

                $id = (string) $platform['id'];

                if ( ! isset( $tally['platforms'][ $id ] ) ) {
                    $tally['platforms'][ $id ] = array(
                        'confirmed'     => 0,
                        'not_confirmed' => 0,
                    );
                }

                if ( Statuses::CONFIRMED === $platform['status'] ) {
                    ++$tally['platforms'][ $id ]['confirmed'];
                } else {
                    ++$tally['platforms'][ $id ]['not_confirmed'];
                    $missed[] = $id;
                }
            }

            if ( Statuses::NOT_CONFIRMED === $status && count( $tally['not_confirmed_orders'] ) < self::MAX_ORDERS ) {
                $tally['not_confirmed_orders'][] = array(
                    'id'        => (int) $order['id'],
                    'number'    => isset( $order['number'] ) ? (string) $order['number'] : (string) $order['id'],
                    'platforms' => $missed,
                );
            }
        }

        return $tally;
    }

    /**
     * Purchases per hour over the last 24 hours, by the time each order was created; an order created before the window counts at its check-in (paid later), or in the first hour.
     * Sent = confirmed orders, not confirmed apart; No check-in, pending and waiting orders aren't drawn.
     *
     * @param array[] $orders Results of Statuses::evaluate().
     * @param int     $now    Unix time.
     * @param int     $offset Site time zone offset now, in seconds, so the hours start on the site's clock.
     *
     * @return array[] Oldest first: start (Unix time), sent, not_confirmed.
     */
    public static function hourly( array $orders, $now, $offset = 0 ) {
        $now     = (int) $now;
        $offset  = (int) $offset;
        $current = (int) ( floor( ( $now + $offset ) / self::HOUR ) * self::HOUR ) - $offset;
        $first   = $current - ( self::HOURS - 1 ) * self::HOUR;
        $hours   = array();

        for ( $i = 0; $i < self::HOURS; $i++ ) {
            $hours[] = array(
                'start'         => $first + $i * self::HOUR,
                'sent'          => 0,
                'not_confirmed' => 0,
            );
        }

        foreach ( $orders as $order ) {
            $status = isset( $order['status'] ) ? $order['status'] : '';

            if ( Statuses::CONFIRMED !== $status && Statuses::NOT_CONFIRMED !== $status ) {
                continue;
            }

            $index = (int) floor( ( self::chart_time( $order, $first ) - $first ) / self::HOUR );
            $index = min( self::HOURS - 1, max( 0, $index ) );

            ++$hours[ $index ][ Statuses::CONFIRMED === $status ? 'sent' : 'not_confirmed' ];
        }

        return $hours;
    }

    /**
     * When an order shows in the chart: its creation, or for an order created before the first hour, its first check-in (or thank-you page visit).
     *
     * @param array $order Result of Statuses::evaluate().
     * @param int   $first Start of the first hour.
     *
     * @return int Unix time.
     */
    public static function chart_time( array $order, $first ) {
        $created = isset( $order['created'] ) ? (int) $order['created'] : 0;

        if ( $created >= $first ) {
            return $created;
        }

        $checked_in = array_filter( array_map( 'intval', array_column( isset( $order['platforms'] ) && is_array( $order['platforms'] ) ? $order['platforms'] : array(), 'checked_in_at' ) ) );

        if ( $checked_in ) {
            return min( $checked_in );
        }

        return ! empty( $order['thankyou'] ) ? (int) $order['thankyou'] : $created;
    }

    /**
     * An hour's label on the site's clock (H:00), from the hour's own offset so a daylight saving change shows.
     *
     * @param int                $start Unix time.
     * @param \DateTimeZone|null $zone  Time zone; the site's by default.
     *
     * @return string
     */
    public static function hour_label( $start, $zone = null ) {
        if ( ! $zone instanceof \DateTimeZone && function_exists( 'wp_date' ) ) {
            return (string) wp_date( 'H:00', (int) $start );
        }

        $zone = $zone instanceof \DateTimeZone ? $zone : new \DateTimeZone( 'UTC' );

        return ( new \DateTimeImmutable( '@' . (int) $start ) )->setTimezone( $zone )->format( 'H:00' );
    }

    /**
     * A platform card's state.
     *
     * @param array $platform connected, paused, not_confirmed (last 24 hours), confirmed (last 24 hours), last_purchase (array|null), needs_fix (a setting to fix: X's retired codes, a Doctor finding).
     *
     * @return string
     */
    public static function state( array $platform ) {
        if ( empty( $platform['connected'] ) ) {
            return self::NOT_SET_UP;
        }

        if ( ! empty( $platform['paused'] ) ) {
            return self::PAUSED;
        }

        if ( ! empty( $platform['not_confirmed'] ) || ! empty( $platform['needs_fix'] ) ) {
            return self::ATTENTION;
        }

        return ! empty( $platform['last_purchase'] ) || ! empty( $platform['confirmed'] ) ? self::WORKING : self::WAITING;
    }

    /**
     * Open Doctor findings worth a look: warnings that aren't dismissed.
     *
     * @param array $report Doctor::report().
     *
     * @return array[]
     */
    public static function open_findings( array $report ) {
        return array_values(
            array_filter(
                isset( $report['findings'] ) && is_array( $report['findings'] ) ? $report['findings'] : array(),
                function ( $finding ) {
                    return is_array( $finding ) && empty( $finding['dismissed'] ) && isset( $finding['severity'] ) && 'warning' === $finding['severity'];
                }
            )
        );
    }

    /**
     * The settings platform a finding's action opens (#/platforms/<id>), or an empty string.
     *
     * @param array $finding Finding.
     *
     * @return string
     */
    public static function finding_platform( array $finding ) {
        $url = isset( $finding['action']['url'] ) && is_string( $finding['action']['url'] ) ? $finding['action']['url'] : '';

        return preg_match( '~^#/platforms/([A-Za-z0-9_\-]+)(?:[/?]|$)~', $url, $match ) ? $match[1] : '';
    }

    /**
     * Everything the metrics route returns.
     *
     * @param int|null $now Unix time, default now.
     *
     * @return array
     */
    public static function build( $now = null ) {
        $now = null === $now ? time() : (int) $now;

        // Fresh, as the log route reads it.
        Activity::reset();

        $orders    = Activity::orders( $now );
        $tally     = self::tally( $orders );
        $schema    = Schema::get();
        $values    = Values::get( $schema, Store::load() );
        $connected = ConnectedAt::connected( $schema, $values );
        $times     = ConnectedAt::get( $connected );
        $report    = Doctor::report();
        $findings  = self::open_findings( $report );
        $alerts    = Alerts::states();
        $platforms = array();
        $fixes     = array();

        foreach ( $findings as $finding ) {
            $id = self::finding_platform( $finding );

            if ( '' !== $id && ! isset( $fixes[ $id ] ) ) {
                $fixes[ $id ] = $finding;
            }
        }

        foreach ( $schema['platforms'] as $id => $platform ) {
            $id     = (string) $id;
            $own    = isset( $values['platforms'][ $id ] ) && is_array( $values['platforms'][ $id ] ) ? $values['platforms'][ $id ] : array();
            $counts = isset( $tally['platforms'][ $id ] ) ? $tally['platforms'][ $id ] : array(
                'confirmed'     => 0,
                'not_confirmed' => 0,
            );
            $card   = array(
                'id'            => $id,
                'name'          => (string) $platform['label'],
                'connected'     => in_array( $id, $connected, true ),
                'paused'        => ! empty( $own['paused'] ),
                'confirmed'     => $counts['confirmed'],
                'not_confirmed' => $counts['not_confirmed'],
                'last_purchase' => self::last_purchase( array_merge( array( $id ), (array) $platform['integrations'] ), $now ),
                'connected_at'  => isset( $times[ $id ] ) && $times[ $id ] ? gmdate( 'c', $times[ $id ] ) : null,
                'needs_resetup' => ! empty( $own['needs_resetup'] ),
                'finding'       => isset( $fixes[ $id ] ) ? $fixes[ $id ] : null,
            );

            $card['needs_fix'] = $card['needs_resetup'] || null !== $card['finding'];
            $card['state']     = self::state( $card );
            $card['note']      = self::note( $card, isset( $times[ $id ] ) ? $times[ $id ] : 0, $now );
            $platforms[]       = $card;
        }

        $working = count( wp_list_filter( $platforms, array( 'state' => self::WORKING ) ) );

        // The menu badge reads the same count until the next check-in.
        if ( ! empty( $alerts[ Types::NOT_CONFIRMED ] ) ) {
            Badge::remember( $tally['not_confirmed'], Activity::truncated(), $now );
        }

        return array(
            'since'                => gmdate( 'c', $now - OrderLog::RETENTION ),
            'now'                  => gmdate( 'c', $now ),
            'purchases'            => $tally['purchases'],
            'sent'                 => $tally['sent'],
            'not_confirmed'        => $tally['not_confirmed'],
            'not_confirmed_by'     => array_filter( wp_list_pluck( $tally['platforms'], 'not_confirmed' ) ),
            'no_checkin'           => $tally['no_checkin'],
            'pending'              => $tally['pending'],
            'waiting'              => $tally['waiting'],
            'platforms_working'    => $working,
            'platforms_connected'  => count( $connected ),
            'hourly'               => self::hourly_output( $orders, $now ),
            'platforms'            => array_map( array( self::class, 'platform_output' ), $platforms ),
            'not_confirmed_orders' => ! empty( $alerts[ Types::NOT_CONFIRMED ] ) ? $tally['not_confirmed_orders'] : array(),
            'alerts'               => $alerts,
            'doctor'               => array(
                'ran_at'  => isset( $report['ran_at'] ) ? $report['ran_at'] : null,
                'ran_ago' => isset( $report['ran_ago'] ) ? (string) $report['ran_ago'] : '',
                'open'    => count( $findings ),
                'new'     => ! empty( $alerts[ Alerts::DOCTOR ] ) && isset( $report['new'] ) ? (int) $report['new'] : 0,
            ),
            'next_steps'           => self::next_steps( $platforms, $findings, ! empty( $report['ran_at'] ) ),
        );
    }

    /**
     * What to do next: one action per amber state, empty when everything is green.
     *
     * @param array[] $platforms Platform cards.
     * @param array[] $findings  Open Doctor findings.
     * @param bool    $ran       Whether the Doctor ever ran.
     *
     * @return array[] id, text, link, link_label.
     */
    public static function next_steps( array $platforms, array $findings, $ran ) {
        $steps = array();
        $used  = array();

        foreach ( $platforms as $platform ) {
            if ( self::ATTENTION !== $platform['state'] ) {
                continue;
            }

            if ( $platform['not_confirmed'] ) {
                $steps[] = array(
                    'id'         => 'not-confirmed-' . $platform['id'],
                    /* translators: %s: platform name, for example Meta */
                    'text'       => sprintf( __( 'See which purchases %s didn\'t confirm.', 'woocommerce-conversion-tracking' ), $platform['name'] ),
                    'link'       => '#/log?tab=not-confirmed',
                    'link_label' => __( 'Open the Activity log', 'woocommerce-conversion-tracking' ),
                );
            }

            if ( $platform['needs_resetup'] ) {
                $steps[] = array(
                    'id'         => 'resetup-' . $platform['id'],
                    /* translators: %s: platform name, for example X */
                    'text'       => sprintf( __( 'Give %s a new purchase event ID; the old codes were retired.', 'woocommerce-conversion-tracking' ), $platform['name'] ),
                    'link'       => '#/platforms/' . $platform['id'],
                    /* translators: %s: platform name, for example X */
                    'link_label' => sprintf( __( 'Open %s', 'woocommerce-conversion-tracking' ), $platform['name'] ),
                );
            }

            if ( $platform['finding'] ) {
                $used[]  = $platform['finding']['hash'];
                $steps[] = self::finding_step(
                    $platform['finding'],
                    /* translators: %s: platform name, for example Google */
                    sprintf( __( 'Open %s', 'woocommerce-conversion-tracking' ), $platform['name'] )
                );
            }
        }

        // One cookie choices step for every not confirmed platform.
        if ( array_filter( array_map(
            function ( $platform ) {
                return self::ATTENTION === $platform['state'] && ! empty( $platform['not_confirmed'] );
            },
            $platforms
        ) ) ) {
            $steps[] = array(
                'id'         => 'cookie-choices',
                'text'       => __( 'Check cookie choices and your cookie plugin, which decide when a platform\'s script may load.', 'woocommerce-conversion-tracking' ),
                'link'       => self::COOKIE_CHOICES,
                'link_label' => __( 'Check cookie choices', 'woocommerce-conversion-tracking' ),
            );
        }

        $listed = 0;

        foreach ( $findings as $finding ) {
            if ( in_array( $finding['hash'], $used, true ) || $listed >= self::MAX_DOCTOR_STEPS ) {
                continue;
            }

            ++$listed;
            $steps[] = self::finding_step( $finding, __( "See the Doctor's note", 'woocommerce-conversion-tracking' ) );
        }

        if ( ! $ran ) {
            $steps[] = array(
                'id'         => 'doctor',
                'text'       => __( 'Let the Doctor check for duplicate tracking.', 'woocommerce-conversion-tracking' ),
                'link'       => '#/doctor',
                'link_label' => __( 'Check my store', 'woocommerce-conversion-tracking' ),
            );
        }

        return $steps;
    }

    /**
     * A next step from a Doctor finding; a link that leaves the app goes through the Doctor page, where its confirmation lives.
     *
     * @param array  $finding Finding.
     * @param string $label   Link label for an in-app link.
     *
     * @return array
     */
    private static function finding_step( array $finding, $label ) {
        $url    = isset( $finding['action']['url'] ) && is_string( $finding['action']['url'] ) ? $finding['action']['url'] : '';
        $in_app = 0 === strpos( $url, '#/' ) && ( ! isset( $finding['action']['type'] ) || 'link' === $finding['action']['type'] ) && empty( $finding['action']['confirm'] );

        return array(
            'id'         => 'doctor-' . $finding['hash'],
            'text'       => isset( $finding['found'] ) ? (string) $finding['found'] : '',
            'link'       => $in_app ? $url : '#/doctor',
            'link_label' => $in_app ? $label : __( "See the Doctor's note", 'woocommerce-conversion-tracking' ),
        );
    }

    /**
     * The line under a platform card's name.
     *
     * @param array $card         Platform card.
     * @param int   $connected_at Unix time it was connected, 0 for unknown.
     * @param int   $now          Unix time.
     *
     * @return array|null text, link, link_label.
     */
    private static function note( array $card, $connected_at, $now ) {
        switch ( $card['state'] ) {
            case self::PAUSED:
                return array(
                    'text'       => __( 'Tracking is paused. Nothing is sent until you resume it.', 'woocommerce-conversion-tracking' ),
                    'link'       => '',
                    'link_label' => '',
                );
            case self::ATTENTION:
                if ( $card['not_confirmed'] ) {
                    return array(
                        /* translators: %s: number of purchases */
                        'text'       => sprintf( _n( "We couldn't confirm %s purchase in the last 24 hours.", "We couldn't confirm %s purchases in the last 24 hours.", $card['not_confirmed'], 'woocommerce-conversion-tracking' ), number_format_i18n( $card['not_confirmed'] ) ),
                        'link'       => '#/log?tab=not-confirmed',
                        'link_label' => __( 'See the orders', 'woocommerce-conversion-tracking' ),
                    );
                }

                if ( $card['needs_resetup'] ) {
                    return array(
                        /* translators: %s: platform name, for example X */
                        'text'       => sprintf( __( '%s retired the old event codes, so purchases need a new event ID.', 'woocommerce-conversion-tracking' ), $card['name'] ),
                        'link'       => '#/platforms/' . $card['id'],
                        'link_label' => __( 'Fix it', 'woocommerce-conversion-tracking' ),
                    );
                }

                return array(
                    'text'       => isset( $card['finding']['found'] ) ? (string) $card['finding']['found'] : '',
                    'link'       => self::finding_step( $card['finding'], '' )['link'],
                    'link_label' => __( 'Fix it', 'woocommerce-conversion-tracking' ),
                );
            case self::WORKING:
                if ( ! $card['last_purchase'] ) {
                    return null;
                }

                return array(
                    /* translators: 1: time since the purchase, for example 2 mins; 2: order number */
                    'text'       => sprintf( __( 'Last purchase %1$s ago, #%2$s', 'woocommerce-conversion-tracking' ), human_time_diff( $card['last_purchase']['time'], $now ), $card['last_purchase']['order_number'] ),
                    'link'       => '',
                    'link_label' => '',
                );
            case self::WAITING:
                $wait = __( 'This updates when the first purchase checks in. Place a test order to see it now.', 'woocommerce-conversion-tracking' );

                return array(
                    'text'       => $connected_at
                        /* translators: 1: time since the platform was connected, for example 5 mins; 2: the rest of the note */
                        ? sprintf( __( 'Connected %1$s ago. %2$s', 'woocommerce-conversion-tracking' ), human_time_diff( $connected_at, $now ), $wait )
                        : $wait,
                    'link'       => '',
                    'link_label' => '',
                );
        }

        return null;
    }

    /**
     * The latest purchase handed to any of a platform's integrations; survives the 24-hour prune (LastPurchase option).
     *
     * @param string[] $ids Platform and integration IDs.
     * @param int      $now Unix time.
     *
     * @return array|null time, at (ISO 8601), ago, order_id, order_number.
     */
    private static function last_purchase( array $ids, $now ) {
        $latest = null;

        foreach ( array_unique( array_map( 'strval', $ids ) ) as $id ) {
            $last = LastPurchase::get( $id );

            if ( $last && ( ! $latest || $last['last_purchase_at'] > $latest['last_purchase_at'] ) ) {
                $latest = $last;
            }
        }

        if ( ! $latest ) {
            return null;
        }

        return array(
            'time'         => $latest['last_purchase_at'],
            'at'           => gmdate( 'c', $latest['last_purchase_at'] ),
            /* translators: %s: time since the purchase, for example 2 mins */
            'ago'          => sprintf( __( '%s ago', 'woocommerce-conversion-tracking' ), human_time_diff( $latest['last_purchase_at'], $now ) ),
            'order_id'     => $latest['order_id'],
            'order_number' => '' !== $latest['order_number'] ? $latest['order_number'] : (string) $latest['order_id'],
        );
    }

    /**
     * The hourly series for the response, in the site's time zone.
     *
     * @param array[] $orders Evaluated orders.
     * @param int     $now    Unix time.
     *
     * @return array[]
     */
    private static function hourly_output( array $orders, $now ) {
        $offset = function_exists( 'wp_timezone' ) ? wp_timezone()->getOffset( new \DateTimeImmutable( '@' . $now ) ) : 0;
        $hours  = array();

        foreach ( self::hourly( $orders, $now, $offset ) as $hour ) {
            $hours[] = array(
                'start'         => gmdate( 'c', $hour['start'] ),
                'label'         => self::hour_label( $hour['start'] ),
                'sent'          => $hour['sent'],
                'not_confirmed' => $hour['not_confirmed'],
            );
        }

        return $hours;
    }

    /**
     * A platform card for the response.
     *
     * @param array $card Platform card.
     *
     * @return array
     */
    private static function platform_output( array $card ) {
        $last = $card['last_purchase'];

        if ( $last ) {
            unset( $last['time'] );
        }

        return array(
            'id'            => $card['id'],
            'name'          => $card['name'],
            'state'         => $card['state'],
            'connected'     => $card['connected'],
            'paused'        => $card['paused'],
            'confirmed'     => $card['confirmed'],
            'not_confirmed' => $card['not_confirmed'],
            'last_purchase' => $last,
            'connected_at'  => $card['connected_at'],
            'note'          => $card['note'],
        );
    }
}
