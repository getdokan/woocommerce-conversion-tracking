/**
 * External dependencies
 */
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    Button,
    Checkbox,
    FieldLegend,
    FieldSet,
    Label,
    Notice,
    NoticeTitle,
    Skeleton,
} from '@wedevs/plugin-ui';
import { ProBadge } from '@wcct/components';
import { Lock, Users } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
    fetchAccess,
    saveAccess,
    sortRoles,
    type AccessData,
    type AccessRole,
} from '../../data/access';
import { errorMessage } from '../../data/api';
import { isPro, upgradeUrl } from '../../data/plan';
import { NewTabNote } from '../../shell/NewTabNote';
import {
    FeatureList,
    LINK_CLASS,
    SectionBody,
    SectionCard,
    SectionFooter,
} from './SectionCard';

const DESCRIPTION = () =>
    __(
        'Let people other than administrators manage tracking, without giving them the rest of WordPress.',
        'woocommerce-conversion-tracking'
    );

/**
 * Free: what role access is, and the way to Pro.
 */
const LockedAccess = () => (
    <SectionCard
        id="access"
        icon={ <Users /> }
        title={
            <>
                { __(
                    'Integration access',
                    'woocommerce-conversion-tracking'
                ) }
                <ProBadge />
            </>
        }
        description={ DESCRIPTION() }
    >
        <SectionBody
            label={ __( 'What you get', 'woocommerce-conversion-tracking' ) }
            main={
                <>
                    <div className="flex flex-col items-start gap-2">
                        <span
                            className="inline-flex size-13 items-center justify-center rounded-full bg-muted text-muted-foreground"
                            aria-hidden="true"
                        >
                            <Lock className="size-5" />
                        </span>
                        <h3 className="m-0 mt-1 p-0 text-lg font-semibold text-foreground">
                            { __(
                                'Role access is part of Pro',
                                'woocommerce-conversion-tracking'
                            ) }
                        </h3>
                        <p className="m-0 text-sm text-muted-foreground">
                            { __(
                                'On Free, only administrators can open Conversion Tracking. Pro lets you pick any role on your site, so a shop manager, an editor or your agency can fix a pixel without full admin rights.',
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                    </div>
                    <FeatureList
                        items={ [
                            __(
                                'Every role on your site, including Shop manager and Dokan vendors',
                                'woocommerce-conversion-tracking'
                            ),
                            __(
                                'Access to tracking settings only, nothing else in WordPress',
                                'woocommerce-conversion-tracking'
                            ),
                            __(
                                'Administrators always keep access',
                                'woocommerce-conversion-tracking'
                            ),
                        ] }
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            nativeButton={ false }
                            render={
                                // eslint-disable-next-line jsx-a11y/anchor-has-content, jsx-a11y/no-redundant-roles -- the button renders the label; Base UI would make it role=button.
                                <a
                                    href={ upgradeUrl() }
                                    target="_blank"
                                    rel="noreferrer"
                                    role="link"
                                />
                            }
                        >
                            { __(
                                'Compare plans',
                                'woocommerce-conversion-tracking'
                            ) }
                            <NewTabNote />
                        </Button>
                        <Button
                            variant="ghost"
                            nativeButton={ false }
                            // WordPress's link colour would win otherwise.
                            className="text-foreground hover:text-foreground"
                            // eslint-disable-next-line jsx-a11y/anchor-has-content, jsx-a11y/no-redundant-roles -- the button renders the label; Base UI would make it role=button.
                            render={ <a href="#/dashboard" role="link" /> }
                        >
                            { __(
                                'Continue with Free',
                                'woocommerce-conversion-tracking'
                            ) }
                        </Button>
                    </div>
                </>
            }
            aside={
                <>
                    <h3 className="m-0 p-0 text-sm font-semibold text-foreground">
                        { __(
                            'Also in Pro',
                            'woocommerce-conversion-tracking'
                        ) }
                    </h3>
                    <FeatureList
                        items={ [
                            __(
                                'Microsoft Advertising',
                                'woocommerce-conversion-tracking'
                            ),
                            <a
                                key="catalog"
                                href="#/catalog"
                                className={ LINK_CLASS }
                            >
                                { __(
                                    'Meta catalog feed',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </a>,
                            __(
                                'More than one Meta pixel',
                                'woocommerce-conversion-tracking'
                            ),
                        ] }
                    />
                </>
            }
        />
    </SectionCard>
);

/**
 * Short notes under a role's name.
 *
 * @param {AccessRole} role Role.
 */
const roleNotes = ( role: AccessRole ): string[] => {
    const notes = [];

    if ( role.always ) {
        notes.push(
            __( 'Always has access.', 'woocommerce-conversion-tracking' )
        );
    } else if ( role.carried ) {
        notes.push(
            __(
                'Had access before the update.',
                'woocommerce-conversion-tracking'
            )
        );
    }

    if ( role.dokan_vendor ) {
        notes.push(
            __( 'Added by Dokan.', 'woocommerce-conversion-tracking' )
        );
    }

    return notes;
};

/**
 * Granted roles that can change; always-on roles aren't sent.
 *
 * @param {AccessRole[]} roles Roles from the route.
 */
const grantedIds = ( roles: AccessRole[] ): string[] =>
    roles
        .filter( ( role ) => role.granted && ! role.always )
        .map( ( role ) => role.id );

const sameSet = ( a: string[], b: string[] ) =>
    a.length === b.length && a.every( ( id ) => b.includes( id ) );

/**
 * Pro: the role list. Ticking a role asks first; only administrators can change it.
 *
 * @param {Object}     props        Component props.
 * @param {AccessData} props.access What the access route returned.
 */
const RoleAccess = ( { access }: { access: AccessData } ) => {
    const [ roles, setRoles ] = useState( () =>
        sortRoles( access.roles ?? [] )
    );
    const [ saved, setSaved ] = useState( () => grantedIds( roles ) );
    const [ draft, setDraft ] = useState( saved );
    const [ pending, setPending ] = useState< AccessRole | null >( null );
    const [ note, setNote ] = useState( '' );
    const [ justSaved, setJustSaved ] = useState( false );
    const [ saving, setSaving ] = useState( false );
    const [ error, setError ] = useState( '' );
    // Last role asked about: focus goes back to its box, and the closing dialog keeps its text.
    const asked = useRef< AccessRole | null >( null );
    const canEdit = !! access.can_edit;
    const dirty = ! sameSet( draft, saved );
    const vendorOn = roles.some(
        ( role ) =>
            role.dokan_vendor && ( role.always || draft.includes( role.id ) )
    );

    const toggle = ( role: AccessRole, checked: boolean ) => {
        if ( ! checked ) {
            setDraft( ( ids ) => ids.filter( ( id ) => id !== role.id ) );
            setNote( '' );
            setJustSaved( false );
            return;
        }

        // Not ticked until the dialog says yes.
        asked.current = role;
        setPending( role );
    };

    const give = () => {
        if ( ! pending ) {
            return;
        }

        setDraft( ( ids ) => [ ...ids, pending.id ] );
        setNote(
            sprintf(
                /* translators: %s: role name, e.g. Shop manager */
                __(
                    '%s can manage tracking once you save.',
                    'woocommerce-conversion-tracking'
                ),
                pending.name
            )
        );
        setJustSaved( false );
        setPending( null );
    };

    const save = () => {
        setSaving( true );
        setError( '' );

        saveAccess( draft )
            .then( ( next ) => {
                const nextRoles = sortRoles( next.roles ?? [] );
                const ids = grantedIds( nextRoles );

                setRoles( nextRoles );
                setSaved( ids );
                setDraft( ids );
                setNote( '' );
                setJustSaved( true );
            } )
            .catch( ( reason: unknown ) => setError( errorMessage( reason ) ) )
            .finally( () => setSaving( false ) );
    };

    const shown = pending ?? asked.current;
    let footNote = '';

    if ( dirty ) {
        footNote =
            note || __( 'Unsaved changes', 'woocommerce-conversion-tracking' );
    } else if ( justSaved ) {
        footNote = __( 'Saved just now', 'woocommerce-conversion-tracking' );
    }

    const footer = canEdit ? (
        <>
            <span
                className="text-[13px] text-muted-foreground"
                role="status"
                data-testid="wcct-access-status"
            >
                { footNote }
            </span>
            <Button
                className="ml-auto"
                disabled={ ! dirty || saving }
                onClick={ save }
            >
                { saving && __( 'Saving…', 'woocommerce-conversion-tracking' ) }
                { ! saving &&
                    ( dirty
                        ? __(
                              'Save changes',
                              'woocommerce-conversion-tracking'
                          )
                        : __( 'Saved', 'woocommerce-conversion-tracking' ) ) }
            </Button>
        </>
    ) : (
        <p className="m-0 text-[13px] text-muted-foreground">
            { __(
                'Only administrators can change who has access.',
                'woocommerce-conversion-tracking'
            ) }
        </p>
    );

    return (
        <>
            <SectionBody
                label={ __( 'Help', 'woocommerce-conversion-tracking' ) }
                main={
                    <>
                        { error && (
                            <Notice variant="destructive" role="alert">
                                <NoticeTitle>
                                    { __(
                                        'Access was not saved.',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </NoticeTitle>
                                <p className="m-0">{ error }</p>
                            </Notice>
                        ) }
                        <FieldSet className="m-0 gap-3 border-0 p-0">
                            <FieldLegend
                                variant="label"
                                className="mb-1 p-0 text-sm font-semibold text-foreground"
                            >
                                { __(
                                    'Roles that can manage tracking',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </FieldLegend>
                            { roles.map( ( role ) => {
                                const id = `wcct-role-${ role.id }`;
                                const notes = roleNotes( role );

                                return (
                                    <div
                                        key={ role.id }
                                        className="flex items-start gap-2.5"
                                    >
                                        <Checkbox
                                            id={ id }
                                            className="mt-0.5"
                                            checked={
                                                role.always ||
                                                draft.includes( role.id )
                                            }
                                            disabled={
                                                role.always || ! canEdit
                                            }
                                            aria-describedby={
                                                notes.length
                                                    ? `${ id }-note`
                                                    : undefined
                                            }
                                            onCheckedChange={ ( checked ) =>
                                                toggle( role, checked )
                                            }
                                        />
                                        <div className="flex flex-col gap-0.5">
                                            <Label
                                                htmlFor={ id }
                                                className="text-sm font-normal text-foreground"
                                            >
                                                { role.name }
                                            </Label>
                                            { notes.length > 0 && (
                                                <span
                                                    id={ `${ id }-note` }
                                                    className="text-xs text-muted-foreground"
                                                >
                                                    { notes.join( ' ' ) }
                                                </span>
                                            ) }
                                        </div>
                                    </div>
                                );
                            } ) }
                        </FieldSet>
                        { vendorOn && (
                            <Notice
                                variant="warning"
                                data-testid="wcct-access-vendor-warning"
                            >
                                <NoticeTitle>
                                    { __(
                                        'Dokan vendors can change tracking from outside wp-admin',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </NoticeTitle>
                                <p className="m-0">
                                    { __(
                                        "Dokan keeps vendors out of wp-admin, so they won't see Conversion Tracking. While logged in, they can still change your pixel IDs and other tracking settings through the WordPress REST API. Give a vendor role access only if you trust every vendor with where your sales data goes.",
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </p>
                            </Notice>
                        ) }
                        <p className="m-0 text-[13px] text-muted-foreground">
                            { access.upgrade
                                ? __(
                                      "The roles that had access before the update still have it. People with a ticked role see Conversion Tracking under WooCommerce and can change every setting in it. They can't install plugins or edit other parts of your site.",
                                      'woocommerce-conversion-tracking'
                                  )
                                : __(
                                      "Only administrators have access until you tick a role. People with a ticked role see Conversion Tracking under WooCommerce and can change every setting in it. They can't install plugins or edit other parts of your site.",
                                      'woocommerce-conversion-tracking'
                                  ) }
                        </p>
                    </>
                }
                aside={
                    <>
                        <h3 className="m-0 p-0 text-sm font-semibold text-foreground">
                            { __(
                                'Who should get access',
                                'woocommerce-conversion-tracking'
                            ) }
                        </h3>
                        <p className="m-0 text-sm text-foreground">
                            { __(
                                'Give it to the person who runs your ads. Most stores add Shop manager. Customer, Subscriber and Vendor are listed because they are roles on your site, not because they need it.',
                                'woocommerce-conversion-tracking'
                            ) }
                        </p>
                    </>
                }
            />
            <AlertDialog
                open={ !! pending }
                onOpenChange={ ( open ) => ! open && setPending( null ) }
            >
                <AlertDialogContent
                    data-testid="wcct-role-dialog"
                    finalFocus={ () =>
                        document.getElementById(
                            `wcct-role-${ asked.current?.id ?? '' }`
                        )
                    }
                >
                    <AlertDialogHeader>
                        <AlertDialogTitle className="m-0 p-0 text-lg font-semibold">
                            { sprintf(
                                /* translators: %s: role name, e.g. Editor */
                                __(
                                    'Give %s access to Conversion Tracking?',
                                    'woocommerce-conversion-tracking'
                                ),
                                shown?.name ?? ''
                            ) }
                        </AlertDialogTitle>
                        <AlertDialogDescription
                            render={ <div /> }
                            className="flex flex-col gap-3"
                        >
                            <p className="m-0">
                                { __(
                                    'People with this role can change your pixel IDs, events and every other tracking setting, so they decide where your sales data goes. Custom scripts stay with administrators. Only give access to people you trust with your store.',
                                    'woocommerce-conversion-tracking'
                                ) }
                            </p>
                            { shown?.outside && (
                                <p className="m-0">
                                    { __(
                                        'This role usually belongs to people outside your business.',
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </p>
                            ) }
                            { shown?.dokan_vendor && (
                                <p className="m-0">
                                    { __(
                                        "Dokan keeps vendors out of wp-admin, but they can still change tracking settings through the WordPress REST API while they're logged in.",
                                        'woocommerce-conversion-tracking'
                                    ) }
                                </p>
                            ) }
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel variant="secondary">
                            { __(
                                'Cancel',
                                'woocommerce-conversion-tracking'
                            ) }
                        </AlertDialogCancel>
                        <AlertDialogAction onClick={ give }>
                            { __(
                                'Give access',
                                'woocommerce-conversion-tracking'
                            ) }
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            <SectionFooter>{ footer }</SectionFooter>
        </>
    );
};

/**
 * Skeleton while the role list loads.
 */
const AccessSkeleton = () => (
    <div
        className="flex flex-col gap-3 p-6"
        data-testid="wcct-access-skeleton"
        aria-hidden="true"
    >
        { [ 0, 1, 2, 3, 4 ].map( ( key ) => (
            <Skeleton key={ key } className="h-4 w-48" />
        ) ) }
    </div>
);

/**
 * Settings > Integration access: a locked preview on Free, the role list on Pro.
 */
export const AccessSection = () => {
    const pro = isPro();
    const [ access, setAccess ] = useState< AccessData | null >( null );
    const [ error, setError ] = useState( '' );

    const load = useCallback( () => {
        setError( '' );
        setAccess( null );

        fetchAccess()
            .then( setAccess )
            .catch( ( reason: unknown ) => setError( errorMessage( reason ) ) );
    }, [] );

    useEffect( () => {
        if ( pro ) {
            load();
        }
    }, [ pro, load ] );

    if ( ! pro || access?.locked ) {
        return <LockedAccess />;
    }

    let body = <AccessSkeleton />;

    if ( access ) {
        body = <RoleAccess access={ access } />;
    } else if ( error ) {
        body = (
            <div className="p-6">
                <Notice
                    variant="destructive"
                    role="alert"
                    data-testid="wcct-load-error"
                >
                    <NoticeTitle>
                        { __(
                            'Could not load who has access.',
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
            </div>
        );
    }

    // One card for loading, error and the list, so the focused heading survives the fetch.
    return (
        <SectionCard
            id="access"
            icon={ <Users /> }
            title={ __(
                'Integration access',
                'woocommerce-conversion-tracking'
            ) }
            description={ DESCRIPTION() }
        >
            { body }
        </SectionCard>
    );
};
