(function( $ ) {
    // Data Save
    $( '#wcct-submit' ).on( 'click', function( e ) {
        e.preventDefault();

        $( '#wcct-submit' ).addClass( 'updating-message' );

        wp.ajax.send( 'wcct_save_settings', {
            data: $( '#integration-form' ).serialize(),
            success: function( response ) {

                $("#ajax-message")
                    .html('<p><strong>' + response.message + '</strong></p>')
                    .show()
                    .delay(3000)
                    .slideUp('fast');

                $('html, body').animate({
                    scrollTop: 0
                }, 'fast');

                $( '#wcct-submit' ).removeClass( 'updating-message' );
            },
            error: function(error) {
                $( '#wcct-submit' ).removeClass( 'updating-message' );
                alert( error && error.message ? error.message : 'something wrong happend' );
            }
        });

        return false;
    });

    // Toggoling the settings
    $( '.slider' ).on( 'click', function() {
        var id = $( this ).attr( 'data-id' );
        var target = $( '#setting-'+id );
        target.stop().toggle('fast');
    });

    // Default Settings
    $( '.toogle-seller:checked' ).each( function( index, value ) {
        var id =  $( value ).attr( 'data-id' );
        var target = $( '#setting-'+id );

        $( target ).css( 'display', 'block' );
    } );

    $('.event').on( 'change', function() {
        var target = $( this ).next('.event-label-box');
        target.addClass( 'event-label-space' );
        target.stop().toggle();

    } );

    $( '.event:checked' ).each( function( index, value ) {
        $( value ).next( '.event-label-box' ).addClass( 'event-label-space' );
        $( value ).next( '.event-label-box' ).css( 'display', 'block' );
    } );

    // Pro-Feature Message
    $( '.disabled-class' ).on( 'click', function() {
        var title =  $( this ).text();
        swal({
            title: title + ' is available in Pro version',
            text: 'Please upgrade to the Pro version to get all the awesome feature',
            buttons: {
                confirm: 'Get the Pro Version',
                cancel: 'Close',
            },
        }).then( function( is_confirm ){
            if ( is_confirm ) {
              window.open('https://wedevs.com/woocommerce-conversion-tracking/upgrade-to-pro/?utm_source=wp-admin&utm_medium=pro-upgrade&utm_campaign=wcct_upgrade&utm_content=Pro_Alert', '_blank');
            }
        }, function() {});
    } );

    // Change Tooltip Text
    $( '.toogle-seller' ).on( 'change', function() {
        var tooltipText = $( this ).parents( '.switch' ).find( '.integration-tooltip' );
        var text = $( tooltipText ).text().trim();
        var newText = '';

        if ( text == 'Activate' ) {
            newText = 'Deactivate'
        } else if( text == 'Deactivate' ) {
           newText = 'Activate';
        }

        $( tooltipText ).text( newText );
    } );

    // Install and activate happy addons
    $( '#wcct-install-happ-addons' ).on( 'click', function( e ) {
        e.preventDefault();
        var loader = $( '#wcct-preloader' );
        $('.wcct-update-icon').remove();
        $(this).text('Installing...');
        $(this).addClass( 'updating-message' );
        loader.show();
        var remote_message = $( '#wcct_remote_notice' );
        wp.ajax.send( 'activate_happy_addons', {
            data: {
                _wpnonce: wc_tracking.nonce,
            },
            success: function( response ) {
                $('.wcct-notice-wrap').hide();
                loader.hide();
                remote_message.show();
                remote_message.html('<p><strong>' + response.message + 'Happy Addons !</strong></p>');
            },
            error: function( error ) {
                loader.hide();
                remote_message.html('<p><strong>' + response.message + '</strong></p>');
            }
        } );
    } );

    // Dismiss notice
    $('.wcct-notice-wrap').on( 'click', function() {
        wp.ajax.send( 'wcct_dismissable_notice', {
            data: {
                _wpnonce: wc_tracking.nonce,
            },
            success: function( response ) {
                console.log( 'Success' );
            }
        } );
    } );

})( jQuery );
