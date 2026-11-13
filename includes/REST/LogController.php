<?php

namespace StorePulse\ConversionTracking\REST;

use StorePulse\ConversionTracking\Log\Activity;
use StorePulse\ConversionTracking\Log\OrderLog;
use StorePulse\ConversionTracking\Log\Statuses;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The Activity log route: the last 24 hours of orders with their per-platform statuses, by tab and page; order numbers and amounts only.
 */
class LogController extends Controller {

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'log';

    /**
     * Register the route.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_items' ),
                    'permission_callback' => array( $this, 'permissions_check' ),
                    'args'                => array(
                        'tab'      => array(
                            'description' => __( 'All, or only the orders under one tab.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'string',
                            'enum'        => array( Statuses::TAB_ALL, Statuses::TAB_TRACKED, Statuses::TAB_NOT_CONFIRMED, Statuses::TAB_PENDING ),
                            'default'     => Statuses::TAB_ALL,
                        ),
                        'page'     => array(
                            'description' => __( 'Page of the list.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'default'     => 1,
                        ),
                        'per_page' => array(
                            'description' => __( 'Orders per page.', 'woocommerce-conversion-tracking' ),
                            'type'        => 'integer',
                            'minimum'     => 1,
                            'maximum'     => 100,
                            'default'     => 20,
                        ),
                    ),
                ),
                'schema' => array( $this, 'get_public_item_schema' ),
            )
        );
    }

    /**
     * One page of orders under a tab, with the count per tab.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return WP_REST_Response
     */
    public function get_items( $request ) {
        $now = time();

        // Fresh on every request, never the dashboard's cached list.
        Activity::reset();

        $orders   = Activity::orders( $now );
        $tab      = (string) $request->get_param( 'tab' );
        $per_page = (int) $request->get_param( 'per_page' );
        $page     = (int) $request->get_param( 'page' );
        $listed   = array_values(
            array_filter(
                $orders,
                function ( $order ) use ( $tab ) {
                    return Statuses::in_tab( $order, $tab );
                }
            )
        );
        $total    = count( $listed );
        $pages    = (int) ceil( $total / $per_page );
        $names    = Activity::platform_names();
        $items    = array();

        foreach ( array_slice( $listed, ( $page - 1 ) * $per_page, $per_page ) as $order ) {
            $items[] = $this->prepare_order( $order, $names, $now );
        }

        $response = rest_ensure_response(
            array(
                'orders'   => $items,
                'counts'   => Statuses::counts( $orders ),
                'total'    => $total,
                'pages'    => $pages,
                'page'     => $page,
                'per_page' => $per_page,
                'since'    => gmdate( 'c', $now - OrderLog::RETENTION ),
                'settle'   => Statuses::SETTLE,
            )
        );

        $response->header( 'X-WP-Total', (string) $total );
        $response->header( 'X-WP-TotalPages', (string) $pages );

        return $response;
    }

    /**
     * An evaluated order for the response.
     *
     * @param array    $order Result of Statuses::evaluate().
     * @param string[] $names Platform ID => name.
     * @param int      $now   Unix time.
     *
     * @return array
     */
    private function prepare_order( array $order, array $names, $now ) {
        $platforms = array();

        foreach ( $order['platforms'] as $platform ) {
            $platforms[] = array(
                'id'            => $platform['id'],
                'name'          => isset( $names[ $platform['id'] ] ) ? $names[ $platform['id'] ] : $platform['id'],
                'integrations'  => $platform['integrations'],
                'status'        => $platform['status'],
                'cause'         => $platform['cause'],
                'sent'          => (bool) $platform['sent'],
                'checked_in_at' => self::iso( $platform['checked_in_at'] ),
                'sent_at'       => self::iso( $platform['sent_at'] ),
                'sent_at_label' => self::clock( $platform['sent_at'] ),
                'consent'       => $platform['consent'],
                'event_id'      => $platform['event_id'],
                'unknown'       => ! empty( $platform['unknown'] ),
            );
        }

        return array(
            'id'                 => (int) $order['id'],
            'number'             => (string) $order['number'],
            'amount'             => self::amount( $order['total'], $order['currency'] ),
            'total'              => (string) $order['total'],
            'currency'           => (string) $order['currency'],
            'order_status'       => (string) $order['order_status'],
            'order_status_label' => function_exists( 'wc_get_order_status_name' ) ? (string) wc_get_order_status_name( $order['order_status'] ) : (string) $order['order_status'],
            'status'             => $order['status'],
            'cause'              => $order['cause'],
            'tab'                => Statuses::tab( $order['status'] ),
            'created_at'         => self::iso( $order['created'] ),
            'created_label'      => self::when( $order['created'], $now ),
            'created_clock'      => self::clock( $order['created'] ),
            'paid_at'            => self::iso( $order['paid'] ),
            'thankyou_at'        => self::iso( $order['thankyou'] ),
            'thankyou_clock'     => self::clock( $order['thankyou'] ),
            'settles_at'         => self::iso( $order['settles_at'] ),
            'platforms'          => $platforms,
        );
    }

    /**
     * "Today 10:42", "Yesterday 18:07", or the date and time, in the site's time zone.
     *
     * @param int $time Unix time.
     * @param int $now  Unix time.
     *
     * @return string
     */
    private static function when( $time, $now ) {
        if ( ! $time ) {
            return '';
        }

        $day = wp_date( 'Y-m-d', $time );

        if ( wp_date( 'Y-m-d', $now ) === $day ) {
            /* translators: %s: time, for example 10:42 */
            return sprintf( __( 'Today %s', 'woocommerce-conversion-tracking' ), self::clock( $time ) );
        }

        if ( wp_date( 'Y-m-d', $now - DAY_IN_SECONDS ) === $day ) {
            /* translators: %s: time, for example 18:07 */
            return sprintf( __( 'Yesterday %s', 'woocommerce-conversion-tracking' ), self::clock( $time ) );
        }

        return (string) wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . get_option( 'time_format', 'H:i' ), $time );
    }

    /**
     * A time of day in the site's format and time zone.
     *
     * @param int $time Unix time, 0 for none.
     *
     * @return string
     */
    private static function clock( $time ) {
        return $time ? (string) wp_date( (string) get_option( 'time_format', 'H:i' ), $time ) : '';
    }

    /**
     * A Unix time as ISO 8601 UTC, null for none.
     *
     * @param int $time Unix time.
     *
     * @return string|null
     */
    private static function iso( $time ) {
        return $time ? gmdate( 'c', (int) $time ) : null;
    }

    /**
     * The order total in its currency as plain text, for example $200.00.
     *
     * @param string $total    Total.
     * @param string $currency Currency code.
     *
     * @return string
     */
    private static function amount( $total, $currency ) {
        if ( ! function_exists( 'wc_price' ) ) {
            return trim( $total . ' ' . $currency );
        }

        return html_entity_decode( wp_strip_all_tags( wc_price( $total, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
    }

    /**
     * JSON schema of the response.
     *
     * @return array
     */
    public function get_item_schema() {
        if ( $this->schema ) {
            return $this->add_additional_fields_schema( $this->schema );
        }

        $text     = array( 'type' => 'string' );
        $time     = array(
            'description' => __( 'ISO 8601, UTC; null when it didn\'t happen.', 'woocommerce-conversion-tracking' ),
            'type'        => array( 'string', 'null' ),
        );
        $statuses = array(
            'type' => 'string',
            'enum' => array( Statuses::CONFIRMED, Statuses::NOT_CONFIRMED, Statuses::NO_CHECKIN, Statuses::PENDING, Statuses::WAITING, Statuses::SKIPPED, Statuses::PAUSED ),
        );

        $this->schema = array(
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'wcct_log',
            'type'       => 'object',
            'properties' => array(
                'orders'   => array(
                    'type'  => 'array',
                    'items' => array(
                        'type'       => 'object',
                        'properties' => array(
                            'id'                 => array( 'type' => 'integer' ),
                            'number'             => $text,
                            'amount'             => $text,
                            'total'              => $text,
                            'currency'           => $text,
                            'order_status'       => $text,
                            'order_status_label' => $text,
                            'status'             => $statuses,
                            'cause'              => $text,
                            'tab'                => $text,
                            'created_at'         => $time,
                            'created_label'      => $text,
                            'created_clock'      => $text,
                            'paid_at'            => $time,
                            'thankyou_at'        => $time,
                            'thankyou_clock'     => $text,
                            'settles_at'         => $time,
                            'platforms'          => array(
                                'type'  => 'array',
                                'items' => array(
                                    'type'       => 'object',
                                    'properties' => array(
                                        'id'            => $text,
                                        'name'          => $text,
                                        'integrations'  => array(
                                            'type'  => 'array',
                                            'items' => $text,
                                        ),
                                        'status'        => $statuses,
                                        'cause'         => $text,
                                        'sent'          => array( 'type' => 'boolean' ),
                                        'checked_in_at' => $time,
                                        'sent_at'       => $time,
                                        'sent_at_label' => $text,
                                        'consent'       => $text,
                                        'event_id'      => $text,
                                        'unknown'       => array(
                                            'description' => __( 'No check-in from this platform while others checked in: listed, never judged.', 'woocommerce-conversion-tracking' ),
                                            'type'        => 'boolean',
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'counts'   => array(
                    'description' => __( 'Orders per tab.', 'woocommerce-conversion-tracking' ),
                    'type'        => 'object',
                ),
                'total'    => array( 'type' => 'integer' ),
                'pages'    => array( 'type' => 'integer' ),
                'page'     => array( 'type' => 'integer' ),
                'per_page' => array( 'type' => 'integer' ),
                'since'    => $text,
                'settle'   => array(
                    'description' => __( 'Seconds before a result is final.', 'woocommerce-conversion-tracking' ),
                    'type'        => 'integer',
                ),
            ),
        );

        return $this->add_additional_fields_schema( $this->schema );
    }
}
