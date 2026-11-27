/**
 * Shapes of the wcct/v1 settings and settings/schema responses.
 */

export type SchemaDependency = {
    key: string;
    value: unknown[];
    comparison?: string;
};

export type SchemaElement = {
    id: string;
    type: string;
    variant?: string;
    format?: string;
    section_id?: string;
    label?: string;
    default?: unknown;
    dependencies?: SchemaDependency[];
    [ key: string ]: unknown;
};

export type SchemaPlatform = {
    id: string;
    label: string;
    integrations: string[];
    pro: boolean;
    credentials: SchemaElement[];
    schema: SchemaElement[];
};

export type SettingsSchema = {
    global: SchemaElement[];
    platforms: SchemaPlatform[];
};

export type PlatformValues = Record< string, unknown >;

export type SettingsValues = {
    platforms: Record< string, PlatformValues >;
    [ key: string ]: unknown;
};

export type OnboardingStatus = 'new' | 'in_progress' | 'skipped' | 'done';

export type EuAnswer = '' | 'yes' | 'no';

// The wcct/v1/onboarding state: wizard progress, never settings.
export type OnboardingState = {
    status: OnboardingStatus;
    // Furthest step reached, 1 to 6; 0 before the wizard opens.
    step: number;
    platforms: string[];
    eu: EuAnswer;
};

export type AppData = {
    settings: SettingsValues;
    schema: SettingsSchema;
    onboarding: OnboardingState;
};
