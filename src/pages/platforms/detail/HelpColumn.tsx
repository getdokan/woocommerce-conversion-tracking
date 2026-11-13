/**
 * External dependencies
 */
import type { ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { NewTabNote } from '../../../shell/NewTabNote';

const EVENTS_MANAGER = 'https://business.facebook.com/events_manager2/';
const GOOGLE_ADS = 'https://ads.google.com/aw/conversions';
const GA_ADMIN = 'https://analytics.google.com/analytics/web/';
const X_ADS = 'https://ads.x.com/';
const MICROSOFT_ADS = 'https://ads.microsoft.com/';

const external = ( href: string ) => (
    // eslint-disable-next-line jsx-a11y/anchor-has-content -- createInterpolateElement fills it.
    <a
        href={ href }
        target="_blank"
        rel="noreferrer"
        className="text-primary underline underline-offset-3"
    />
);

const internal = ( href: string ) => (
    // eslint-disable-next-line jsx-a11y/anchor-has-content -- createInterpolateElement fills it.
    <a href={ href } className="text-primary underline underline-offset-3" />
);

/**
 * One block of the help column.
 *
 * @param {Object}    props          Component props.
 * @param {string}    props.title    Heading.
 * @param {ReactNode} props.children Content.
 */
const Block = ( {
    title,
    children,
}: {
    title: string;
    children: ReactNode;
} ) => (
    <div className="flex flex-col gap-2.5">
        <h3 className="m-0 text-sm font-semibold text-foreground">{ title }</h3>
        { children }
    </div>
);

const Steps = ( { children }: { children: ReactNode } ) => (
    <ol className="m-0 flex flex-col gap-2.5 pl-5 text-sm leading-normal">
        { children }
    </ol>
);

/**
 * Meta: where the Pixel ID is, and what to do after saving.
 */
const MetaHelp = () => (
    <>
        <Block
            title={ __(
                'Where to find your Pixel ID',
                'woocommerce-conversion-tracking'
            ) }
        >
            <Steps>
                <li>
                    { createInterpolateElement(
                        __(
                            "Open <a>Meta Events Manager</a><n/> and choose your store's data source.",
                            'woocommerce-conversion-tracking'
                        ),
                        { a: external( EVENTS_MANAGER ), n: <NewTabNote /> }
                    ) }
                </li>
                <li>
                    { createInterpolateElement(
                        __(
                            'Click <b>Settings</b>. The Pixel ID is the number at the top, usually 15 or 16 digits.',
                            'woocommerce-conversion-tracking'
                        ),
                        { b: <b /> }
                    ) }
                </li>
                <li>
                    { __(
                        'Paste it into the Pixel ID field. We check the format as you type.',
                        'woocommerce-conversion-tracking'
                    ) }
                </li>
            </Steps>
        </Block>
        <Block
            title={ __( 'After you save', 'woocommerce-conversion-tracking' ) }
        >
            <p className="m-0 text-sm">
                { createInterpolateElement(
                    __(
                        "Place a test order on your store. Within a minute it shows in the <log>Activity log</log> as sent to Meta. If Meta's own plugin is also sending purchases, the <doctor>Doctor</doctor> will say so.",
                        'woocommerce-conversion-tracking'
                    ),
                    { log: internal( '#/log' ), doctor: internal( '#/doctor' ) }
                ) }
            </p>
        </Block>
    </>
);

/**
 * Google: the IDs, the Ads label split and double counting.
 */
const GoogleHelp = () => (
    <>
        <Block
            title={ __(
                'Finding the Ads conversion label',
                'woocommerce-conversion-tracking'
            ) }
        >
            <Steps>
                <li>
                    { createInterpolateElement(
                        __(
                            'In <a>Google Ads</a><n/>, open <b>Goals</b>, then <b>Conversions</b>, then <b>Summary</b>.',
                            'woocommerce-conversion-tracking'
                        ),
                        {
                            a: external( GOOGLE_ADS ),
                            n: <NewTabNote />,
                            b: <b />,
                        }
                    ) }
                </li>
                <li>
                    { createInterpolateElement(
                        __(
                            'Open your Purchase conversion and choose <b>Tag setup</b>, then <b>Use Google Tag Manager</b>.',
                            'woocommerce-conversion-tracking'
                        ),
                        { b: <b /> }
                    ) }
                </li>
                <li>
                    { createInterpolateElement(
                        __(
                            'Copy the <b>Conversion ID</b> into Ads conversion ID. It is only numbers there; we add AW- in front when you save.',
                            'woocommerce-conversion-tracking'
                        ),
                        { b: <b /> }
                    ) }
                </li>
                <li>
                    { createInterpolateElement(
                        __(
                            'Copy the <b>Conversion label</b> into Ads conversion label.',
                            'woocommerce-conversion-tracking'
                        ),
                        { b: <b /> }
                    ) }
                </li>
                <li>
                    { createInterpolateElement(
                        __(
                            'Have an event snippet instead? It ends in <code>AW-10938215/AbCdEfGhIj</code>. The part before the slash is the conversion ID, the part after it is the label.',
                            'woocommerce-conversion-tracking'
                        ),
                        { code: <code className="font-mono text-[12px]" /> }
                    ) }
                </li>
            </Steps>
        </Block>
        <Block
            title={ __(
                'Where to find the Measurement ID',
                'woocommerce-conversion-tracking'
            ) }
        >
            <p className="m-0 text-sm">
                { createInterpolateElement(
                    __(
                        'In <a>Google Analytics</a><n/>, open <b>Admin</b>, then <b>Data streams</b>, and pick your web stream. The Measurement ID starts with G-.',
                        'woocommerce-conversion-tracking'
                    ),
                    { a: external( GA_ADMIN ), n: <NewTabNote />, b: <b /> }
                ) }
            </p>
        </Block>
        <Block
            title={ __(
                'Avoid double counting',
                'woocommerce-conversion-tracking'
            ) }
        >
            <p
                className="m-0 text-sm"
                data-testid="wcct-google-double-counting"
            >
                { createInterpolateElement(
                    __(
                        'If Site Kit or Google for WooCommerce already sends purchases, the <doctor>Doctor</doctor> will show it and offer to keep one.',
                        'woocommerce-conversion-tracking'
                    ),
                    { doctor: internal( '#/doctor' ) }
                ) }
            </p>
        </Block>
    </>
);

/**
 * Any platform: what to do after saving.
 */
const AfterSave = () => (
    <Block title={ __( 'After you save', 'woocommerce-conversion-tracking' ) }>
        <p className="m-0 text-sm">
            { createInterpolateElement(
                __(
                    'Place a test order on your store. Within a minute it shows in the <log>Activity log</log>.',
                    'woocommerce-conversion-tracking'
                ),
                { log: internal( '#/log' ) }
            ) }
        </p>
    </Block>
);

/**
 * X: where each event ID is, and why upgraders set the events up again.
 */
const XHelp = () => (
    <>
        <Block
            title={ __(
                'Where to find the Pixel ID and event IDs',
                'woocommerce-conversion-tracking'
            ) }
        >
            <Steps>
                <li>
                    { createInterpolateElement(
                        __(
                            'In <a>X Ads Manager</a><n/>, open <b>Tools</b>, then <b>Events Manager</b>.',
                            'woocommerce-conversion-tracking'
                        ),
                        { a: external( X_ADS ), n: <NewTabNote />, b: <b /> }
                    ) }
                </li>
                <li>
                    { createInterpolateElement(
                        __(
                            "If you don't have an X Pixel event source yet, choose <b>Add event source</b>; its ID is your Pixel ID.",
                            'woocommerce-conversion-tracking'
                        ),
                        { b: <b /> }
                    ) }
                </li>
                <li>
                    { createInterpolateElement(
                        __(
                            'Choose <b>Add events</b>, pick <b>Purchase</b> and the <b>Pixel</b> install method.',
                            'woocommerce-conversion-tracking'
                        ),
                        { b: <b /> }
                    ) }
                </li>
                <li>
                    { createInterpolateElement(
                        __(
                            'Copy the ID that starts with <code>tw-</code> and paste it into the Purchase event ID field. Repeat for Add to cart, Start checkout or Registration if you want those too.',
                            'woocommerce-conversion-tracking'
                        ),
                        { code: <code className="font-mono text-[12px]" /> }
                    ) }
                </li>
            </Steps>
        </Block>
        <Block
            title={ __( 'Why a re-setup', 'woocommerce-conversion-tracking' ) }
        >
            <p className="m-0 text-sm">
                { __(
                    'X moved from one code per event to one pixel with named events. Your old codes stopped counting, so we ask once instead of guessing.',
                    'woocommerce-conversion-tracking'
                ) }
            </p>
        </Block>
        <AfterSave />
    </>
);

/**
 * Microsoft: where the UET tag ID is.
 */
const MicrosoftHelp = () => (
    <>
        <Block
            title={ __(
                'Where to find your UET tag ID',
                'woocommerce-conversion-tracking'
            ) }
        >
            <Steps>
                <li>
                    { createInterpolateElement(
                        __(
                            'In <a>Microsoft Advertising</a><n/>, open <b>Conversions</b>, then <b>UET tag</b>.',
                            'woocommerce-conversion-tracking'
                        ),
                        {
                            a: external( MICROSOFT_ADS ),
                            n: <NewTabNote />,
                            b: <b />,
                        }
                    ) }
                </li>
                <li>
                    { createInterpolateElement(
                        __(
                            'Find the tag for this store. The ID is the number in the <b>Tag ID</b> column.',
                            'woocommerce-conversion-tracking'
                        ),
                        { b: <b /> }
                    ) }
                </li>
                <li>
                    { __(
                        'Paste it into the UET tag ID field. We check the format as you type.',
                        'woocommerce-conversion-tracking'
                    ) }
                </li>
            </Steps>
        </Block>
        <AfterSave />
    </>
);

/**
 * The help content of a platform page.
 *
 * @param {Object} props    Component props.
 * @param {string} props.id Platform ID.
 */
export const HelpContent = ( { id }: { id: string } ) => {
    if ( id === 'facebook' ) {
        return <MetaHelp />;
    }

    if ( id === 'google' ) {
        return <GoogleHelp />;
    }

    if ( id === 'twitter' ) {
        return <XHelp />;
    }

    if ( id === 'bing_ads' ) {
        return <MicrosoftHelp />;
    }

    return <AfterSave />;
};
