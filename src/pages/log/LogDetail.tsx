/**
 * External dependencies
 */
import { cn } from '@wedevs/plugin-ui';
import { PlatformLogo, StatusPill } from '@wcct/components';
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { LogOrder, LogPlatform } from '../../data/log';
import { platformLogo } from '../../data/platforms';
import {
    TONES,
    eventLabel,
    handOverStep,
    orderStatusText,
    statusLabel,
} from './text';

type StepTone = 'ok' | 'warn' | 'off';

const DOTS: Record< StepTone, string > = {
    ok: 'before:bg-tone-ok-dot before:shadow-[0_0_0_1px_var(--tone-ok-dot)]',
    warn: 'before:bg-tone-warn-dot before:shadow-[0_0_0_1px_var(--tone-warn-dot)]',
    off: 'before:bg-tone-off-dot before:shadow-[0_0_0_1px_var(--tone-off-dot)]',
};

const NOTE = 'm-0 text-[13px] text-muted-foreground';

/**
 * A vertical list of steps joined by a line.
 *
 * @param {Object}    props          Component props.
 * @param {ReactNode} props.children Steps.
 */
const Timeline = ( { children }: { children: ReactNode } ) => (
    <ul className="relative m-0 list-none py-0 pr-0 pl-[22px] before:absolute before:top-2.5 before:bottom-2.5 before:left-[7px] before:w-0.5 before:bg-border before:content-['']">
        { children }
    </ul>
);

/**
 * One step: a dot in its tone and the text.
 *
 * @param {Object}    props          Component props.
 * @param {StepTone}  props.tone     Dot tone.
 * @param {ReactNode} props.children Text.
 */
const Step = ( {
    tone = 'ok',
    children,
}: {
    tone?: StepTone;
    children: ReactNode;
} ) => (
    <li
        data-tone={ tone }
        className={ cn(
            "relative m-0 py-[5px] text-[13px] before:absolute before:top-2.5 before:-left-[19px] before:size-2.5 before:rounded-full before:border-2 before:border-card before:content-['']",
            DOTS[ tone ]
        ) }
    >
        { children }
    </li>
);

/**
 * A time after a step, when there is one.
 *
 * @param {string} text  Step.
 * @param {string} clock Time, may be empty.
 */
const at = ( text: string, clock: string ) =>
    clock
        ? sprintf(
              /* translators: 1: what happened, 2: time, for example "Thank-you page seen, 10:42" */
              __( '%1$s, %2$s', 'woocommerce-conversion-tracking' ),
              text,
              clock
          )
        : text;

/**
 * Why an order that never sends was skipped, in its status's words.
 *
 * @param {LogOrder} order Order.
 */
const skippedStep = ( order: LogOrder ): string => {
    if ( order.order_status === 'failed' ) {
        return __( 'Payment failed', 'woocommerce-conversion-tracking' );
    }

    if ( order.order_status === 'cancelled' ) {
        return __( 'Order cancelled', 'woocommerce-conversion-tracking' );
    }

    return orderStatusText( order );
};

/**
 * One platform's result and its text.
 *
 * @param {Object}      props          Component props.
 * @param {LogPlatform} props.platform Platform.
 * @param {number}      props.minutes  Minutes before a result is final.
 */
const PlatformResult = ( {
    platform,
    minutes,
}: {
    platform: LogPlatform;
    minutes: number;
} ) => {
    let steps: ReactNode = null;
    let note: ReactNode = null;

    switch ( platform.status ) {
        case 'confirmed':
            steps = (
                <>
                    <Step>{ handOverStep( platform.id ) }</Step>
                    <Step>
                        { at(
                            __(
                                'Reported to the log',
                                'woocommerce-conversion-tracking'
                            ),
                            platform.sent_at_label
                        ) }
                    </Step>
                    { platform.id !== 'google' && (
                        <Step>
                            { __(
                                'Counted once, even if the thank-you page reloads',
                                'woocommerce-conversion-tracking'
                            ) }
                        </Step>
                    ) }
                </>
            );
            break;
        case 'not_confirmed':
            steps = (
                <Step tone="warn">
                    { __(
                        'We couldn’t confirm this purchase was sent',
                        'woocommerce-conversion-tracking'
                    ) }
                </Step>
            );
            note = (
                <p className={ NOTE }>
                    { sprintf(
                        /* translators: %s: platform name, for example Meta */
                        __(
                            'The thank-you page checked in, but %s’s script didn’t confirm it ran. An ad blocker or a browser privacy setting often causes this, but we can’t tell for sure.',
                            'woocommerce-conversion-tracking'
                        ),
                        platform.name
                    ) }{ ' ' }
                    { __(
                        'If it happens often,',
                        'woocommerce-conversion-tracking'
                    ) }{ ' ' }
                    <a href="#/doctor" className="text-primary underline">
                        { __(
                            'ask the Doctor',
                            'woocommerce-conversion-tracking'
                        ) }
                    </a>
                    .
                </p>
            );
            break;
        case 'pending':
            steps = platform.sent ? (
                <Step>
                    { __(
                        'Sent on the thank-you page; payment still pending',
                        'woocommerce-conversion-tracking'
                    ) }
                </Step>
            ) : (
                <Step tone="warn">
                    { __(
                        'We couldn’t confirm this purchase was sent; payment still pending',
                        'woocommerce-conversion-tracking'
                    ) }
                </Step>
            );
            break;
        case 'waiting':
            steps = ! platform.checked_in_at ? (
                <Step tone="off">
                    { __(
                        'Waiting for the thank-you page to check in',
                        'woocommerce-conversion-tracking'
                    ) }
                </Step>
            ) : (
                <Step tone="off">
                    { sprintf(
                        /* translators: %d: minutes */
                        _n(
                            'Not confirmed yet. The result is final %d minute after the order checks in; a reload of the thank-you page can still confirm it.',
                            'Not confirmed yet. The result is final %d minutes after the order checks in; a reload of the thank-you page can still confirm it.',
                            minutes,
                            'woocommerce-conversion-tracking'
                        ),
                        minutes
                    ) }
                </Step>
            );
            break;
        case 'skipped':
            steps = (
                <Step tone="off">
                    { __(
                        'Not sent: the shopper didn’t accept cookies. That’s by design.',
                        'woocommerce-conversion-tracking'
                    ) }
                </Step>
            );
            break;
        case 'paused':
            steps = (
                <Step tone="off">
                    { sprintf(
                        /* translators: %s: platform name */
                        __(
                            'Not sent: %s is paused',
                            'woocommerce-conversion-tracking'
                        ),
                        platform.name
                    ) }
                </Step>
            );
            break;
        default:
            steps = (
                <Step tone="off">
                    { platform.unknown
                        ? __(
                              'No check-in from this platform, so we don’t judge it',
                              'woocommerce-conversion-tracking'
                          )
                        : __(
                              'No check-in from the thank-you page',
                              'woocommerce-conversion-tracking'
                          ) }
                </Step>
            );
    }

    return (
        <div
            className="flex flex-col gap-1.5"
            data-testid="wcct-log-platform"
            data-platform={ platform.id }
            data-status={ platform.status }
        >
            <h3 className="m-0 flex items-center gap-2 p-0 text-[13px] font-semibold text-foreground">
                <PlatformLogo
                    src={ platformLogo( platform.id ) }
                    className="size-[22px] rounded-sm [&>img]:size-3.5"
                />
                { platform.id === 'google'
                    ? __(
                          'Google Analytics and Ads',
                          'woocommerce-conversion-tracking'
                      )
                    : platform.name }
            </h3>
            <Timeline>{ steps }</Timeline>
            { note }
        </div>
    );
};

/**
 * The selected order: what happened, step by step, and per platform.
 *
 * @param {Object}   props         Component props.
 * @param {LogOrder} props.order   Order.
 * @param {number}   props.settle  Seconds before a result is final.
 * @param {string}   props.titleId ID for the heading.
 */
export const LogDetail = ( {
    order,
    settle,
    titleId,
}: {
    order: LogOrder;
    settle: number;
    titleId?: string;
} ) => {
    const minutes = Math.max( 1, Math.round( settle / 60 ) );
    const orderSkipped =
        order.status === 'skipped' && order.cause === 'order_status';
    const noCheckIn = order.status === 'no_checkin';
    const eventId = order.platforms.find( ( platform ) => platform.event_id )
        ?.event_id;

    return (
        <div className="flex flex-col gap-4" data-testid="wcct-log-detail">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2
                        id={ titleId }
                        className="m-0 p-0 text-base font-semibold text-foreground"
                    >
                        { sprintf(
                            /* translators: %s: order number */
                            __(
                                'Order #%s',
                                'woocommerce-conversion-tracking'
                            ),
                            order.number
                        ) }
                    </h2>
                    <p className="m-0 text-sm text-muted-foreground">
                        { sprintf(
                            /* translators: 1: what it is (Purchase), 2: amount, 3: when, for example "Today 10:42" */
                            __(
                                '%1$s, %2$s, %3$s',
                                'woocommerce-conversion-tracking'
                            ),
                            eventLabel( order ),
                            order.amount,
                            order.created_label
                        ) }
                    </p>
                </div>
                <StatusPill tone={ TONES[ order.status ] } className="shrink-0">
                    { statusLabel( order ) }
                </StatusPill>
            </div>

            <Timeline>
                <Step tone={ orderSkipped ? 'off' : 'ok' }>
                    { at(
                        __(
                            'Order created',
                            'woocommerce-conversion-tracking'
                        ),
                        order.created_clock
                    ) }
                </Step>
                { orderSkipped && (
                    <>
                        <Step tone="off">{ skippedStep( order ) }</Step>
                        <Step tone="off">
                            { __(
                                'Nothing sent to any platform, so it never counts as a sale',
                                'woocommerce-conversion-tracking'
                            ) }
                        </Step>
                    </>
                ) }
                { ! orderSkipped && order.thankyou_at && (
                    <Step>
                        { at(
                            __(
                                'Thank-you page seen',
                                'woocommerce-conversion-tracking'
                            ),
                            order.thankyou_clock
                        ) }
                    </Step>
                ) }
                { noCheckIn && (
                    <Step tone="off">
                        { order.cause === 'not_reported'
                            ? __(
                                  'The thank-you page opened but never checked in',
                                  'woocommerce-conversion-tracking'
                              )
                            : __(
                                  'The shopper didn’t reach the thank-you page, so it never checked in',
                                  'woocommerce-conversion-tracking'
                              ) }
                    </Step>
                ) }
                { order.status === 'pending' && (
                    <Step tone="off">
                        { __(
                            'Payment still pending',
                            'woocommerce-conversion-tracking'
                        ) }
                    </Step>
                ) }
            </Timeline>

            { noCheckIn && (
                <p className={ NOTE }>
                    { order.cause === 'not_reported'
                        ? __(
                              'The page loaded, but our check-in never arrived, for example because the tab closed at once or a script blocker stopped it. We can’t tell whether it was sent, so it isn’t counted as not confirmed.',
                              'woocommerce-conversion-tracking'
                          )
                        : __(
                              'Common with payments that don’t return to your store, one-click upsell pages and some custom checkouts. We can’t tell whether it was sent, so it isn’t counted as not confirmed.',
                              'woocommerce-conversion-tracking'
                          ) }
                </p>
            ) }

            { order.status === 'pending' && (
                <p className={ NOTE }>
                    { __(
                        'Bank transfers and cash on delivery are sent when the shopper reaches the thank-you page, like every other order. The order stays here until you mark it paid.',
                        'woocommerce-conversion-tracking'
                    ) }
                </p>
            ) }

            { ! orderSkipped &&
                ! noCheckIn &&
                order.platforms.map( ( platform ) => (
                    <PlatformResult
                        key={ platform.id }
                        platform={ platform }
                        minutes={ minutes }
                    />
                ) ) }

            <details className="text-[13px]">
                <summary className="cursor-pointer text-foreground">
                    { __(
                        'For developers',
                        'woocommerce-conversion-tracking'
                    ) }
                </summary>
                <dl className="mt-2 mb-0 grid grid-cols-[auto_1fr] gap-x-3 gap-y-1.5 text-muted-foreground">
                    { eventId && (
                        <>
                            <dt className="text-tone-off-fg">
                                { __(
                                    'Event ID',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </dt>
                            <dd className="m-0">
                                <code>{ eventId }</code>
                            </dd>
                        </>
                    ) }
                    <dt className="text-tone-off-fg">
                        { __( 'Source', 'woocommerce-conversion-tracking' ) }
                    </dt>
                    <dd className="m-0">
                        <code>browser</code>
                    </dd>
                    <dt className="text-tone-off-fg">
                        { __(
                            'Order status',
                            'woocommerce-conversion-tracking'
                        ) }
                    </dt>
                    <dd className="m-0">{ order.order_status_label }</dd>
                </dl>
            </details>
        </div>
    );
};
