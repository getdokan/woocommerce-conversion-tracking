/**
 * Data printed for the admin app by `StorePulse\ConversionTracking\Admin\Assets`.
 */
type WcctDocsTile = {
    id: 'meta' | 'google' | 'x' | 'microsoft' | 'custom' | 'catalog';
    title: string;
    url: string;
    icon: string;
    pro: boolean;
};

type WcctAdminData = {
    version: string;
    proVersion: string;
    docs: {
        home: string;
        tiles: WcctDocsTile[];
    };
};

interface Window {
    wcctAdmin?: WcctAdminData;
}

declare module '*.css';
