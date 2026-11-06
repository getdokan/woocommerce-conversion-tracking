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
    // Installed Pro version, also when it's paused.
    proVersion: string;
    // wp_localize_script sends booleans as '1' or ''.
    proActive: boolean | string;
    proUpdate: string;
    docs: {
        home: string;
        tiles: WcctDocsTile[];
    };
    help: {
        docs: string;
        support: string;
        feature_request: string;
    };
    // Every Upgrade and Manage subscription link (WooCommerce.com), from Admin\Upgrade.
    upgrade: {
        upgrade: string;
        manage: string;
    };
    logosUrl: string;
    classicUrl: string;
};

interface Window {
    wcctAdmin?: WcctAdminData;
}

declare module '*.css';
