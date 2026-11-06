<?php

namespace StorePulse\ConversionTracking\Doctor;

/**
 * Runs the checks, keeps the last results, dismissals and the "found something new" badge, and builds the report.
 */
class Doctor {

    /**
     * Last result of every check: { time (last full run, the merchant's "Last check"), rechecked_at, results: { id: result } }.
     */
    const RESULTS_OPTION = 'wcct_doctor_results';

    /**
     * Dismissed findings: { hash: time }.
     */
    const DISMISSED_OPTION = 'wcct_doctor_dismissed';

    /**
     * Hashes the quiet re-check found that the merchant hasn't seen yet.
     */
    const NEW_OPTION = 'wcct_doctor_new';

    /**
     * Run every check.
     */
    const SCOPE_ALL = 'all';

    /**
     * Dismissals kept; the oldest go first.
     */
    const MAX_DISMISSED = 200;

    /**
     * Findings kept per check.
     */
    const MAX_FINDINGS = 20;

    /**
     * Run checks and store their results next to the others.
     *
     * @param string[]     $only    Check IDs to run; empty for all.
     * @param string       $scope   all, local or remote.
     * @param Context|null $context Run context; a new one by default.
     *
     * @return array The report.
     */
    public static function run( array $only = array(), $scope = self::SCOPE_ALL, $context = null ) {
        $checks   = self::select( Registry::applicable(), $only, $scope );
        $full     = ! $only && self::SCOPE_ALL === $scope;
        $previous = self::stored()['results'];
        $results  = self::execute( $checks, $context instanceof Context ? $context : new Context() );

        self::store( $results, $full );

        // A full run is the merchant looking at everything.
        if ( $full ) {
            self::seen();
            self::prune_dismissed( $results, $previous );
        }

        return self::report();
    }

    /**
     * The quiet re-check after a plugin is activated: local checks only; new problems light the dashboard badge.
     * Nothing runs before the merchant's first run, and the "Last check" time stays as it is.
     *
     * @return string[] Hashes of the new findings.
     */
    public static function recheck() {
        $stored = self::stored();

        if ( ! $stored['time'] ) {
            return array();
        }

        $known = array_merge( self::hashes( $stored['results'], false ), array_keys( self::dismissed() ) );
        $run   = self::execute( self::select( Registry::applicable(), array(), Check::LOCAL ), new Context() );

        self::store( $run, false );

        $new = array_values( array_diff( self::hashes( $run, true ), $known ) );

        if ( $new ) {
            update_option( self::NEW_OPTION, array_values( array_unique( array_merge( self::new_hashes(), $new ) ) ), false );
        }

        return $new;
    }

    /**
     * Run checks and collect their results; local checks first, remote ones while the budget lasts.
     *
     * @param Check[] $checks  Checks keyed by ID.
     * @param Context $context Run context.
     *
     * @return array Results keyed by check ID.
     */
    public static function execute( array $checks, Context $context ) {
        $results = array();

        uasort(
            $checks,
            static function ( Check $a, Check $b ) {
                return ( Check::REMOTE === self::scope_of( $a ) ) - ( Check::REMOTE === self::scope_of( $b ) );
            }
        );

        foreach ( $checks as $id => $check ) {
            $scope  = self::scope_of( $check );
            $result = array(
                'id'       => $id,
                'label'    => '',
                'scope'    => $scope,
                'status'   => 'passed',
                'reason'   => '',
                'findings' => array(),
                'time'     => time(),
            );

            if ( Check::REMOTE === $scope && $context->remaining() <= 0 ) {
                $result['status'] = 'skipped';
                $result['reason'] = 'timeout';
            } else {
                try {
                    $found = $check->run( $context );

                    foreach ( is_array( $found ) ? $found : array() as $finding ) {
                        if ( $finding instanceof Finding && count( $result['findings'] ) < self::MAX_FINDINGS ) {
                            $result['findings'][] = array_merge( $finding->to_array(), array( 'check' => $id ) );
                        }
                    }

                    $result['status'] = $result['findings'] ? 'found' : 'passed';
                } catch ( \Throwable $e ) {
                    $result['status']   = 'skipped';
                    $result['reason']   = 'error';
                    $result['findings'] = array();
                }
            }

            $result['label'] = self::label_of( $check, $id );
            $results[ $id ]  = $result;
        }

        return $results;
    }

    /**
     * The report the doctor route returns.
     *
     * @return array
     */
    public static function report() {
        $checks    = Registry::applicable();
        $stored    = self::stored();
        $dismissed = self::dismissed();
        $new       = self::new_hashes();
        $report    = array(
            'ran_at'       => null,
            'ran_at_label' => '',
            'ran_ago'      => '',
            'total'        => count( $checks ),
            'checked'      => 0,
            'findings'     => array(),
            'working'      => array(),
            'skipped'      => array(),
            'new'          => 0,
        );

        foreach ( array_keys( $checks ) as $id ) {
            if ( ! isset( $stored['results'][ $id ] ) ) {
                continue;
            }

            $result = $stored['results'][ $id ];

            if ( 'skipped' === $result['status'] ) {
                $report['skipped'][] = array(
                    'check'  => $id,
                    'label'  => '' !== $result['label'] ? $result['label'] : $id,
                    'reason' => self::reason( $result['reason'] ),
                );
                continue;
            }

            ++$report['checked'];

            if ( 'passed' === $result['status'] ) {
                $report['working'][] = array(
                    'check' => $id,
                    'label' => '' !== $result['label'] ? $result['label'] : $id,
                );
                continue;
            }

            foreach ( $result['findings'] as $finding ) {
                $finding['dismissed'] = isset( $dismissed[ $finding['hash'] ] );
                $finding['new']       = ! $finding['dismissed'] && Finding::WARNING === $finding['severity'] && in_array( $finding['hash'], $new, true );
                $report['new']       += $finding['new'] ? 1 : 0;
                $report['findings'][] = $finding;
            }
        }

        // Problems we are sure about first, then the unsure ones, then the good-to-know notes.
        $rank = static function ( array $finding ) {
            if ( Finding::INFO === $finding['severity'] ) {
                return 2;
            }

            return Finding::UNSURE === $finding['confidence'] ? 1 : 0;
        };
        $ranks = array();

        // Stable: within a rank, findings keep the registry order.
        foreach ( $report['findings'] as $index => $finding ) {
            $ranks[] = array( $rank( $finding ), $index );
        }

        array_multisort( $ranks, $report['findings'] );

        if ( $stored['time'] ) {
            $report = array_merge( $report, self::when( $stored['time'] ) );
        }

        return $report;
    }

    /**
     * Dismiss a finding, or bring it back (Undo).
     *
     * @param string $hash      Finding hash.
     * @param bool   $dismissed Dismiss or undo.
     *
     * @return void
     */
    public static function dismiss( $hash, $dismissed = true ) {
        $list = self::dismissed();

        unset( $list[ $hash ] );

        if ( $dismissed ) {
            $list[ $hash ] = time();
            $list          = array_slice( $list, -self::MAX_DISMISSED, null, true );
        }

        update_option( self::DISMISSED_OPTION, $list, false );
        update_option( self::NEW_OPTION, array_values( array_diff( self::new_hashes(), array( $hash ) ) ), false );
    }

    /**
     * Forget dismissals of findings a full run no longer found, so a finding that went away and comes back shows again.
     * Findings of checks that were skipped this time keep their dismissals.
     *
     * @param array $results  Results of the full run, keyed by check ID.
     * @param array $previous Stored results before it.
     *
     * @return void
     */
    private static function prune_dismissed( array $results, array $previous ) {
        $list = self::dismissed();

        if ( ! $list ) {
            return;
        }

        $present = self::hashes( $results, false );

        foreach ( $results as $id => $result ) {
            if ( 'skipped' === $result['status'] && isset( $previous[ $id ] ) ) {
                $present = array_merge( $present, self::hashes( array( $previous[ $id ] ), false ) );
            }
        }

        $kept = array_intersect_key( $list, array_flip( $present ) );

        if ( count( $kept ) !== count( $list ) ) {
            update_option( self::DISMISSED_OPTION, $kept, false );
        }
    }

    /**
     * The merchant has seen the results: clear the dashboard badge.
     *
     * @return void
     */
    public static function seen() {
        if ( self::new_hashes() ) {
            update_option( self::NEW_OPTION, array(), false );
        }
    }

    /**
     * Whether a string is a finding hash.
     *
     * @param mixed $hash Raw hash.
     *
     * @return bool
     */
    public static function is_hash( $hash ) {
        return is_string( $hash ) && (bool) preg_match( '/^[a-f0-9]{32}$/', $hash );
    }

    /**
     * Checks limited to some IDs and a scope.
     *
     * @param Check[]  $checks Checks keyed by ID.
     * @param string[] $only   IDs; empty for all.
     * @param string   $scope  all, local or remote.
     *
     * @return Check[]
     */
    private static function select( array $checks, array $only, $scope ) {
        if ( $only ) {
            $checks = array_intersect_key( $checks, array_flip( array_map( 'strval', $only ) ) );
        }

        if ( self::SCOPE_ALL === $scope ) {
            return $checks;
        }

        return array_filter(
            $checks,
            static function ( Check $check ) use ( $scope ) {
                return self::scope_of( $check ) === $scope;
            }
        );
    }

    /**
     * Store new results over the old ones; results of checks that are gone are dropped.
     *
     * @param array $results Results keyed by check ID.
     * @param bool  $full    A full run by the merchant: it moves the "Last check" time.
     *
     * @return void
     */
    private static function store( array $results, $full ) {
        $stored  = self::stored();
        $merged  = array();
        $current = array_keys( Registry::all() );

        foreach ( $current as $id ) {
            if ( isset( $results[ $id ] ) ) {
                $merged[ $id ] = $results[ $id ];
            } elseif ( isset( $stored['results'][ $id ] ) ) {
                $merged[ $id ] = $stored['results'][ $id ];
            }
        }

        update_option(
            self::RESULTS_OPTION,
            array(
                'time'         => $full ? time() : $stored['time'],
                'rechecked_at' => $full ? $stored['rechecked_at'] : time(),
                'results'      => $merged,
            ),
            false
        );
    }

    /**
     * The stored results, cleaned.
     *
     * @return array { time: int, results: array }
     */
    private static function stored() {
        $stored  = get_option( self::RESULTS_OPTION, array() );
        $stored  = is_array( $stored ) ? $stored : array();
        $results = array();

        foreach ( isset( $stored['results'] ) && is_array( $stored['results'] ) ? $stored['results'] : array() as $id => $result ) {
            if ( ! is_array( $result ) || ! isset( $result['status'] ) || ! in_array( $result['status'], array( 'passed', 'found', 'skipped' ), true ) ) {
                continue;
            }

            $findings = array_filter(
                isset( $result['findings'] ) && is_array( $result['findings'] ) ? $result['findings'] : array(),
                static function ( $finding ) {
                    return is_array( $finding ) && isset( $finding['hash'], $finding['severity'], $finding['confidence'] ) && self::is_hash( $finding['hash'] );
                }
            );

            $results[ (string) $id ] = array(
                'id'       => (string) $id,
                'label'    => isset( $result['label'] ) && is_string( $result['label'] ) ? $result['label'] : '',
                'scope'    => isset( $result['scope'] ) && Check::REMOTE === $result['scope'] ? Check::REMOTE : Check::LOCAL,
                'status'   => 'found' === $result['status'] && ! $findings ? 'passed' : $result['status'],
                'reason'   => isset( $result['reason'] ) && is_string( $result['reason'] ) ? $result['reason'] : '',
                'findings' => array_values( $findings ),
                'time'     => isset( $result['time'] ) ? absint( $result['time'] ) : 0,
            );
        }

        return array(
            'time'         => isset( $stored['time'] ) ? absint( $stored['time'] ) : 0,
            'rechecked_at' => isset( $stored['rechecked_at'] ) ? absint( $stored['rechecked_at'] ) : 0,
            'results'      => $results,
        );
    }

    /**
     * Dismissed hashes and when.
     *
     * @return array
     */
    private static function dismissed() {
        $list = get_option( self::DISMISSED_OPTION, array() );

        return is_array( $list ) ? array_filter( $list, array( self::class, 'is_hash' ), ARRAY_FILTER_USE_KEY ) : array();
    }

    /**
     * Hashes waiting for the merchant to look.
     *
     * @return string[]
     */
    private static function new_hashes() {
        $list = get_option( self::NEW_OPTION, array() );

        return is_array( $list ) ? array_values( array_filter( $list, array( self::class, 'is_hash' ) ) ) : array();
    }

    /**
     * Finding hashes of some results.
     *
     * @param array $results       Results keyed by check ID.
     * @param bool  $warnings_only Only the ones worth a look.
     *
     * @return string[]
     */
    private static function hashes( array $results, $warnings_only ) {
        $hashes = array();

        foreach ( $results as $result ) {
            foreach ( $result['findings'] as $finding ) {
                if ( ! $warnings_only || Finding::WARNING === $finding['severity'] ) {
                    $hashes[] = $finding['hash'];
                }
            }
        }

        return $hashes;
    }

    /**
     * A check's scope; anything unknown is local.
     *
     * @param Check $check Check.
     *
     * @return string
     */
    private static function scope_of( Check $check ) {
        try {
            return Check::REMOTE === $check->scope() ? Check::REMOTE : Check::LOCAL;
        } catch ( \Throwable $e ) {
            return Check::LOCAL;
        }
    }

    /**
     * A check's Working line; its ID when it has none.
     *
     * @param Check  $check Check.
     * @param string $id    Its registry ID.
     *
     * @return string
     */
    private static function label_of( Check $check, $id ) {
        try {
            $label = $check->label();
        } catch ( \Throwable $e ) {
            $label = '';
        }

        return is_string( $label ) && '' !== trim( $label ) ? wp_strip_all_tags( $label ) : $id;
    }

    /**
     * Why a check was skipped, for the page.
     *
     * @param string $reason timeout or error.
     *
     * @return string
     */
    private static function reason( $reason ) {
        if ( 'timeout' === $reason ) {
            return __( 'Ran out of time. Check again in a moment.', 'woocommerce-conversion-tracking' );
        }

        return __( 'This check could not run.', 'woocommerce-conversion-tracking' );
    }

    /**
     * When the last run was, for the page and the dashboard.
     *
     * @param int $time Unix time.
     *
     * @return array
     */
    private static function when( $time ) {
        $time_format = (string) get_option( 'time_format', 'H:i' );
        $label       = wp_date( 'Y-m-d', $time ) === wp_date( 'Y-m-d' )
            /* translators: %s: time of the last check, for example 10:50 */
            ? sprintf( __( 'today %s', 'woocommerce-conversion-tracking' ), wp_date( $time_format, $time ) )
            : wp_date( get_option( 'date_format', 'Y-m-d' ) . ' ' . $time_format, $time );

        return array(
            'ran_at'       => gmdate( 'c', $time ),
            'ran_at_label' => (string) $label,
            'ran_ago'      => time() - $time < 60
                ? __( 'Just now', 'woocommerce-conversion-tracking' )
                /* translators: %s: time since the last check, for example 2 days */
                : sprintf( __( '%s ago', 'woocommerce-conversion-tracking' ), human_time_diff( $time ) ),
        );
    }
}
