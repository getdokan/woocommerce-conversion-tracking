/**
 * The warning beside "Respect cookie choices" while no cookie plugin passes on the visitor's choice, from the settings schema (#135).
 */
import type { SchemaElement } from './types';

export type ConsentWarning = {
    title: string;
    message: string;
    link: { label: string; url: string } | null;
};

/**
 * The warning for a platform page, or for every platform at once ('').
 *
 * @param {SchemaElement|undefined} field    The consent_mode element of the schema.
 * @param {string}                  platform Platform ID; '' for all platforms.
 */
export const consentWarning = (
    field: SchemaElement | undefined,
    platform = ''
): ConsentWarning | null => {
    const raw = field?.warning as
        | {
              title?: unknown;
              messages?: Record< string, unknown >;
              link?: { label?: unknown; url?: unknown };
          }
        | null
        | undefined;
    const message = raw?.messages?.[ platform ];

    if (
        ! raw ||
        typeof raw.title !== 'string' ||
        typeof message !== 'string'
    ) {
        return null;
    }

    return {
        title: raw.title,
        message,
        link:
            typeof raw.link?.label === 'string' &&
            typeof raw.link?.url === 'string'
                ? { label: raw.link.label, url: raw.link.url }
                : null,
    };
};
