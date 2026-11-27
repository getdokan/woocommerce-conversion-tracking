/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { NAMESPACE } from './api';

export type FindingAction = {
    // link follows url; recheck runs the finding's check again.
    type: 'link' | 'recheck';
    label: string;
    url: string;
    external: boolean;
    // Asked before the link opens, when set.
    confirm: string;
};

export type Finding = {
    // Evidence hash: dismissals are stored under it.
    hash: string;
    check: string;
    key: string;
    found: string;
    why: string;
    action: FindingAction;
    confidence: 'sure' | 'unsure';
    severity: 'warning' | 'info';
    label: string;
    tags: string[];
    steps: string[];
    dismissed: boolean;
    // Found by the quiet re-check after a plugin was activated.
    new: boolean;
};

export type CheckLine = {
    check: string;
    label: string;
    reason?: string;
};

export type DoctorReport = {
    // ISO 8601, null before the first run.
    ran_at: string | null;
    ran_at_label: string;
    ran_ago: string;
    // Checks that apply to this store.
    total: number;
    // Checks that ran: "We checked N things".
    checked: number;
    findings: Finding[];
    working: CheckLine[];
    skipped: CheckLine[];
    new: number;
};

const PATH = `${ NAMESPACE }/doctor`;

/**
 * The last report; nothing runs.
 */
export const fetchDoctor = (): Promise< DoctorReport > =>
    apiFetch< DoctorReport >( { path: PATH } );

/**
 * Run every check, or only some.
 *
 * @param {string[]} checks Check IDs; empty for all.
 */
export const runDoctor = ( checks: string[] = [] ): Promise< DoctorReport > =>
    apiFetch< DoctorReport >( {
        path: PATH,
        method: 'POST',
        data: { checks },
    } );

/**
 * Dismiss a finding, or bring it back.
 *
 * @param {string}  hash      Finding hash.
 * @param {boolean} dismissed False for Undo.
 */
export const dismissFinding = (
    hash: string,
    dismissed = true
): Promise< DoctorReport > =>
    apiFetch< DoctorReport >( {
        path: `${ PATH }/dismiss`,
        method: 'POST',
        data: { hash, dismissed },
    } );

/**
 * The merchant has seen the new findings: clear the dashboard badge.
 */
export const markDoctorSeen = (): Promise< DoctorReport > =>
    apiFetch< DoctorReport >( { path: `${ PATH }/seen`, method: 'POST' } );

/**
 * Open findings worth a look: not dismissed, not good-to-know notes.
 *
 * @param {DoctorReport} report Report.
 */
export const openProblems = ( report: DoctorReport ): Finding[] =>
    report.findings.filter(
        ( finding ) => ! finding.dismissed && finding.severity === 'warning'
    );
