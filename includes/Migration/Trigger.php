<?php

namespace StorePulse\ConversionTracking\Migration;

/**
 * Runs a migration body once, when the stored state says it is due.
 */
class Trigger {

    const IDLE   = 'idle';
    const LOCKED = 'locked';
    const RAN    = 'ran';
    const FAILED = 'failed';

    /**
     * @var OptionStore
     */
    private $store;

    /**
     * @var Lock
     */
    private $lock;

    /**
     * @var string
     */
    private $marker;

    /**
     * @var callable
     */
    private $pending;

    /**
     * @var callable
     */
    private $body;

    /**
     * @var string
     */
    private $version;

    /**
     * @var \Throwable|null
     */
    private $error = null;

    /**
     * Constructor.
     *
     * @param OptionStore $store   Option store.
     * @param Lock        $lock    Lock that serialises runs.
     * @param string      $marker  Option written once the body has run.
     * @param callable    $pending Receives ( OptionStore $store, bool $fresh ), returns whether old data needs migrating.
     * @param callable    $body    The migration body.
     * @param string      $version Plugin version stored in the marker.
     */
    public function __construct( OptionStore $store, Lock $lock, string $marker, callable $pending, callable $body, string $version ) {
        $this->store   = $store;
        $this->lock    = $lock;
        $this->marker  = $marker;
        $this->pending = $pending;
        $this->body    = $body;
        $this->version = $version;
    }

    /**
     * Whether the migration still has to run.
     *
     * @param bool $fresh Read the database instead of the cache.
     *
     * @return bool
     */
    public function is_due( bool $fresh = false ): bool {
        if ( $this->store->exists( $this->marker, $fresh ) ) {
            return false;
        }

        return (bool) call_user_func( $this->pending, $this->store, $fresh );
    }

    /**
     * Run the body if it is due and this caller gets the lock.
     *
     * @return string One of the status constants.
     */
    public function maybe_run(): string {
        if ( ! $this->is_due() ) {
            return self::IDLE;
        }

        if ( ! $this->lock->acquire() ) {
            return self::LOCKED;
        }

        try {
            // Another request may have finished between the cached check and the lock.
            if ( ! $this->is_due( true ) ) {
                $this->lock->release();

                return self::IDLE;
            }

            call_user_func( $this->body );
        } catch ( \Throwable $e ) {
            // Keep the lock: the next attempt waits for its timeout instead of retrying on every request.
            $this->error = $e;

            return self::FAILED;
        }

        $this->store->save( $this->marker, $this->version );
        $this->lock->release();

        return self::RAN;
    }

    /**
     * The error thrown by the last failed run.
     *
     * @return \Throwable|null
     */
    public function error(): ?\Throwable {
        return $this->error;
    }
}
