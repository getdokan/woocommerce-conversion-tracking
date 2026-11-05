/**
 * External dependencies
 */
import {
    Button,
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@wedevs/plugin-ui';
import { BookOpen, CircleHelp, Lightbulb, MessageSquare } from 'lucide-react';

/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * The top bar's Help (?) menu: Docs, Support and Feature request.
 */
export const HelpMenu = () => {
    const help = window.wcctAdmin?.help;

    const items = [
        {
            id: 'docs',
            label: __( 'Docs', 'woocommerce-conversion-tracking' ),
            url: help?.docs ?? '',
            Icon: BookOpen,
        },
        {
            id: 'support',
            label: __( 'Support', 'woocommerce-conversion-tracking' ),
            url: help?.support ?? '',
            Icon: MessageSquare,
        },
        {
            id: 'feature-request',
            label: __( 'Feature request', 'woocommerce-conversion-tracking' ),
            url: help?.feature_request ?? '',
            Icon: Lightbulb,
        },
    ].filter( ( item ) => item.url );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                render={
                    <Button
                        variant="secondary"
                        size="icon"
                        className="rounded-full"
                        aria-label={ __(
                            'Help',
                            'woocommerce-conversion-tracking'
                        ) }
                    />
                }
            >
                <CircleHelp />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-45">
                { items.map( ( { id, label, url, Icon } ) => (
                    <DropdownMenuItem
                        key={ id }
                        render={
                            // eslint-disable-next-line jsx-a11y/anchor-has-content -- the item renders the label inside.
                            <a href={ url } target="_blank" rel="noreferrer" />
                        }
                        className="cursor-pointer text-popover-foreground no-underline"
                    >
                        <Icon
                            className="text-muted-foreground"
                            aria-hidden="true"
                        />
                        { label }
                        <span className="sr-only">
                            { __(
                                '(opens in a new tab)',
                                'woocommerce-conversion-tracking'
                            ) }
                        </span>
                    </DropdownMenuItem>
                ) ) }
            </DropdownMenuContent>
        </DropdownMenu>
    );
};
