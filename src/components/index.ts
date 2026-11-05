/**
 * Shared CT components, exposed as `window.wcct.components` (`@wcct/components`).
 */
import '../base-tailwind.css';

export { ThemeProvider, themeTokens } from './ThemeProvider';
export type { ThemeProviderProps } from './ThemeProvider';
export { Page } from './Page';
export type { PageProps } from './Page';
export { StatusPill } from './StatusPill';
export type { StatusPillProps, StatusTone } from './StatusPill';
export { ProBadge } from './ProBadge';
export { PlatformLogo } from './PlatformLogo';
export type { PlatformLogoProps } from './PlatformLogo';
