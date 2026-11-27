/**
 * External dependencies
 */
import { MatricsCard, Skeleton, cn } from '@wedevs/plugin-ui';
import { Check, ShoppingCart, Stethoscope, TriangleAlert } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { Metrics } from '../../data/metrics';

// MatricsCard's icon circle, toned per card; literal classes so Tailwind finds them.
const TONES = {
    primary: '',
    warn: '[&>div>div:first-child]:bg-tone-warn-bg [&>div>div:first-child]:text-tone-warn-fg [&:hover>div>div:first-child]:bg-tone-warn-bg [&:hover>div>div:first-child]:text-tone-warn-fg',
    ok: '[&>div>div:first-child]:bg-tone-ok-bg [&>div>div:first-child]:text-tone-ok-fg [&:hover>div>div:first-child]:bg-tone-ok-bg [&:hover>div>div:first-child]:text-tone-ok-fg',
    off: '[&>div>div:first-child]:bg-tone-off-bg [&>div>div:first-child]:text-tone-off-fg [&:hover>div>div:first-child]:bg-tone-off-bg [&:hover>div>div:first-child]:text-tone-off-fg',
};

/**
 * "Not confirmed on Meta" when one platform didn't confirm, "Not confirmed" otherwise.
 *
 * @param {Metrics|null} metrics Metrics.
 */
const notConfirmedLabel = ( metrics: Metrics | null ): string => {
    const ids = Object.keys( metrics?.not_confirmed_by ?? {} );
    const name =
        ids.length === 1
            ? metrics?.platforms.find(
                  ( platform ) => platform.id === ids[ 0 ]
              )?.name
            : '';

    return name
        ? sprintf(
              /* translators: %s: platform name, for example Meta */
              __( 'Not confirmed on %s', 'woocommerce-conversion-tracking' ),
              name
          )
        : __( 'Not confirmed', 'woocommerce-conversion-tracking' );
};

/**
 * The four 24-hour stat cards, from the metrics route; a dash until it answers.
 *
 * @param {Object}       props               Component props.
 * @param {boolean}      props.refreshing    Show skeletons.
 * @param {Metrics|null} props.metrics       Metrics, null while they load or when they failed.
 * @param {string}       props.lastDoctorRun When the Doctor last ran, for example "2 days ago"; empty before the first run.
 */
export const StatCards = ( {
    refreshing,
    metrics,
    lastDoctorRun,
}: {
    refreshing: boolean;
    metrics: Metrics | null;
    lastDoctorRun: string;
} ) => {
    const empty = '–';
    const cards = [
        {
            id: 'purchases',
            icon: ShoppingCart,
            tone: TONES.primary,
            value: metrics ? String( metrics.purchases ) : empty,
            label: __( 'Purchases tracked', 'woocommerce-conversion-tracking' ),
        },
        {
            id: 'not-confirmed',
            icon: TriangleAlert,
            tone: TONES.warn,
            value: metrics ? String( metrics.not_confirmed ) : empty,
            label: notConfirmedLabel( metrics ),
        },
        {
            id: 'working',
            icon: Check,
            tone: TONES.ok,
            value: metrics
                ? sprintf(
                      /* translators: 1: platforms working, 2: platforms connected */
                      __( '%1$d of %2$d', 'woocommerce-conversion-tracking' ),
                      metrics.platforms_working,
                      metrics.platforms_connected
                  )
                : empty,
            label: __( 'Platforms working', 'woocommerce-conversion-tracking' ),
        },
        {
            id: 'doctor',
            icon: Stethoscope,
            tone: TONES.off,
            value:
                lastDoctorRun ||
                __( 'Not yet', 'woocommerce-conversion-tracking' ),
            label: __( 'Last Doctor run', 'woocommerce-conversion-tracking' ),
        },
    ];

    return (
        <section
            className="grid grid-cols-4 gap-4 max-[1100px]:grid-cols-2"
            aria-label={ __(
                'Last 24 hours at a glance',
                'woocommerce-conversion-tracking'
            ) }
        >
            { cards.map( ( card ) =>
                refreshing ? (
                    <Skeleton
                        key={ card.id }
                        className="h-23 rounded-xl"
                        role="status"
                        aria-label={ card.label }
                        aria-busy="true"
                        data-testid="wcct-card-skeleton"
                    />
                ) : (
                    <MatricsCard
                        key={ card.id }
                        data-testid={ `wcct-stat-${ card.id }` }
                        icon={ card.icon }
                        value={ card.value }
                        shortDescription={ card.label }
                        // CT cards: 14px corners like every card, not clickable.
                        className={ cn( 'cursor-auto rounded-xl', card.tone ) }
                    />
                )
            ) }
        </section>
    );
};
