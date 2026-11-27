/**
 * External dependencies
 */
import { Input, Label, Notice, Switch } from '@wedevs/plugin-ui';

/**
 * WordPress dependencies
 */
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { CatalogLogin } from '../../data/catalog';

export type LoginDraft = {
    enabled: boolean;
    username: string;
    // Typed here only; never filled from the server.
    password: string;
};

export type LoginErrors = {
    username?: string;
    password?: string;
    general?: string;
};

export type FeedLoginProps = {
    saved: CatalogLogin;
    canEdit: boolean;
    draft: LoginDraft;
    errors: LoginErrors;
    onChange: ( next: Partial< LoginDraft > ) => void;
};

/**
 * Whether a usable password is stored or set in wp-config.php.
 *
 * @param {CatalogLogin} saved Saved login state.
 */
export const hasUsablePassword = ( saved: CatalogLogin ): boolean =>
    saved.password_overridden ||
    ( saved.password_set && ! saved.password_unreadable );

/**
 * Field errors for a login draft, before it is sent.
 *
 * @param {LoginDraft}   draft Login draft.
 * @param {CatalogLogin} saved Saved login state.
 */
export const validateLogin = (
    draft: LoginDraft,
    saved: CatalogLogin
): LoginErrors => {
    const errors: LoginErrors = {};

    if ( draft.username.includes( ':' ) ) {
        errors.username = __(
            "The username can't contain a colon.",
            'woocommerce-conversion-tracking'
        );
    }

    if ( ! draft.enabled ) {
        return errors;
    }

    if ( ! draft.username.trim() ) {
        errors.username = __(
            'Enter a username to turn on the feed login.',
            'woocommerce-conversion-tracking'
        );
    }

    if ( ! draft.password && ! hasUsablePassword( saved ) ) {
        errors.password = __(
            'Enter a password to turn on the feed login.',
            'woocommerce-conversion-tracking'
        );
    }

    return errors;
};

/**
 * The optional feed login: off by default, administrators only, the password is never shown.
 *
 * @param {FeedLoginProps} props Component props.
 */
export const FeedLogin = ( {
    saved,
    canEdit,
    draft,
    errors,
    onChange,
}: FeedLoginProps ) => {
    const passwordHint = () => {
        if ( saved.password_overridden ) {
            return __(
                'Set in wp-config.php with WCCT_SECRET_FEED_PASSWORD, so it is changed there.',
                'woocommerce-conversion-tracking'
            );
        }

        if ( saved.password_set && ! saved.password_unreadable ) {
            return __(
                'A password is saved. Leave this empty to keep it.',
                'woocommerce-conversion-tracking'
            );
        }

        return __(
            'Use the same password in Commerce Manager.',
            'woocommerce-conversion-tracking'
        );
    };

    return (
        <div
            className="flex flex-col gap-4 rounded-lg border border-border p-4"
            data-testid="wcct-feed-login"
        >
            <div className="flex items-start justify-between gap-4">
                <div className="flex flex-col gap-1">
                    <span
                        id="wcct-feed-login-title"
                        className="text-sm font-medium text-foreground"
                    >
                        { __(
                            'Ask for a login',
                            'woocommerce-conversion-tracking'
                        ) }
                    </span>
                    <span
                        id="wcct-feed-login-desc"
                        className="text-[13px] text-muted-foreground"
                    >
                        { draft.enabled
                            ? __(
                                  'On, so Meta needs the same username and password in Commerce Manager, or it stops updating your catalog.',
                                  'woocommerce-conversion-tracking'
                              )
                            : __(
                                  'Off, so Meta keeps fetching the URL as it is. If you turn it on, add the same username and password in Commerce Manager, or Meta stops updating your catalog.',
                                  'woocommerce-conversion-tracking'
                              ) }
                    </span>
                </div>
                <Switch
                    id="wcct-feed-login"
                    checked={ draft.enabled }
                    disabled={ ! canEdit }
                    onCheckedChange={ ( enabled ) => onChange( { enabled } ) }
                    aria-labelledby="wcct-feed-login-title"
                    aria-describedby={
                        canEdit
                            ? 'wcct-feed-login-desc'
                            : 'wcct-feed-login-desc wcct-feed-login-admin'
                    }
                />
            </div>

            { ! canEdit && (
                <p
                    id="wcct-feed-login-admin"
                    className="m-0 text-[13px] text-muted-foreground"
                >
                    { saved.enabled && saved.username
                        ? sprintf(
                              /* translators: %s: feed username. */
                              __(
                                  'The feed asks for a login with the username %s. Only administrators can change it.',
                                  'woocommerce-conversion-tracking'
                              ),
                              saved.username
                          )
                        : __(
                              'Only administrators can change the feed login.',
                              'woocommerce-conversion-tracking'
                          ) }
                </p>
            ) }

            { canEdit && saved.password_unreadable && (
                <Notice variant="warning" role="status">
                    <p className="m-0">
                        { __(
                            "The saved feed password can't be read anymore, usually because the site's security keys changed. Meta can't fetch the feed until you enter the password again.",
                            'woocommerce-conversion-tracking'
                        ) }
                    </p>
                </Notice>
            ) }

            { canEdit && draft.enabled && (
                <div className="grid max-w-md gap-4">
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="wcct-feed-user">
                            { __(
                                'Feed username',
                                'woocommerce-conversion-tracking'
                            ) }
                        </Label>
                        <Input
                            id="wcct-feed-user"
                            autoComplete="off"
                            value={ draft.username }
                            onChange={ ( event ) =>
                                onChange( { username: event.target.value } )
                            }
                            aria-invalid={ !! errors.username }
                            aria-describedby={
                                errors.username
                                    ? 'wcct-feed-user-error'
                                    : undefined
                            }
                        />
                        { errors.username && (
                            <p
                                id="wcct-feed-user-error"
                                className="m-0 text-[13px] text-destructive"
                            >
                                { errors.username }
                            </p>
                        ) }
                    </div>
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="wcct-feed-pass">
                            { __(
                                'Feed password',
                                'woocommerce-conversion-tracking'
                            ) }
                        </Label>
                        <Input
                            id="wcct-feed-pass"
                            type="password"
                            autoComplete="new-password"
                            value={ draft.password }
                            disabled={ saved.password_overridden }
                            onChange={ ( event ) =>
                                onChange( { password: event.target.value } )
                            }
                            aria-invalid={ !! errors.password }
                            aria-describedby={
                                errors.password
                                    ? 'wcct-feed-pass-error wcct-feed-pass-hint'
                                    : 'wcct-feed-pass-hint'
                            }
                        />
                        { errors.password && (
                            <p
                                id="wcct-feed-pass-error"
                                className="m-0 text-[13px] text-destructive"
                            >
                                { errors.password }
                            </p>
                        ) }
                        <p
                            id="wcct-feed-pass-hint"
                            className="m-0 text-[13px] text-muted-foreground"
                        >
                            { passwordHint() }
                        </p>
                    </div>
                </div>
            ) }

            { errors.general && (
                <Notice variant="destructive" role="alert">
                    <p className="m-0">{ errors.general }</p>
                </Notice>
            ) }
        </div>
    );
};
