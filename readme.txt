=== Conversion Tracking for WooCommerce ===
Contributors: tareq1988, wedevs
Tags: ecommerce, e-commerce, commerce, woocommerce, tracking, facebook, google, adwords, tracking-pixel
Donate link: https://tareq.co/donate/
Requires at least: 5.4
Tested up to: 6.8.2
Requires PHP: 7.4
WC requires at least: 5.0.0
WC tested up to: 10.1.1
Stable tag: 2.1.4
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Adds various conversion tracking codes to cart, checkout, registration success and product page on WooCommerce

== Description ==

When you are integrating any advertising campaigns, they provide various tracking codes (mainly JavaScript) to insert them various pages of your site so that it can track how the conversion is happening.

This plugin inserts those codes on WooCommerce cart page, checkout success page and after user registration. So you can track who are adding your products to cart, who are buying them and who are registering to your site.

= Supported Integrations =

 * [Facebook](https://wedevs.com/docs/woocommerce-conversion-tracking/facebook/?utm_source=wporg&utm_medium=Readme&utm_campaign=wcct-lite&utm_content=facebook)
 * [Twitter](https://wedevs.com/docs/woocommerce-conversion-tracking/twitter/?utm_source=wporg&utm_medium=Readme&utm_campaign=wcct-lite&utm_content=twitter)
 * [Google Adwords](https://wedevs.com/docs/woocommerce-conversion-tracking/google-adwords/?utm_source=wporg&utm_medium=Readme&utm_campaign=wcct-lite&utm_content=google_adwords)
 * [Custom Tracking](https://wedevs.com/docs/woocommerce-conversion-tracking/custom/?utm_source=wporg&utm_medium=Readme&utm_campaign=wcct-lite&utm_content=custom)

= Pro Features =

 * More Facebook Events
 * Multiple Facebook Pixels
 * [Facebook Product Catalog](https://wedevs.com/docs/woocommerce-conversion-tracking/facebook/facebook-product-catalog/?utm_source=wporg&utm_medium=Readme&utm_campaign=wcct-lite&utm_content=product_catalog)
 * [Perfect Audience](https://wedevs.com/docs/woocommerce-conversion-tracking/perfect-audience/?utm_source=wporg&utm_medium=Readme&utm_campaign=wcct-lite&utm_content=perfect_audience)
 * [Bing Ads](https://wedevs.com/docs/woocommerce-conversion-tracking/bing-ads)
 * More Twitter and Google Adwords Events

[**Get Pro Version**](https://wedevs.com/woocommerce-conversion-tracking/pricing/?utm_source=wporg&utm_medium=Readme&utm_campaign=wcct-lite&utm_content=pricing)

= Videos =
[youtube http://www.youtube.com/watch?v=PZN883xb51c]
[youtube http://www.youtube.com/watch?v=6QMWzM9decU]

[**All Videos**](https://www.youtube.com/watch?v=b3BHJwQ7Q70&list=PLJorZsV2RVv_7zV2I1_X_xJODZklXHQtS)

= Contribute =
[Github](https://github.com/tareq1988/woocommerce-conversion-tracking)

= Author =
[Tareq Hasan](https://tareq.co)

== Installation ==

Extract the zip file and just drop the contents in the wp-content/plugins/ directory of your WordPress installation and then activate the Plugin from Plugins page.

= Minimum Requirements =

* WooCommerce 3.0
* PHP version 7.4 or greater
* MySQL version 5.0 or greater

= Tracking Conversions =

If you need to track successful purchases, go to the settings and in the **Tags for Successful Order** box, place the JavaScript code you received from the services (e.g. Facebook, Twitter, AdWords) you want to use.

In the JavaScript snippet, there are places where you can use `order_number`, `order_total`, `order_subtotal`, `currency`, etc.

The plugin provides the following codes: <code>{customer_id}</code>, <code>{customer_email}</code>, <code>{customer_first_name}</code>, <code>{customer_last_name}</code>, <code>{order_number}</code>, <code>{order_total}</code>, <code>{order_subtotal}</code>, <code>{currency}</code>, <code>{payment_method}</code>.

Replace the script tag values with the suitable codes the plugin provides and finally insert the snippet in the boxes.

== Frequently Asked Questions ==

= Does it work with WooCommerce 2.x and 3.x? =

Yes, the plugin works both with the latest v3.x and the older 2.x versions.

= How conversions are tracked? =

We have three different ways of tracking a conversion. The most used one is when a order has been made.

We put the JavaScript scripts provided by you in the page and it fires a conversion event to that scripts site.


== Screenshots ==

1. Settings Panel
2. Facebook Pixel Setup
3. Google Adwords Conversion Tracking Settings
4. Twitter Purchase Tracking Setup
5. Custom Codes

## Privacy Policy
woocommerce-conversion-tracking uses [Appsero](https://appsero.com) SDK to collect some telemetry data upon user's confirmation. This helps us to troubleshoot problems faster & make product improvements.

Appsero SDK **does not gather any data by default.** The SDK only starts gathering basic telemetry data **when a user allows it via the admin notice**. We collect the data to ensure great user experience for all our users.

Integrating Appsero SDK **DOES NOT IMMEDIATELY** start gathering data, **without confirmation from users in any case.**

Learn more how [Appsero collects and uses this data](https://appsero.com/privacy-policy/).

Additionally, read weDevs [privacy policy](https://wedevs.com/privacy-policy/) for more.


== Changelog ==

= Version 2.1.4 (13 Oct, 2025) =

- **Update:** Update plugin name.

= Version 2.1.1 (26 Aug, 2025) =

- **fix:** Resolved "Constant WCCT_VERSION already defined" warning caused by multiple plugin initializations in Conversion Tracking for WooCommerce.
- **Update:** Added missing 'Requires PHP' header
- **Update:** WordPress 6.8.2 compatibility
- **chore:** Updated Appsero SDK to the latest version for improved stability and performance.
- **chore:** Refactored codebase with Mozart to ensure better dependency management and prevent conflicts with other plugins.

= Version 2.1.0 (09 Dec, 2024) =

- **Update:** Added support for High Performance Order Storage in WooCommerce
- **Update:** WordPress 6.7.1 compatibility

= Version 2.0.12 (16 Jan, 2024) =

- **Update:** WordPress 6.4.2 compatibility
- **Fix:** Updated Appsero Client Library to the latest version which will fix a security issue
- **Fix:** Fixed a Broken Access Control vulnerability in Conversion Tracking for WooCommerce Plugin that allowed lower privileged users to install and activate a new plugin via an ajax action without a nonce parameter.

= Version 2.0.11 (15 Sep, 2023) =

- **Update:** WordPress 6.3.1 compatibility
- **Fix:** Updated Appsero Client Library to the latest version which will fix a security issue
- **Update:** Added HPOS (High Performance Order Storage) support

= Version 2.0.10 (2 June, 2022) =

 * **Fix:** WordPress 6.0 compatibility

= Version 2.0.9 (6 October, 2021) =

 * **Fix:** Perfect Audience breaking checkbox section issue.
 * **Fix:** WordPress 5.8 compatibility
 * **Fix:** Latest WooCommerce 5.7 compatibility

= Version 2.0.8 (10 June, 2020) =

 * **Fix:** Dismissable HA notice, was introduced in v2.0.7.
 * **Fix:** Removed discount notice as it was deprecated.

= Version 2.0.7 (2 May, 2020) =

 * **Fix:** Removed unnecessary usage of `wp_kses_post()`, which was breaking the plugin.
 * **Improved:** Added WordPress 5.4 compatibility.
 * **Improved:** Added WooCommerce 4.0 compatibility.
 * **Improved:** Removed the v2.0 welcome notice, which isn't relevant anymore.

= Version 2.0.6 (02-January-2020) =

* [fix] Missing  ajax addToCart value of facebook
* [fix] php-cs-fixer: Sanitization and nonce verification

= Version 2.0.4 (21-August-2019) =

* [fix] Happyaddons dismissable banner
* [fix] Getting started link doesn't work
* [tweak] Change from Google Adwords to Google Ads

= Version 2.0.3 (05-August-2019) =
* [fix] AddToCart Event doesn't work for ajax
* [new] Add happy addons banner

= Version 2.0.2 (29-April-2019) =

* [fix] Missing value of product price

= Version 2.0.1 (04-April-2019) =

 * [fix] Added plugin require notice


= Version 2.0 (22-February-2018) =

 * Major version released
 * Individual gateway integrations
 * More streamlined and user friendly UI


= Version 1.2.5 (28-December-2017) =

 * Added plugin action links for Docs and Settings.
 * Added a survey for next version features.
 * Updated WooCommerce compatibility version tags.

= 1.2.4 - 01-Aug-2017 =

 * [fix] The tracker option won't go away. Sorry!

= 1.2.3 - 31-July-2017 =

 * [improvement] WooCommerce v3.0 compatibility
 * [new] Added new shortcodes: `{customer_id}`, `{customer_email}`, `{customer_first_name}`, `{customer_last_name}`, `{payment_method}`
 * [new] Added weDevs Insights class

= 1.2.2 - 10-Feb-2017 =

* Updated with plugin slug as the textdomain
* Integration class PHP compatibility

= 1.2.1 - 9-June-2016 =

* [fix] Fatal error on thank you page when without parameters

= 1.2 - 10-April-2016 =

* [new] Order number variable `{order_number}` added on checkout page

= 1.1 - 08-Jan-2016 =

* [new] Dynamic values on product and checkout script

= 1.0 - 08/07/2015 =

* [fix] Removed product specific codes from product single page, should show only on checkout. My bad!

= 0.3 - 31/05/2015 =

* [fix] Product specific code only loads on product page itself, not checkout

= 0.2 - 30/05/2015 =

* Renamed position option "In Footer" to "Inside Body Tag", may be was confusing to users.

= 0.1.1 - 06/04/2014 =

* Added position parameter for display position control

= 0.1 - 27/03/2014 =

* Initial release

== Upgrade Notice ==

N/A
