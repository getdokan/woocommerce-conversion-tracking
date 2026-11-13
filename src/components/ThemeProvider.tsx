/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import {
    ThemeProvider as PluginUIThemeProvider,
    cn,
    type ThemeTokens,
} from '@wedevs/plugin-ui';

/**
 * The plugin-ui tokens CT overrides; every other token keeps plugin-ui's default.
 */
export const themeTokens: ThemeTokens = {
    primary: 'oklch(0.52 0.13 248)',
    primaryForeground: 'oklch(1 0 0)',
    ring: 'oklch(0.52 0.13 248)',
    success: 'oklch(0.696 0.17 162)',
};

export type ThemeProviderProps = {
    children: ReactNode;
    className?: string;
};

/**
 * plugin-ui ThemeProvider with the CT tokens and the `.wcct-app` scope.
 *
 * @param {ThemeProviderProps} props Component props.
 */
export const ThemeProvider = ( {
    children,
    className,
}: ThemeProviderProps ) => (
    // plugin-ui copies this class onto portaled popovers and dialogs, so they stay in scope.
    <PluginUIThemeProvider
        pluginId="wcct"
        tokens={ themeTokens }
        storageKey={ false }
        className={ cn( 'wcct-app', className ) }
    >
        { children }
    </PluginUIThemeProvider>
);
