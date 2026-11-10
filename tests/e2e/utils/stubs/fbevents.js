// Stand-in for connect.facebook.net/en_US/fbevents.js. Turns fbq() calls into the /tr GET hits the real library sends.
( function ( window, document ) {
    var fbq = window.fbq;

    if ( ! fbq || fbq.ctE2eStub ) {
        return;
    }

    var pixels = [];

    var send = function ( pixelId, eventName, params, options ) {
        var query = [
            'id=' + encodeURIComponent( pixelId ),
            'ev=' + encodeURIComponent( eventName ),
            // Like the real library, the eventID option is sent as eid.
            'eid=' + encodeURIComponent( options && options.eventID ? options.eventID : '' ),
            // Like the real library, the page URL and referrer come from the browser.
            'dl=' + encodeURIComponent( document.location.href ),
            'rl=' + encodeURIComponent( document.referrer ),
            'if=false',
            'ts=' + Date.now(),
        ];

        params = params || {};

        for ( var key in params ) {
            if ( Object.prototype.hasOwnProperty.call( params, key ) ) {
                var value = typeof params[ key ] === 'object' ? JSON.stringify( params[ key ] ) : String( params[ key ] );
                query.push( 'cd[' + encodeURIComponent( key ) + ']=' + encodeURIComponent( value ) );
            }
        }

        new Image().src = 'https://www.facebook.com/tr/?' + query.join( '&' );
    };

    // Every fbq() call this page made, so a spec can check the method ([method, pixel or event, ...]).
    window.ctE2eFbqCalls = window.ctE2eFbqCalls || [];

    var handle = function ( args ) {
        var method = args[ 0 ];

        window.ctE2eFbqCalls.push( Array.prototype.slice.call( args ) );

        if ( method === 'init' ) {
            if ( pixels.indexOf( String( args[ 1 ] ) ) === -1 ) {
                pixels.push( String( args[ 1 ] ) );
            }
        } else if ( method === 'track' || method === 'trackCustom' ) {
            for ( var i = 0; i < pixels.length; i++ ) {
                send( pixels[ i ], args[ 1 ], args[ 2 ], args[ 3 ] );
            }
        } else if ( method === 'trackSingle' || method === 'trackSingleCustom' ) {
            send( String( args[ 1 ] ), args[ 2 ], args[ 3 ], args[ 4 ] );
        }
    };

    var queue = fbq.queue || [];

    fbq.queue = [];
    fbq.callMethod = function () {
        handle( arguments );
    };
    fbq.ctE2eStub = true;

    for ( var i = 0; i < queue.length; i++ ) {
        handle( queue[ i ] );
    }
} )( window, document );
