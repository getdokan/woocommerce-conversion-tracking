/**
 * External dependencies
 */
import { TopBar, type TopBarProps } from '@wedevs/plugin-ui';
import { ThemeProvider } from '@wcct/components';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Placeholder app: the plugin-ui TopBar, until the shell and screens land.
 */
const App = () => {
    const { version = '', proVersion = '' } = window.wcctAdmin ?? {};

    const versions: NonNullable< TopBarProps[ 'versions' ] > = [
        {
            version: sprintf(
                /* translators: %s: plugin version */
                __( 'Lite %s', 'woocommerce-conversion-tracking' ),
                version
            ),
            isPro: false,
        },
    ];

    if ( proVersion ) {
        versions.push( {
            version: sprintf(
                /* translators: %s: plugin version */
                __( 'Pro %s', 'woocommerce-conversion-tracking' ),
                proVersion
            ),
            isPro: true,
        } );
    }

    return (
        <ThemeProvider>
            <TopBar
                className="rounded-xl ring-1 ring-foreground/10"
                logo={
                    <span className="text-lg leading-7.5 font-semibold text-foreground">
                        { __(
                            'Conversion Tracking',
                            'woocommerce-conversion-tracking'
                        ) }
                    </span>
                }
                versions={ versions }
            />
        </ThemeProvider>
    );
};

export default App;
