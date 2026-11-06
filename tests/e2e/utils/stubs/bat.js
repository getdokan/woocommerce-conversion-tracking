// Stand-in for bat.bing.com/bat.js. UET sends the page load and each event with the page URL and referrer.
( function ( window, document ) {
    if ( window.UET ) {
        return;
    }

    var send = function ( tagId, params ) {
        var query = [ 'ti=' + encodeURIComponent( tagId ), 'p=' + encodeURIComponent( document.location.href ), 'r=' + encodeURIComponent( document.referrer ) ];

        for ( var key in params ) {
            if ( Object.prototype.hasOwnProperty.call( params, key ) ) {
                query.push( encodeURIComponent( key ) + '=' + encodeURIComponent( typeof params[ key ] === 'object' ? JSON.stringify( params[ key ] ) : params[ key ] ) );
            }
        }

        new Image().src = 'https://bat.bing.com/action/0?' + query.join( '&' );
    };

    window.UET = function ( options ) {
        var tagId = options.ti;
        var handle = function ( args ) {
            if ( args[ 0 ] === 'pageLoad' ) {
                send( tagId, { evt: 'pageLoad' } );
            } else if ( args[ 0 ] === 'event' ) {
                var params = args[ 2 ] || {};
                params.evt = 'custom';
                params.ea = args[ 1 ];
                send( tagId, params );
            }
        };
        var queue = options.q || [];

        this.push = function () {
            handle( arguments );
        };

        // Code that ran before the library pushed its arguments one by one onto a plain array.
        for ( var i = 0; i < queue.length; i++ ) {
            if ( queue[ i ] === 'event' ) {
                handle( [ 'event', queue[ i + 1 ], queue[ i + 2 ] ] );
                i += 2;
            } else {
                handle( [ queue[ i ] ] );
            }
        }
    };
} )( window, document );
