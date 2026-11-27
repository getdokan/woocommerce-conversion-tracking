/**
 * External dependencies
 */
import { Button } from '@wedevs/plugin-ui';
import { RefreshCw } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { useAppData } from '../../data/AppData';
import { canRerun, stepHash } from '../../data/onboarding';
import { SectionCard } from './SectionCard';

/**
 * Settings > Setup: run the wizard again on a draft, or continue a setup that isn't finished.
 */
export const SetupSection = () => {
    const { data } = useAppData();
    const rerun = !! data && canRerun( data );
    const href = rerun ? stepHash( 1, true ) : '#/onboarding';

    return (
        <SectionCard
            id="setup"
            icon={ <RefreshCw /> }
            title={ __( 'Setup', 'woocommerce-conversion-tracking' ) }
            description={ __(
                'The guided setup you saw when you installed the plugin.',
                'woocommerce-conversion-tracking'
            ) }
        >
            <div className="flex flex-wrap items-center gap-4 px-6 py-5">
                <div className="flex min-w-0 flex-1 flex-col gap-0.5 text-sm">
                    <span className="font-semibold text-foreground">
                        { __(
                            'Setup wizard',
                            'woocommerce-conversion-tracking'
                        ) }
                    </span>
                    <span className="text-muted-foreground">
                        { rerun
                            ? __(
                                  'Walk through platforms, Europe and the first event again. Your current settings are filled in, and nothing changes unless you finish.',
                                  'woocommerce-conversion-tracking'
                              )
                            : __(
                                  'Pick up the setup where you left it.',
                                  'woocommerce-conversion-tracking'
                              ) }
                    </span>
                </div>
                { data && (
                    <Button
                        variant="outline"
                        className="ml-auto"
                        nativeButton={ false }
                        // eslint-disable-next-line jsx-a11y/anchor-has-content -- the button renders the label inside.
                        render={ <a href={ href } /> }
                        data-testid="wcct-setup-rerun"
                    >
                        { rerun
                            ? __(
                                  'Run setup wizard again',
                                  'woocommerce-conversion-tracking'
                              )
                            : __(
                                  'Continue setup',
                                  'woocommerce-conversion-tracking'
                              ) }
                    </Button>
                ) }
            </div>
        </SectionCard>
    );
};
