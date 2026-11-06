<?php

namespace StorePulse\ConversionTracking\Events;

/**
 * Per-visitor events waiting for the browser, kept in the WooCommerce session, never in page HTML.
 */
class PendingEvents {

    /**
     * Session key of the queue.
     */
    const SESSION_KEY = 'wcct_pending_events';

    /**
     * Seconds an entry stays deliverable.
     */
    const TTL = 900;

    /**
     * Most entries kept.
     */
    const MAX = 20;

    /**
     * Session-like store with get( $key, $default ) and set( $key, $value ), e.g. WC_Session.
     *
     * @var object|null
     */
    private $session;

    /**
     * Current Unix time; null for time().
     *
     * @var int|null
     */
    private $now;

    /**
     * Queue on a session.
     *
     * @param object|null $session Session with get() and set().
     * @param int|null    $now     Current time, for tests.
     */
    public function __construct( $session, $now = null ) {
        $this->session = is_object( $session ) && method_exists( $session, 'get' ) && method_exists( $session, 'set' ) ? $session : null;
        $this->now     = null === $now ? null : (int) $now;
    }

    /**
     * Queue an event's code per platform; empty code is dropped.
     *
     * @param string $id      Event ID.
     * @param array  $scripts JavaScript per platform ID.
     *
     * @return bool Whether the entry was queued.
     */
    public function add( $id, array $scripts ) {
        $scripts = array_filter(
            $scripts,
            function ( $code ) {
                return is_string( $code ) && '' !== trim( $code );
            }
        );

        if ( ! $this->session || ! is_string( $id ) || '' === $id || ! $scripts ) {
            return false;
        }

        $entries   = $this->all();
        $entries[] = array(
            'id'      => $id,
            'time'    => $this->time(),
            'scripts' => $scripts,
        );

        $this->session->set( self::SESSION_KEY, array_slice( $entries, -self::MAX ) );

        return true;
    }

    /**
     * Entries still deliverable, oldest first.
     *
     * @return array List of {id, time, scripts}.
     */
    public function all() {
        $entries = $this->session ? $this->session->get( self::SESSION_KEY, array() ) : array();
        $oldest  = $this->time() - self::TTL;

        return array_values(
            array_filter(
                is_array( $entries ) ? $entries : array(),
                function ( $entry ) use ( $oldest ) {
                    return is_array( $entry ) && isset( $entry['id'], $entry['time'], $entry['scripts'] ) && is_string( $entry['id'] ) && is_array( $entry['scripts'] ) && (int) $entry['time'] >= $oldest;
                }
            )
        );
    }

    /**
     * Entries queued after the one the browser ran last; all of them when that one is unknown.
     *
     * @param string $seen_id ID of the last entry the browser ran.
     *
     * @return array List of {id, scripts}.
     */
    public function after( $seen_id ) {
        $entries = $this->all();
        $ids     = array_column( $entries, 'id' );
        $index   = is_string( $seen_id ) && '' !== $seen_id ? array_search( $seen_id, $ids, true ) : false;

        if ( false !== $index ) {
            $entries = array_slice( $entries, $index + 1 );
        }

        return array_map(
            function ( $entry ) {
                return array(
                    'id'      => $entry['id'],
                    'scripts' => $entry['scripts'],
                );
            },
            $entries
        );
    }

    /**
     * Current time.
     *
     * @return int
     */
    private function time() {
        return null === $this->now ? time() : $this->now;
    }
}
