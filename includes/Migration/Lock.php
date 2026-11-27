<?php

namespace StorePulse\ConversionTracking\Migration;

/**
 * Option-row lock with a timeout, so only one request runs a migration.
 */
class Lock {

    /**
     * Default seconds before a held lock counts as abandoned.
     */
    const DEFAULT_TIMEOUT = 600;

    /**
     * @var OptionStore
     */
    private $store;

    /**
     * @var string
     */
    private $name;

    /**
     * @var int
     */
    private $timeout;

    /**
     * @var callable
     */
    private $clock;

    /**
     * Value written by this instance while it holds the lock.
     *
     * @var string|null
     */
    private $token = null;

    /**
     * Constructor.
     *
     * @param OptionStore   $store   Option store.
     * @param string        $name    Lock option name.
     * @param int           $timeout Seconds before the lock can be taken over.
     * @param callable|null $clock   Returns the current Unix time.
     */
    public function __construct( OptionStore $store, string $name, int $timeout = self::DEFAULT_TIMEOUT, ?callable $clock = null ) {
        $this->store   = $store;
        $this->name    = $name;
        $this->timeout = $timeout;
        $this->clock   = $clock ? $clock : 'time';
    }

    /**
     * Take the lock; false when another caller holds a live one.
     *
     * @return bool
     */
    public function acquire(): bool {
        $now   = (int) call_user_func( $this->clock );
        $token = $now . '|' . bin2hex( random_bytes( 8 ) );
        $held  = $this->store->read( $this->name );

        if ( null === $held ) {
            $acquired = $this->store->insert( $this->name, $token );
        } elseif ( $now - (int) $held >= $this->timeout ) {
            // Abandoned lock: only the caller whose swap lands takes it over.
            $acquired = $this->store->replace( $this->name, $held, $token );
        } else {
            $acquired = false;
        }

        $this->token = $acquired ? $token : null;

        return $acquired;
    }

    /**
     * Release the lock if this instance still holds it.
     *
     * @return void
     */
    public function release() {
        if ( null === $this->token ) {
            return;
        }

        $this->store->delete( $this->name, $this->token );
        $this->token = null;
    }
}
