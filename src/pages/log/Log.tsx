/**
 * External dependencies
 */
import {
    Button,
    Card,
    DataViews,
    Dialog,
    DialogContent,
    DialogTitle,
    Notice,
    NoticeTitle,
    Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
    type DataViewField,
    type DataViewState,
} from '@wedevs/plugin-ui';
import { Page, PlatformLogo, StatusPill } from '@wcct/components';
import { Inbox, Search } from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';

/**
 * WordPress dependencies
 */
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    useSyncExternalStore,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { errorMessage } from '../../data/api';
import {
    LOG_TABS,
    PER_PAGE,
    fetchLog,
    tabFromQuery,
    type LogOrder,
    type LogPage,
    type LogTab,
} from '../../data/log';
import { platformLogo } from '../../data/platforms';
import { replaceHash } from '../../router';
import { CardSkeleton } from '../../shell/PageSkeleton';
import { pageInfo } from '../titles';
import { LogDetail } from './LogDetail';
import {
    TONES,
    eventLabel,
    joinNames,
    sentText,
    statusLabel,
    tabLabel,
} from './text';

// Below this width the row detail opens in a dialog (prototype #log-dialog).
const NARROW = '(max-width: 1100px)';

// A waiting order is read again this long after it settles.
const SETTLE_GRACE_MS = 5000;

const subscribeNarrow = ( onChange: () => void ) => {
    const query = window.matchMedia( NARROW );

    query.addEventListener( 'change', onChange );

    return () => query.removeEventListener( 'change', onChange );
};

const isNarrow = () => window.matchMedia( NARROW ).matches;

/**
 * The Sent column: the logos of the platforms the order was handed to, or a short line.
 *
 * @param {Object}   props       Component props.
 * @param {LogOrder} props.order Order.
 */
const SentCell = ( { order }: { order: LogOrder } ) => {
    const text = sentText( order );

    if ( text ) {
        return (
            <span className="text-[13px] text-muted-foreground">{ text }</span>
        );
    }

    const sent = order.platforms.filter( ( platform ) => platform.sent );
    const names = joinNames( sent.map( ( platform ) => platform.name ) );

    return (
        <span className="inline-flex items-center gap-1" title={ names }>
            { sent.map( ( platform ) => (
                <PlatformLogo
                    key={ platform.id }
                    src={ platformLogo( platform.id ) }
                    className="size-[22px] rounded-sm [&>img]:size-3.5"
                />
            ) ) }
            <span className="sr-only">{ names }</span>
        </span>
    );
};

/**
 * An empty state with a round icon.
 *
 * @param {Object}    props             Component props.
 * @param {ReactNode} props.icon        Icon.
 * @param {string}    props.title       Title.
 * @param {string}    props.description Line under it.
 * @param {string}    props.testId      Test ID.
 */
const Empty = ( {
    icon,
    title,
    description,
    testId,
}: {
    icon: ReactNode;
    title: string;
    description: string;
    testId: string;
} ) => (
    <div
        className="flex flex-col items-center gap-1 px-6 py-12 text-center"
        data-testid={ testId }
    >
        <span
            className="mb-4 inline-flex size-32 items-center justify-center rounded-full bg-accent text-primary [&>svg]:size-12"
            aria-hidden="true"
        >
            { icon }
        </span>
        <p className="m-0 text-lg font-semibold text-foreground">{ title }</p>
        <p className="m-0 mt-1 max-w-[380px] text-sm text-muted-foreground">
            { description }
        </p>
    </div>
);

/**
 * The Activity log: the last 24 hours of orders, by tab, with the selected order's detail.
 *
 * @param {Object} props       Component props.
 * @param {string} props.query Route query; ?tab= picks the tab.
 */
const Log = ( { query }: { query: string } ) => {
    const { title, description } = pageInfo( { name: 'log', query: '' } );
    const [ tab, setTab ] = useState< LogTab >( () => tabFromQuery( query ) );
    const [ page, setPage ] = useState( 1 );
    const [ data, setData ] = useState< LogPage | null >( null );
    const [ loading, setLoading ] = useState( true );
    const [ error, setError ] = useState( '' );
    const [ selected, setSelected ] = useState< number | null >( null );
    const [ dialogOpen, setDialogOpen ] = useState( false );
    // One line for screen readers when a row opens, instead of reading the whole detail.
    const [ announce, setAnnounce ] = useState( '' );
    const narrow = useSyncExternalStore( subscribeNarrow, isNarrow );
    const request = useRef( 0 );

    const load = useCallback( () => {
        const current = ++request.current;

        setLoading( true );

        fetchLog( tab, page )
            .then( ( next ) => {
                if ( current !== request.current ) {
                    return;
                }

                setData( next );
                setError( '' );
            } )
            .catch( ( reason ) => {
                if ( current === request.current ) {
                    setError( errorMessage( reason ) );
                }
            } )
            .finally( () => {
                if ( current === request.current ) {
                    setLoading( false );
                }
            } );
    }, [ tab, page ] );

    useEffect( () => {
        load();
    }, [ load ] );

    // Ignore answers that arrive after the page closed.
    useEffect(
        () => () => {
            request.current++;
        },
        []
    );

    // A link to #/log?tab=… while the log is open switches the tab.
    useEffect( () => {
        setTab( tabFromQuery( query ) );
        setPage( 1 );
    }, [ query ] );

    // Read again once a waiting order's result is final.
    useEffect( () => {
        const settles = ( data?.orders ?? [] )
            .map( ( order ) =>
                order.settles_at ? Date.parse( order.settles_at ) : 0
            )
            .filter( Boolean );

        if ( ! settles.length ) {
            return;
        }

        const timer = window.setTimeout(
            load,
            Math.min(
                Math.max( Math.min( ...settles ) - Date.now(), 0 ) +
                    SETTLE_GRACE_MS,
                ( data?.settle ?? 600 ) * 1000
            )
        );

        return () => window.clearTimeout( timer );
    }, [ data, load ] );

    const orders = useMemo( () => data?.orders ?? [], [ data ] );
    const current =
        orders.find( ( order ) => order.id === selected ) ?? orders[ 0 ];

    const changeTab = ( next: LogTab ) => {
        setTab( next );
        setPage( 1 );
        setSelected( null );
        replaceHash( next === 'all' ? '#/log' : `#/log?tab=${ next }` );
    };

    const open = useCallback(
        ( order: LogOrder ) => {
            setSelected( order.id );
            setAnnounce(
                sprintf(
                    /* translators: 1: order number, 2: status, for example Counted once */
                    __(
                        'Showing order #%1$s: %2$s',
                        'woocommerce-conversion-tracking'
                    ),
                    order.number,
                    statusLabel( order )
                )
            );

            if ( narrow ) {
                setDialogOpen( true );
            }
        },
        [ narrow ]
    );

    const fields = useMemo< DataViewField< LogOrder >[] >(
        () => [
            {
                id: 'time',
                label: __( 'Time', 'woocommerce-conversion-tracking' ),
                render: ( { item } ) => (
                    <span className="whitespace-nowrap text-muted-foreground">
                        { item.created_label }
                    </span>
                ),
            },
            {
                id: 'order',
                label: __( 'Order', 'woocommerce-conversion-tracking' ),
                render: ( { item } ) => (
                    <div>
                        <b className="font-semibold text-foreground">
                            #{ item.number }
                        </b>
                        <div className="text-xs text-muted-foreground">
                            { sprintf(
                                /* translators: 1: order amount, 2: what it is, for example Purchase */
                                __(
                                    '%1$s, %2$s',
                                    'woocommerce-conversion-tracking'
                                ),
                                item.amount,
                                eventLabel( item )
                            ) }
                        </div>
                    </div>
                ),
            },
            {
                id: 'sent',
                label: __( 'Sent', 'woocommerce-conversion-tracking' ),
                render: ( { item } ) => <SentCell order={ item } />,
            },
            {
                id: 'status',
                label: __( 'Status', 'woocommerce-conversion-tracking' ),
                render: ( { item } ) => (
                    <StatusPill
                        tone={ TONES[ item.status ] }
                        className="whitespace-nowrap"
                    >
                        { statusLabel( item ) }
                    </StatusPill>
                ),
            },
            {
                id: 'details',
                label: __( 'Details', 'woocommerce-conversion-tracking' ),
                header: (
                    <span className="sr-only">
                        { __( 'Details', 'woocommerce-conversion-tracking' ) }
                    </span>
                ),
                render: ( { item } ) => (
                    <Button
                        variant="ghost"
                        size="sm"
                        data-log-order={ item.id }
                        data-selected={ current?.id === item.id }
                        aria-current={
                            current?.id === item.id ? true : undefined
                        }
                        onClick={ () => open( item ) }
                    >
                        { __( 'View', 'woocommerce-conversion-tracking' ) }
                        <span className="sr-only">
                            { ' ' }
                            { sprintf(
                                /* translators: %s: order number */
                                __(
                                    'order #%s',
                                    'woocommerce-conversion-tracking'
                                ),
                                item.number
                            ) }
                        </span>
                    </Button>
                ),
            },
        ],
        [ current?.id, open ]
    );

    const view: DataViewState = {
        type: 'table',
        page,
        perPage: PER_PAGE,
        fields: [ 'time', 'order', 'sent', 'status', 'details' ],
        layout: { enableMoving: false },
    };

    const onChangeView = useCallback(
        ( next: DataViewState ) => {
            if ( next.page && next.page !== page ) {
                setPage( next.page );
                setSelected( null );
            }
        },
        [ page ]
    );

    // A click anywhere on a row opens it, like its View button (the keyboard path).
    const rowClick = ( event: MouseEvent< HTMLDivElement > ) => {
        const target = event.target as HTMLElement;

        if ( target.closest( 'button, a, summary' ) ) {
            return;
        }

        const id = Number(
            target
                .closest( 'tr' )
                ?.querySelector< HTMLElement >( '[data-log-order]' )?.dataset
                .logOrder
        );
        const order = orders.find( ( item ) => item.id === id );

        if ( order ) {
            open( order );
        }
    };

    const count = data
        ? sprintf(
              /* translators: %d: number of orders */
              _n(
                  '%d order in the last 24 hours',
                  '%d orders in the last 24 hours',
                  data.total,
                  'woocommerce-conversion-tracking'
              ),
              data.total
          )
        : '';

    let body: ReactNode;

    if ( ! data ) {
        body = error ? (
            <Notice variant="destructive" role="alert">
                <NoticeTitle>
                    { __(
                        'Could not load the Activity log.',
                        'woocommerce-conversion-tracking'
                    ) }
                </NoticeTitle>
                <p className="m-0">{ error }</p>
                <div>
                    <Button variant="outline" size="sm" onClick={ load }>
                        { __( 'Try again', 'woocommerce-conversion-tracking' ) }
                    </Button>
                </div>
            </Notice>
        ) : (
            <Card className="px-6" role="status" aria-busy="true">
                <span className="sr-only">
                    { __( 'Loading…', 'woocommerce-conversion-tracking' ) }
                </span>
                <CardSkeleton lines={ 4 } />
            </Card>
        );
    } else if ( ! data.counts.all ) {
        body = (
            <Card className="py-0" data-testid="wcct-log">
                <Empty
                    testId="wcct-log-empty"
                    icon={ <Inbox /> }
                    title={ __(
                        'Waiting for your first event',
                        'woocommerce-conversion-tracking'
                    ) }
                    description={ __(
                        'No events yet. Place a test order and come back in a minute.',
                        'woocommerce-conversion-tracking'
                    ) }
                />
            </Card>
        );
    } else {
        const table = orders.length ? (
            // eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions -- each row's View button is the keyboard path.
            <div
                onClick={ rowClick }
                className="[&_tbody_tr]:cursor-pointer [&_tr:has([data-selected=true])_td]:bg-accent!"
            >
                <DataViews< LogOrder >
                    namespace="wcct-log"
                    view={ view }
                    onChangeView={ onChangeView }
                    fields={ fields }
                    data={ orders }
                    getItemId={ ( item ) => String( item.id ) }
                    isLoading={ loading }
                    responsive={ false }
                    defaultLayouts={ { table: {} } }
                    paginationInfo={ {
                        totalItems: data.total,
                        totalPages: data.pages,
                    } }
                >
                    <DataViews.Layout />
                    { data.pages > 1 && <DataViews.Pagination /> }
                </DataViews>
            </div>
        ) : (
            <Empty
                testId="wcct-log-nomatch"
                icon={ <Search /> }
                title={ __(
                    'Nothing matches',
                    'woocommerce-conversion-tracking'
                ) }
                description={ __(
                    'No orders match this filter. Try All.',
                    'woocommerce-conversion-tracking'
                ) }
            />
        );

        body = (
            <div className="grid grid-cols-[minmax(0,1.7fr)_minmax(320px,1fr)] items-start gap-5 max-[1100px]:grid-cols-1">
                <Card
                    className="gap-0 overflow-hidden py-0"
                    data-testid="wcct-log"
                >
                    <Tabs
                        value={ tab }
                        onValueChange={ ( next ) =>
                            changeTab( next as LogTab )
                        }
                    >
                        <div className="flex flex-wrap items-center gap-2 border-b border-border px-5 py-4">
                            <TabsList
                                aria-label={ __(
                                    'Filter by status',
                                    'woocommerce-conversion-tracking'
                                ) }
                            >
                                { LOG_TABS.map( ( item ) => (
                                    <TabsTrigger key={ item } value={ item }>
                                        { tabLabel( item ) }
                                    </TabsTrigger>
                                ) ) }
                            </TabsList>
                        </div>
                        <TabsContent
                            value={ tab }
                            className="overflow-x-auto"
                            data-testid="wcct-log-panel"
                        >
                            { table }
                        </TabsContent>
                    </Tabs>
                    <div
                        className="flex items-center justify-between border-t border-border px-5 py-[15px] text-[13px] text-muted-foreground"
                        data-testid="wcct-log-count"
                    >
                        { count }
                    </div>
                </Card>

                { current && ! narrow && (
                    <Card
                        className="px-6"
                        role="region"
                        aria-label={ __(
                            'Order details',
                            'woocommerce-conversion-tracking'
                        ) }
                        data-testid="wcct-log-detail-pane"
                    >
                        <LogDetail order={ current } settle={ data.settle } />
                    </Card>
                ) }
            </div>
        );
    }

    return (
        <Page
            title={ title }
            description={ description }
            actions={
                <StatusPill tone="off" className="before:hidden">
                    { __( 'Last 24 hours', 'woocommerce-conversion-tracking' ) }
                </StatusPill>
            }
        >
            { data && error && (
                <Notice variant="destructive" role="status">
                    <NoticeTitle>
                        { __(
                            'Could not refresh the Activity log. What you see may be out of date.',
                            'woocommerce-conversion-tracking'
                        ) }
                    </NoticeTitle>
                    <p className="m-0">{ error }</p>
                    <div>
                        <Button variant="outline" size="sm" onClick={ load }>
                            { __(
                                'Try again',
                                'woocommerce-conversion-tracking'
                            ) }
                        </Button>
                    </div>
                </Notice>
            ) }
            <p role="status" className="sr-only" data-testid="wcct-log-live">
                { announce }
            </p>
            { body }
            { current && data && (
                <Dialog
                    open={ narrow && dialogOpen }
                    onOpenChange={ ( next ) => setDialogOpen( next ) }
                >
                    <DialogContent className="max-h-[85vh] max-w-xl overflow-y-auto">
                        <DialogTitle className="sr-only">
                            { __(
                                'Order details',
                                'woocommerce-conversion-tracking'
                            ) }
                        </DialogTitle>
                        <LogDetail order={ current } settle={ data.settle } />
                    </DialogContent>
                </Dialog>
            ) }
        </Page>
    );
};

export default Log;
