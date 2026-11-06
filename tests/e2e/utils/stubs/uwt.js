// Stand-in for static.ads-twitter.com/uwt.js. Turns twq('config') and twq('event') into the adsct GET hits the real library sends.
( function ( window, document ) {
    var twq = window.twq;

    if ( ! twq || twq.ctE2eStub ) {
        return;
    }

    var pixels = [];

    var send = function ( txnId, eventName, params ) {
        var query = [
            'txn_id=' + encodeURIComponent( txnId ),
            'p_id=Twitter',
            'event=' + encodeURIComponent( JSON.stringify( params || {} ) ),
            'tw_event_name=' + encodeURIComponent( eventName ),
            // Like the real library, the page URL and referrer come from the browser.
            'tw_document_href=' + encodeURIComponent( document.location.href ),
            'tw_document_referrer=' + encodeURIComponent( document.referrer ),
            'type=javascript',
        ];

        new Image().src = 'https://analytics.twitter.com/1/i/adsct?' + query.join( '&' );
    };

    var handle = function ( args ) {
        if ( args[ 0 ] === 'config' ) {
            pixels.push( String( args[ 1 ] ) );
            send( String( args[ 1 ] ), 'PageView', {} );
        } else if ( args[ 0 ] === 'event' ) {
            send( String( args[ 1 ] ), 'event', args[ 2 ] );
        }
    };

    var queue = twq.queue || [];

    twq.queue = [];
    twq.exe = function () {
        handle( arguments );
    };
    twq.ctE2eStub = true;

    for ( var i = 0; i < queue.length; i++ ) {
        handle( queue[ i ] );
    }
} )( window, document );
