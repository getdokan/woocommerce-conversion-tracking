<?php
/**
 * Queues an add to cart, then pauses Meta and Google and returns what still reaches the browser. Runs in a test store process (see PauseTest).
 */

// phpcs:disable

use StorePulse\ConversionTracking\Events\Pipeline;
use StorePulse\ConversionTracking\Platforms\Pause;

WC()->cart->empty_cart();
WC()->cart->add_to_cart( 2001, 1 );

$queued = wcct_init()->event_dispatcher->get_pipeline()->pending()->after( '' );

// Each later request builds its pipeline over the platforms active then.
$wcct_later = function () {
    return new Pipeline(
        function () {
            return wcct_init()->manager->get_active_integrations();
        }
    );
};

$wcct_platforms = function ( Pipeline $pipeline ) {
    $events = $pipeline->unseen_events();

    return array( count( $events ), $events ? array_keys( $events[0]['scripts'] ) : null, wp_json_encode( $events ) );
};

Pause::set( 'facebook', true );
list( , $after_meta, $fragment ) = $wcct_platforms( $wcct_later() );

Pause::set( 'google', true );
list( $entries, $after_google ) = $wcct_platforms( $wcct_later() );

Pause::set( 'facebook', false );
Pause::set( 'google', false );
list( , $after_resume ) = $wcct_platforms( $wcct_later() );

return array(
    'queued'              => array_keys( $queued[0]['scripts'] ),
    'after_meta_paused'   => $after_meta,
    'after_google_paused' => $after_google,
    'entries'             => $entries,
    'fragment'            => $fragment,
    'after_resume'        => $after_resume,
);
