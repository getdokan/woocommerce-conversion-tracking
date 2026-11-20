/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './api';

export type PlatformState =
    | 'working'
    | 'attention'
    | 'waiting'
    | 'paused'
    | 'not_set_up';

export type MetricsNote = {
    text: string;
    // In-app hash link, empty for none.
    link: string;
    link_label: string;
};

export type MetricsPlatform = {
    // Settings platform ID.
    id: string;
    name: string;
    state: PlatformState;
    connected: boolean;
    paused: boolean;
    // Last 24 hours.
    confirmed: number;
    not_confirmed: number;
    // Kept past the 24-hour prune.
    last_purchase: {
        at: string;
        ago: string;
        order_id: number;
        order_number: string;
    } | null;
    // ISO 8601, null when unknown.
    connected_at: string | null;
    note: MetricsNote | null;
};

export type MetricsHour = {
    // ISO 8601 start of the hour.
    start: string;
    // Site time, for example 14:00.
    label: string;
    sent: number;
    not_confirmed: number;
};

export type NotConfirmedOrder = {
    id: number;
    number: string;
    // Platform IDs that didn't confirm it.
    platforms: string[];
};

export type NextStep = {
    id: string;
    text: string;
    link: string;
    link_label: string;
};

// The wcct/v1/metrics response: the last 24 hours.
export type Metrics = {
    since: string;
    now: string;
    // Checked-in paid orders: sent plus not confirmed.
    purchases: number;
    sent: number;
    not_confirmed: number;
    // Platform ID => not confirmed orders.
    not_confirmed_by: Record< string, number >;
    // Never in the Not confirmed count or the chart.
    no_checkin: number;
    pending: number;
    waiting: number;
    platforms_working: number;
    platforms_connected: number;
    hourly: MetricsHour[];
    platforms: MetricsPlatform[];
    // Empty while the Not confirmed alert is off.
    not_confirmed_orders: NotConfirmedOrder[];
    // Alert key => on.
    alerts: Record< string, boolean >;
    doctor: {
        ran_at: string | null;
        ran_ago: string;
        open: number;
        // 0 while "Doctor found something new" is off.
        new: number;
    };
    next_steps: NextStep[];
};

/**
 * The Dashboard's numbers now.
 */
export const fetchMetrics = (): Promise< Metrics > =>
    apiFetch< Metrics >( { path: `${ NAMESPACE }/metrics` } );
