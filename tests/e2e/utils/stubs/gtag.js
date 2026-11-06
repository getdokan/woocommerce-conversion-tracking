// Stand-in for googletagmanager.com/gtag/js. Turns GA4 gtag() commands into /g/collect GET hits and Google Ads ones into conversion hits.
( function ( window, document ) {
    var dataLayer = ( window.dataLayer = window.dataLayer || [] );
    // Like gtag.js, which the check-ins read as "library loaded".
    window.google_tag_data = window.google_tag_data || {};

    if ( dataLayer.ctE2eStub ) {
        return;
    }

    var configs = {};
    var skipped = [ 'page_location', 'page_referrer', 'event_callback', 'event_timeout', 'send_to', 'items', 'currency' ];

    var encode = function ( value ) {
        return encodeURIComponent( value === undefined || value === null ? '' : String( value ) );
    };

    var send = function ( id, eventName, params ) {
        var config = configs[ id ] || {};
        var query = [
            'v=2',
            'tid=' + encode( id ),
            'en=' + encode( eventName ),
            // Like gtag.js, page_location and page_referrer win over the browser's URL and referrer.
            'dl=' + encode( params.page_location || config.page_location || document.location.href ),
            'dr=' + encode( params.page_referrer || config.page_referrer || document.referrer ),
            'dt=' + encode( document.title ),
        ];

        if ( params.currency ) {
            query.push( 'cu=' + encode( params.currency ) );
        }

        for ( var key in params ) {
            if ( Object.prototype.hasOwnProperty.call( params, key ) && skipped.indexOf( key ) === -1 ) {
                query.push( ( typeof params[ key ] === 'number' ? 'epn.' : 'ep.' ) + key + '=' + encode( params[ key ] ) );
            }
        }

        var items = params.items || [];

        for ( var i = 0; i < items.length; i++ ) {
            query.push( 'pr' + ( i + 1 ) + '=' + encode( 'id' + items[ i ].item_id + '~nm' + items[ i ].item_name + '~pr' + items[ i ].price + '~qt' + items[ i ].quantity ) );
        }

        var image = new Image();

        if ( typeof params.event_callback === 'function' ) {
            image.onload = image.onerror = function () {
                params.event_callback();
            };
        }

        image.src = 'https://region1.google-analytics.com/g/collect?' + query.join( '&' );
    };

    // Google Ads: a remarketing hit on config and a conversion hit per conversion event, with the page URL and referrer.
    var sendAds = function ( id, label, params ) {
        var config = configs[ id ] || {};
        var query = [
            'label=' + encode( label ),
            'url=' + encode( params.page_location || config.page_location || document.location.href ),
            'ref=' + encode( params.page_referrer || config.page_referrer || document.referrer ),
            'value=' + encode( params.value ),
            'currency_code=' + encode( params.currency ),
            'oid=' + encode( params.transaction_id ),
        ];
        var path = label ? 'https://www.googleadservices.com/pagead/conversion/' : 'https://googleads.g.doubleclick.net/pagead/viewthroughconversion/';

        var image = new Image();

        if ( typeof params.event_callback === 'function' ) {
            image.onload = image.onerror = function () {
                params.event_callback();
            };
        }

        image.src = path + encode( id.replace( /^AW-/, '' ) ) + '/?' + query.join( '&' );
    };

    var handle = function ( args ) {
        if ( Object.prototype.toString.call( args ) !== '[object Arguments]' ) {
            return;
        }

        var params = args[ 2 ] || {};

        if ( args[ 0 ] === 'config' && /^G-/.test( args[ 1 ] ) ) {
            configs[ args[ 1 ] ] = params;

            if ( params.send_page_view !== false ) {
                send( args[ 1 ], 'page_view', {} );
            }
        } else if ( args[ 0 ] === 'config' && /^AW-/.test( args[ 1 ] ) ) {
            configs[ args[ 1 ] ] = params;
            sendAds( args[ 1 ], '', {} );
        } else if ( args[ 0 ] === 'event' && params.send_to && /^AW-[^/]+\/./.test( String( params.send_to ) ) ) {
            var target = String( params.send_to ).split( '/' );

            sendAds( target[ 0 ], target[ 1 ], params );
        } else if ( args[ 0 ] === 'event' ) {
            var targets = params.send_to ? [].concat( params.send_to ) : Object.keys( configs );

            for ( var i = 0; i < targets.length; i++ ) {
                if ( /^G-/.test( targets[ i ] ) ) {
                    send( targets[ i ], args[ 1 ], params );
                }
            }
        }
    };

    for ( var i = 0; i < dataLayer.length; i++ ) {
        handle( dataLayer[ i ] );
    }

    var push = dataLayer.push;

    dataLayer.push = function () {
        for ( var i = 0; i < arguments.length; i++ ) {
            handle( arguments[ i ] );
        }

        return push.apply( dataLayer, arguments );
    };
    dataLayer.ctE2eStub = true;
} )( window, document );
