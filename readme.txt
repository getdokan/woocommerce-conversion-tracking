=== Conversion Tracking for WooCommerce ===
Contributors: tareq1988, wedevs
Tags: woocommerce, conversion tracking, tracking pixel, analytics, ecommerce
Donate link: https://tareq.co/donate/
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.5.0
WC tested up to: 11.1
Stable tag: 2.1.6
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Send WooCommerce purchases and shopper events to Meta, Google Analytics 4, Google Ads and X, each purchase counted once, plus your own scripts.

== Description ==

Conversion Tracking for WooCommerce adds the tracking tags of your ad and analytics platforms to your store and sends them what shoppers do: product views, add to cart, checkout, sign-ups and purchases. You set each platform up from one screen under WooCommerce, without editing your theme or pasting code.

= What it does =

* **Each purchase counted once.** A purchase is sent once per order to each platform, even if the shopper reloads the thank-you page. Failed and cancelled orders are skipped.
* **Classic and block checkout.** Events work the same on both.
* **Respects cookie choices.** Turn on "Respect cookie choices" and tags wait for the visitor's consent: Google Consent Mode v2 for Google, the WP Consent API for the other platforms.
* **Customer data stays out of the page.** Matching data is hashed before it's sent to the platform, and the order key is removed from every address sent.
* **See that it works.** The dashboard and the Activity log show the last 24 hours of purchases and whether each platform received them.
* **Tracking Doctor.** Checks your store for common setup problems, such as another plugin already sending the same purchases or a wrongly formatted ID.

= Supported Integrations =

* **Meta:** the Meta Pixel for Facebook and Instagram ads
* **Google Analytics 4:** product views, add to cart, checkout and purchases
* **Google Ads:** purchase conversions for your campaigns
* **X:** the X Pixel for X ads
* **Custom script:** paste a tag for any other platform and choose where it runs (thank-you page, every page header or after sign-up), with placeholders for the order number, total, currency and more

Setup guides for every platform are in the [documentation](https://wedevs.com/docs/woocommerce-conversion-tracking/?utm_source=wporg&utm_medium=Readme&utm_campaign=wcct-lite&utm_content=docs).

= Pro Features =

[Conversion Tracking for WooCommerce Pro](https://woocommerce.com/products/woocommerce-conversion-tracking-pro/) adds:

* **Microsoft Advertising:** the UET tag for Microsoft ads
* **Multiple Meta pixels,** each with its own events
* **Meta catalog feed:** a product feed for Meta catalogs that matches the product IDs your pixel sends
* **Extra events:** View category and Add to wishlist on Meta, Registration on Google Ads and X
* **Richer matching:** Google Ads Enhanced conversions and custom parameters on Meta events
* **Role access:** choose which roles can manage tracking

Pro is sold on WooCommerce.com.

= Contribute =

Report bugs and send pull requests on [GitHub](https://github.com/getdokan/woocommerce-conversion-tracking).

= Author =

[Tareq Hasan](https://tareq.co)

== External services ==

The plugin connects to a third-party advertising or analytics service only after you turn on that platform and enter its ID. Nothing is sent to these services from the admin. When "Respect cookie choices" is on, a platform's tag waits for the visitor's consent before it loads.

= Meta Pixel =

Used to measure and optimise Meta (Facebook and Instagram) ads. When Meta is connected, the plugin loads the Meta Pixel script from connect.facebook.net on your store's pages and sends events to Meta at www.facebook.com. Meta receives the page address and referrer, with the order key removed, and the events you turn on: page views, product views, add to cart, checkout and purchases, with product IDs, names, categories, prices and quantities, the order number, value and currency, and an event ID. For logged-in shoppers and on the order confirmation page, matching data (email address, phone number, name, city, state, postcode and customer ID) is hashed before it's sent to the platform. Meta sets its own cookies and receives the visitor's IP address and browser details as part of the request.

Meta is provided by Meta Platforms, Inc.: [Business Tools Terms](https://www.facebook.com/legal/terms/businesstools), [Privacy Policy](https://www.facebook.com/privacy/policy/).

= Google Analytics 4 and Google Ads =

Used to measure store activity (Analytics) and count ad conversions (Ads). When Google is connected with a Measurement ID or an Ads conversion ID, the plugin loads Google's gtag.js from www.googletagmanager.com on your store's pages. Google Analytics receives the page address and referrer, with the order key removed, and the events you turn on: product views, add to cart, begin checkout, search and purchases. Event data contains product IDs, names, categories, prices, quantities, coupon codes, the order number, totals, tax, shipping and currency. Google Ads receives the purchase conversion with the order number, value and currency. No customer names, email addresses, phone numbers or addresses are sent by the free plugin. Google sets its own cookies and receives the visitor's IP address and browser details as part of the request.

Google Analytics and Google Ads are provided by Google LLC: [Google Analytics Terms of Service](https://marketingplatform.google.com/about/analytics/terms/us/), [Google Terms of Service](https://policies.google.com/terms), [Privacy Policy](https://policies.google.com/privacy).

= X Pixel =

Used to measure X ads. When X is connected, the plugin loads the X Pixel script from static.ads-twitter.com on your store's pages and sends the events you turn on to X: page views, add to cart, checkout and purchases, with product IDs, quantities, the order number, value and currency. X sets its own cookies and receives the visitor's IP address and browser details as part of the request.

X is provided by X Corp.: [Terms of Service](https://x.com/en/tos), [Privacy Policy](https://x.com/en/privacy).

= Custom scripts =

A custom script runs the code you paste, so it connects to whichever service that code calls, under that service's terms. Customer details (customer ID, email address and name) are filled into a script's placeholders only when you turn on "Include customer details in scripts", and only on a verified order confirmation page.

= Appsero (usage data and deactivation feedback) =

The plugin uses the [Appsero](https://appsero.com) SDK. It sends nothing until you act on it:

* **Usage data:** an admin notice asks whether you want to share usage data. If you click "Allow", the plugin sends a weekly report to api.appsero.com: the site address and name, the administrator's email address and name, the server's IP address (looked up through icanhazip.com), server, PHP and WordPress versions, the active theme, user counts, plugin counts and the plugin version. If you click "No thanks", only that choice is sent.
* **Deactivation feedback:** when you deactivate the plugin, a form asks why. "Skip & Deactivate" deactivates the plugin at once and sends nothing. "Submit & Deactivate" sends your answer together with the usage report above.

Appsero is provided by Appsero: [Privacy Policy](https://appsero.com/privacy-policy/). See also the weDevs [privacy policy](https://wedevs.com/privacy-policy/).

== Installation ==

1. In your WordPress admin, go to Plugins > Add New, search for "Conversion Tracking for WooCommerce", then install and activate it. Or upload the plugin zip from Plugins > Add New > Upload Plugin.
2. Go to WooCommerce > Conversion Tracking.
3. Connect each platform you use with its ID. The help next to each platform shows where to find it.
4. Place a test order and check the Activity log.

= Minimum Requirements =

* WordPress 7.0
* WooCommerce 8.5
* PHP 7.4

== Frequently Asked Questions ==

= Will a purchase be counted twice if the shopper reloads the thank-you page? =

No. Each purchase is sent once per order to each platform. Failed and cancelled orders are never sent. Orders waiting for payment (pending, on hold) are sent on the thank-you page.

= What order value is sent with a purchase? =

For Meta, Google Ads, X and Microsoft you choose: items only, items and shipping, or items, tax and shipping (the order total). New stores start with items only; stores updating from 2.x keep the order total. Items are the line items after discounts plus any fees (a negative fee lowers the value). Google Analytics always sends the line items after discounts, without fees, with tax and shipping as separate fields. With prices entered including tax, WooCommerce stores items without their tax, so "items only" is the price without tax. The value is always in the order's currency. Developers can change it with the `wcct_event_value` filter.

= Does it work with the block checkout? =

Yes. Events work the same on the classic and the block checkout.

= Does it work with cookie consent plugins? =

Yes. Turn on "Respect cookie choices" and tags wait for the visitor's consent. Google gets Consent Mode v2 signals; the other platforms read consent through the WP Consent API, which most consent plugins support. If your consent plugin already sends Google's consent signals (for example Complianz with consent mode, CookieYes connected to its web app, Cookiebot or Site Kit), the plugin leaves Google's consent to it and sets no defaults of its own.

= Can I add a platform that isn't listed? =

Yes. Use the Custom script page to paste that platform's tag and choose where it runs.

= Where do I buy or manage Pro? =

Pro is sold on [WooCommerce.com](https://woocommerce.com/products/woocommerce-conversion-tracking-pro/). Manage your subscription from your WooCommerce.com account.

= Can I deactivate the plugin without answering the feedback form? =

Yes. Click "Skip & Deactivate" and the plugin deactivates at once without sending anything.

= What is removed when I delete the plugin? =

Deactivating keeps everything. Deleting the plugin from the Plugins page removes its settings, logs and scheduled jobs on every site of the network. The note on each order of the platforms its purchase was sent to stays, so a reinstall never counts a purchase twice. While Conversion Tracking Pro is installed, Pro's settings and catalog feed stay until you delete Pro too. On a large multisite network, delete it with WP-CLI (`wp plugin uninstall woocommerce-conversion-tracking`) so the cleanup isn't cut short by a web request time limit.

= Is there text for my privacy policy? =

Yes. Go to Settings > Privacy > Policy Guide in WordPress for suggested text that names the platforms your store can send data to.

== Screenshots ==

1. Dashboard
2. Meta platform setup
3. Google Analytics and Google Ads setup
4. X platform setup
5. Custom script

== Changelog ==

= Version 2.1.6 (06 Oct, 2026) =

- **New:** Google Analytics 4 integration with view item, add to cart, begin checkout and purchase events
- **Fix:** Deferred plugin class and translation loading to avoid early textdomain notices
- **Update:** Improved data handling on the order received page
- **Update:** WordPress 7.1.2 and WooCommerce 11.1.2 compatibility

Earlier versions are listed in changelog.txt in the plugin folder.

== Upgrade Notice ==

N/A
