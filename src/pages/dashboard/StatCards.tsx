/**
 * External dependencies
 */
import { MatricsCard, Skeleton, cn } from '@wedevs/plugin-ui';
import { Check, ShoppingCart, Stethoscope, TriangleAlert } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

// MatricsCard's icon circle, toned per card; literal classes so Tailwind finds them.
const TONES = {
    primary: '',
    warn: '[&>div>div:first-child]:bg-tone-warn-bg [&>div>div:first-child]:text-tone-warn-fg [&:hover>div>div:first-child]:bg-tone-warn-bg [&:hover>div>div:first-child]:text-tone-warn-fg',
    ok: '[&>div>div:first-child]:bg-tone-ok-bg [&>div>div:first-child]:text-tone-ok-fg [&:hover>div>div:first-child]:bg-tone-ok-bg [&:hover>div>div:first-child]:text-tone-ok-fg',
    off: '[&>div>div:first-child]:bg-tone-off-bg [&>div>div:first-child]:text-tone-off-fg [&:hover>div>div:first-child]:bg-tone-off-bg [&:hover>div>div:first-child]:text-tone-off-fg',
};

/**
 * The four 24-hour stat cards, empty until the metrics route fills them.
 *
 * @param {Object}  props               Component props.
 * @param {boolean} props.refreshing    Show skeletons.
 * @param {string}  props.lastDoctorRun When the Doctor last ran, for example "2 days ago"; empty before the first run.
 */
export const StatCards = ( {
    refreshing,
    lastDoctorRun,
}: {
    refreshing: boolean;
    lastDoctorRun: string;
} ) => {
    const empty = '–';
    const cards = [
        {
            id: 'purchases',
            icon: ShoppingCart,
            tone: TONES.primary,
            value: empty,
            label: __( 'Purchases tracked', 'woocommerce-conversion-tracking' ),
        },
        {
            id: 'not-confirmed',
            icon: TriangleAlert,
            tone: TONES.warn,
            value: empty,
            label: __( 'Not confirmed', 'woocommerce-conversion-tracking' ),
        },
        {
            id: 'working',
            icon: Check,
            tone: TONES.ok,
            value: empty,
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
