/**
 * Data printed for the admin app by `StorePulse\ConversionTracking\Admin\Assets`.
 */
type WcctAdminData = {
    version: string;
    proVersion: string;
};

interface Window {
    wcctAdmin?: WcctAdminData;
}

declare module '*.css';
