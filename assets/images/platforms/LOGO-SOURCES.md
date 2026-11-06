# Platform logo sources

All files are 1x SVG. They are used only to identify the platform the store connects to (referential use), at 24 px in lists and 40 px in detail headers, in their original colours on white. The "Not set up" state shows them in greyscale with CSS, not as separate files.

The files were fetched for the CT 3.0 prototype (task 3.1) and copied unchanged from https://ct3-proto.vercel.app/assets/logos/ on 2026-11-05 (task 0.4).

| File | Mark | Source URL | Fetched | Licence / use note |
|---|---|---|---|---|
| `google-analytics.svg` | GA4 bars mark | https://www.gstatic.com/analytics-suite/header/suite/v2/ic_analytics.svg | 2026-10-23 | Google trademark. [GA branding policy](https://developers.google.com/analytics/terms/branding-policy): do not alter, do not make it the most prominent element. Byte-identical to the source. |
| `google-ads.svg` | Google Ads three-bar triangle | https://www.gstatic.com/images/branding/productlogos/ads/v5/192px.svg (linked from the source of https://ads.google.com/home/) | 2026-10-23 | Google trademark, [Google Ads brand guidelines](https://support.google.com/google-ads/answer/14934472). Byte-identical to the source. |
| `x.svg` | X mark (2023) | Inline SVG in the header of https://about.x.com/en/who-we-are/brand-toolkit (class `u01b__icon-home`) | 2026-10-23 | X Corp. trademark, brand toolkit rules. Path unchanged; only `fill="#000"` added (the page sets the colour by CSS). Label it "X (formerly Twitter)". |
| `microsoft.svg` | Microsoft four-square symbol | The four square paths of the Microsoft logo embedded as a CSS data URI on https://about.ads.microsoft.com/en (Microsoft Advertising site header) | 2026-10-23 | Microsoft trademark. [Microsoft trademark guidelines](https://www.microsoft.com/en-us/legal/intellectualproperty/trademarks/usage/general.aspx) allow the symbol for referential use; the Microsoft Advertising product logo needs a licence, so use the symbol plus the text label "Microsoft Advertising". Paths unchanged, `viewBox` cropped to the symbol. |
| `meta.svg` | **Placeholder**, not the official file | Hand-drawn infinity in Meta blue `#0081FB` from the prototype mock-up | 2026-10-23 | **Not the official mark.** The official file is in `Meta_Company-Lockup.zip` on https://www.meta.com/brand/resources/meta/company-brand/, behind a terms-of-use acceptance. To replace: accept the terms on that page, take the blue symbol-only SVG from the zip, save it here as `meta.svg`, and update this row. |
| `custom.svg` | lucide `code-xml` | https://lucide.dev/icons/code-xml | 2026-10-23 | ISC licence (lucide). Stroke colour is the `--tone-off-fg` value. |

`../twitter.png` (the 2.x settings-page icon, kept under the integration id) is `x.svg` rendered white on a 56 px black circle with `rsvg-convert` (task 3.6b).
