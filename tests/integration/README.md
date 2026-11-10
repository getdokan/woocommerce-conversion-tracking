# Characterisation tests

PHPUnit tests that record what the plugin prints on each store page today, per integration, and fail when that output changes. They are the safety net for the 3.0 rebuild: a change that alters tracking output must fail a test on purpose, and the fixture is then updated in the same change, so the diff shows exactly what changed.

The pure unit tests in `tests/unit` are separate and need no WordPress.

## How it works

- `bootstrap.php` drops every table, installs WordPress with the WordPress test library's `install.php`, then builds the store in `setup/store.php` and `setup/orders.php`.
- Each test requests one page. `bin/run.php` runs the request in a new PHP process, the way a web server would: it sets the URL, cookies and logged-in user, loads WooCommerce and the plugin(s) as active plugins, runs `wp()` and WordPress's template loader with the classic test theme in `theme/wcct-test` (WooCommerce's own PHP templates and hooks). The request runs inside a database transaction that is rolled back, so every request sees the same store.
- `includes/class-recorder.php` wraps every hook callback defined in a plugin directory just before its hook runs, and records what it prints, the code it adds to WooCommerce's `wc_enqueue_js()` queue and the scripts it enqueues. Nothing about the plugin's classes is assumed, so a rebuild that keeps the output passes unchanged.
- Each recorded chunk is checked against the full page HTML, so the recording is what the browser receives.

## Run it

You need Docker and Node 22.13 or newer.

```sh
composer install                # PHPUnit, and Mozart's dependencies/
cd tests/integration
npm ci
npm run env:start               # wp-env store on port 8896, only for these tests
npm test                        # composer test inside wp-env: unit tests, then characterisation tests
npm run env:stop
cd ../.. && composer install --no-dev   # back to a release install
```

`composer test` runs `composer test:unit` and `composer test:integration`. Inside wp-env, `npm run test:integration` runs only the characterisation tests. Add PHPUnit arguments after `--`, for example `npx wp-env run cli --env-cwd=wp-content/plugins/woocommerce-conversion-tracking vendor/bin/phpunit -c phpunit.integration.xml.dist --filter 'ga4 / checkout'`.

**The suite deletes everything in the database it is pointed at.** wp-env's test environment here exists only for it.

Without Docker, point `WP_TESTS_DIR` at a WordPress test library (for example from `install-wp-tests.sh`, with its `wp-tests-config.php` set up for an empty database), `WC_DIR` at a WooCommerce plugin directory, and run `composer test`.

| Variable | Default | What it is |
| --- | --- | --- |
| `WP_TESTS_DIR` | set by wp-env | WordPress test library (`includes/install.php`, `wp-tests-config.php`) |
| `WC_DIR` | `woocommerce` next to the Lite directory | WooCommerce to load |
| `WCCT_LITE_DIR` | this checkout | Lite code to load, for example a 2.1.6 checkout |
| `WCCT_PRO_DIR` | not loaded | Pro code to load. The Lite fixtures are skipped when Pro is loaded |
| `WCCT_UPDATE_FIXTURES=1` | | Write the fixtures instead of comparing |
| `WCCT_CAPTURE_HTML=1` | | Write the captured pages (`tests/fixtures/html/`) from this run. Use it only against the released code |
| `WCCT_KEEP_HTML=1` | | Keep every full page of the run under `tests/.output/html/` |

### Against another version

To check the fixtures against other code, for example the released 2.1.6, map that checkout into wp-env in `.wp-env.override.json` (git-ignored) and set `WCCT_LITE_DIR`:

```json
{ "mappings": { "wcct-baseline/woocommerce-conversion-tracking": "/path/to/a/2.1.6/checkout" } }
```

```sh
npx wp-env start
npx wp-env run cli --env-cwd=wp-content/plugins/woocommerce-conversion-tracking env WCCT_LITE_DIR=/var/www/html/wcct-baseline/woocommerce-conversion-tracking WC_DIR=/var/www/html/wp-content/plugins/woocommerce vendor/bin/phpunit -c phpunit.integration.xml.dist
```

The checkout needs its Composer packages (`vendor/` and `dependencies/`).

### Pro

Pro's suite (Pro's `tests/`) runs Pro on top of Lite with this harness. Map Pro into this environment in `.wp-env.override.json`, restart, then `npm run test:pro`:

```json
{ "mappings": { "wp-content/plugins/wc-conversion-tracking-pro": "../../../wc-conversion-tracking-pro" } }
```

### Uninstall

`npm run test:uninstall` runs `uninstall/run.sh` in the same container (#121). It installs throwaway sites next to the test store (own table prefix, plugins folder and uploads folder, so the store is untouched), single site and a two-site network, activates WooCommerce, Free and Pro, seeds every option, transient, table, Action Scheduler group, cron hook, order/product/user meta and the feed folder (`uninstall/seed.php`), then deletes the plugins with `wp plugin uninstall`, one order and the other. `uninstall/check.php` checks that deleting Free while Pro is installed leaves Pro's data (and the reverse), and that after both nothing is left but the order sent flags (`_wcct_sent_*`, `_wcct_ga4_tracked`), in `wc_orders_meta` and `postmeta`. Without Pro mapped it deletes Free alone and expects nothing left.

### Old Pro (#125)

`Compat/OldProTest.php` loads `plugins/old-pro/`, a stand-in with Pro 1.1.4's bootstrap hooks whose admin and integrations throw if they load, before and after Lite. It checks that Lite's output on six pages still matches the `all/` fixtures, that the Update Pro notice shows, and that old Pro's catalog feed still answers. The real Pro 1.1.4 is checked by hand (see #125).

## The store

Fixtures are only valid for this store. Change it and every fixture has to be recorded again.

- **Stack:** WordPress and WooCommerce versions pinned in `.wp-env.json` (WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3), HPOS on, pretty permalinks (`/%postname%/`), the `wcct-test` classic theme.
- **Store options:** US store (`US:CA`) in USD with 2 decimals, no taxes, guest checkout and My account registration on, AJAX add to cart on, no redirect to the cart after adding, coming soon mode off.
- **Pages:** 1001 Shop, 1002 Cart (WooCommerce's cart block), 1003 Checkout (checkout block), 1004 Classic checkout (`[woocommerce_checkout]`), 1005 My account. The home page is the latest posts.
- **Products:** category Clothing with Accessories and Hoodies under it, created on fixed dates so search results keep their order. 2001 Beanie (simple, 18.00, Accessories), 2002 Hoodie (variable, Hoodies) with 2003 Blue (42.00) and 2004 Red (45.00), 2005 T-Shirt (simple, 25.00, Clothing). All descriptions contain "cotton".
- **Coupon:** 2501 `ctsave5`, 5.00 off the cart.
- **Customer:** user 501 `ctcustomer`, Sam Buyer, customer@example.com, billing address in Oakland, CA.
- **Orders:** 3001 guest order by Jane Doe (jane@example.com), 2 × Beanie and 1 × Hoodie Blue, coupon `ctsave5`, 5.00 flat rate shipping, cash on delivery, processing, total 78.00, key `wc_order_ctguest0001`. 3002 customer 501's order, 1 × Beanie, 5.00 shipping, processing, total 23.00, key `wc_order_ctcustomer01`. The thank-you page is viewed inside WooCommerce's email verification grace period, as right after checkout.
- **Tracking settings:** `includes/class-settings.php`, in the shape the settings page saves them, with fake IDs: Meta pixel `111111111111111`, Google Ads `AW-123456789` with label `CtPurchaseLabel`, GA4 `G-CTTEST1234`, X pixel `ct0x1` with Purchase event ID `tw-ct0x1-ctpur` (`WCCT_Test_Settings::twitter_v2()` is X as 2.x saved it, universal tag only), and Custom scripts that use every placeholder. Every event is on, including GA4 `search` and `sign_up`, which 3.0's settings page saves (#92, #87).

## Pages

`includes/class-pages.php`. Each page is requested once per integration (`facebook`, `adwords`, `ga4`, `twitter`, `custom`, each enabled alone) and once with all of them on (`all`).

| Page | Request |
| --- | --- |
| `home` | `/` |
| `shop` | `/shop/` |
| `category` | `/product-category/clothing/` |
| `search` | `/?s=cotton&post_type=product` |
| `product-simple` | `/product/beanie/` |
| `product-variable` | `/product/hoodie/` |
| `cart-after-add-to-cart` | `/cart/?add-to-cart=2001`, empty cart before (add to cart without AJAX) |
| `checkout-classic` | `/classic-checkout/`, cart from an earlier request (2 × Beanie, 1 × Hoodie Blue) |
| `checkout-block` | `/checkout/`, same cart |
| `order-received-classic` | order 3001 with its key, on the classic checkout page |
| `order-received-block` | order 3001 with its key, on the block checkout page |
| `order-received-no-key` | order 3001 without the key |
| `order-received-customer-logged-out` | order 3002 with its key, nobody logged in |
| `order-received-customer` | order 3002 with its key, customer 501 logged in |
| `my-account` | `/my-account/`, logged out (the registration form) |
| `registration` | `/my-account/`, customer 501 logged in with the sign-up flag (`_wcct_sign_up_pending` user meta) that `user_register` leaves: the new customer's first page view (`SignUpTest`) |

## Fixtures

`tests/fixtures/<integration>/<page>.html`, one per page and integration:

```
<!-- wcct-fixture: ga4/checkout-block -->
<!-- page: is_checkout -->              WordPress conditionals that were true, so a wrong page shows up
<!-- head -->                           printed during wp_head
<!-- body -->                           printed elsewhere in the page, for example on woocommerce_thankyou
<!-- footer -->                         printed during wp_footer
<!-- footer: wc_enqueue_js -->          added to WooCommerce's queued JavaScript, printed in the footer inside jQuery(function($) { … });
<!-- scripts -->                        scripts the plugin enqueued or added inline code to
```

Output is kept byte for byte, whitespace included, except: the store URL becomes `{home}`, random UUIDs become `{uuid}` and script versions become `{version}`.

### When output changes on purpose

Record the fixtures again and review the diff before committing it:

```sh
npm run fixtures:update
git diff tests/fixtures
```

A failing run also writes the actual output to `tests/.output/<integration>/<page>.html` (CI uploads it), so `diff tests/fixtures/x/y.html tests/.output/x/y.html` shows the change.

### Captured pages

`tests/fixtures/html/<page>.html` is the full storefront HTML of each page with every integration on (`all`), captured from the released 2.1.6 code in the store above, with the store URL replaced by `{home}`. The fixtures are the plugin's part of these pages. `test_output_is_in_the_page_captured_from_2_1_6` checks that today's output for each page is still in its captured page, so the captures can't drift from the fixtures. When output changes on purpose, a test class lists the pages that now differ in `captured_page_differences()` (their check is skipped), or markup removed from every page in `captured_page_removals()` (taken out of the captured pages before comparing).

Pages captured elsewhere, for example from a real store, can be put next to them and compared with `php bin/extract-baseline.php page.html [home URL]`. It prints the tracking output found in a saved page (the plugin's head block, the queued WooCommerce JavaScript, and other inline scripts that call a tracking function) in the fixture's layout.

## Recorded against

The fixtures and captured pages were recorded from the unmodified 2.1.6 code (store versions above), then recorded again where a change on this branch altered the output on purpose. Pro's fixtures are in Pro's `tests/fixtures`.

- **#69** (event pipeline): page events and add to cart are printed in one footer inline script per platform (`wcct-{platform}-events`, under `<!-- scripts -->`) instead of WooCommerce's `wc_enqueue_js()` queue; GA4's footer code moved there too, and the Custom scripts are printed as raw HTML right after the footer scripts (`wp_footer` priority 21) instead of on `woocommerce_thankyou`. The code itself is unchanged except: Meta `InitiateCheckout` now fires on block checkout (`facebook/checkout-block`, `all/checkout-block`), and GA4 no longer sends the purchase for a registered customer's order viewed while not logged in as that customer, where WooCommerce shows a login notice (`ga4/order-received-customer-logged-out`, `all/order-received-customer-logged-out`). Begin checkout is also skipped where WooCommerce shows its forced-login notice instead of the checkout form (no fixture covers it). On funnel or custom thank-you pages that fire `woocommerce_thankyou` off WooCommerce's order received page, the Purchase comes from that hook as before, for guest orders or the logged-in customer's own (`ThankyouFallbackTest`); WooCommerce's own pages are unchanged. Skipped order statuses and the sent flags apply only to GA4 until #83.
- **#70** (cache-safe add to cart): add to cart is no longer printed into any page. It is queued in the WooCommerce session and reaches the browser through the `wcct_events` cart fragment or the uncached `?wc-ajax=wcct_events` request, run by the `wcct-pending-events` footer script that every page with an add-to-cart platform now carries (`facebook/*`, `ga4/*`, `all/*`). Meta's `added_to_cart` jQuery listener left the head block (`facebook/*`, `all/*`; cut from the captured pages by `captured_page_removal_patterns()`), GA4's footer script no longer carries session adds or its own listener and is printed only on pages with a GA4 event, and the add to cart that `cart-after-add-to-cart` used to print in its footer is gone from it (`facebook/`, `ga4/`, `all/cart-after-add-to-cart`).
- **#91** (sign-up detector): the `registration` page is `/my-account/` for customer 501 with the `_wcct_sign_up_pending` flag instead of `?_wc_user_reg=true`; its sign-up output is unchanged (`RegistrationRedirectTest` now checks the redirect is left alone).
- **#83** (Purchase once per order) and **#95** (order value): on the thank-you pages that send a Purchase, each platform's Purchase is followed by the script that confirms it was handed to the platform (`window.wcctPurchaseSent`), which writes the order's sent flag: Meta once `fbevents.js` has run its queue, X once `uwt.js` has (both also wait for marketing consent when consent mode is on), Google Ads through `event_callback`, Custom right after the merchant's script. The values are unchanged: the store records itself as upgraded from 2.x (`wcct_install_type` = `upgrade` in `setup/store.php`), so Meta, Ads and X keep the order total, and GA4 sends the product line total without fees as before. Fixtures `*/order-received-classic`, `*/order-received-block` and `*/order-received-customer`; the captured-page check takes the Custom confirmation script out of the output (`captured_page_additions()`). `PurchaseOnceTest` checks reloads, failed, cancelled, pending and on-hold orders, the count-once cutoff and the confirmation endpoint; `OrderValueOutputTest` checks each platform's printed value per mode for orders with a fee, a negative fee and tax-inclusive prices, the new-install default and the order currency.
- **#89** (X on `twq('config')` and event IDs): the X base code is the current `uwt.js` loader plus `twq('config', <pixel ID>)`, which sends the page view; `twq('init')` and `twq('track', 'PageView')` are gone. The thank-you page sends `twq('event', <Purchase event ID>, { value, currency, conversion_id, contents })` instead of `twq('track', 'Purchase')`. Every `twitter/` and `all/` fixture changed.
- **#79** (order key out of platform URLs): on the order received page an inline script (`#wcct-order-url`) prints first in the head, keeps the order ID and key in `window.wcctOrder` and removes the order parameters from the address bar. GA4 and Google Ads configs get a cleaned `page_location`/`page_referrer`, and the Meta beacon cleans `dl`/`rl`, removing `key`, `order`, `order_id`, `order-pay`, `order_key` and any value with an order key (was `key` only, Google Ads not at all). Changed: every `order-received-*` fixture, every `adwords/`, `ga4/`, `facebook/` and `all/` fixture.
- **#78** (customer details setting): the Custom script's `{customer_id}`, `{customer_email}`, `{customer_first_name}` and `{customer_last_name}` render empty unless "Include customer details in scripts" (`platforms.custom.customer_details` in `wcct_settings_v3`, off by default) is on, and then only on the verified order received page of the logged-in customer's own order, with marketing consent when "Respect cookie choices" is on. 2.1.6 filled them for any registered customer's order and left them as literal placeholders on guest orders (`custom/` and `all/order-received-classic`, `order-received-block`, `order-received-customer`; their captured-page checks are skipped). `CustomerDetailsTest` covers the setting on.
- **#96** (Product ID sent): Meta `Purchase` sends each line's own product, so a variation sends its variation ID with `content_type: product` instead of its parent with `product_group` (`facebook/` and `all/order-received-classic` and `-block`: `[2001,2003]` instead of `[2001,2002]`). X's `contents` already used the variation ID since #89. The default rule is the product ID, so nothing else changed; the SKU rule, its fallback to the ID and the variation a product page opens on are covered by `ProductIdTest`.
- **#81, #82** (consent): with "Respect cookie choices" off (the default, option `wcct_consent_mode`) every fixture above is unchanged. `ConsentPageOutputTest` records the same store with it on, without a consent plugin, under `tests/fixtures/consent-on/<integration>/<page>.html` for the home, product, cart, block checkout and thank-you pages: the consent block (`<script id="wcct-consent-mode">`, Google's region-scoped default and the consent runtime) is the first head output, Meta revokes consent before `init`, `wcfbq` is held until consent, and X's `uwt.js` loads through the runtime instead of the base code.
- **#94** (Custom script placements): the Custom script reads three placements from the 3.0 settings (`platforms.custom.{thankyou,header,signup}_{enabled,script}`), thank-you and sign-up from the 2.x `checkout` and `registration` scripts while the 3.0 settings hold no keys for that placement; until the 2.x settings page is removed, its saves write the keys it owns through to existing 3.0 settings (`Settings\LegacySync`, on `update_option_wcct_settings`). The "Every page, in the header" script prints in the plugin's `wp_head` block; order and customer placeholders are empty there and in the sign-up script. `{order_id}` is the order number and `{used_coupons}` the coupon codes; every filled value is escaped for a JavaScript string, and placeholders are filled in one pass. The 2.x fixtures are unchanged. New fixtures `custom-placements/*` record every page with all three placements on and customer details allowed; `CustomPlacementsTest` checks the header on every page, the thank-you script once per order after the key and ownership checks, the sign-up script once per new account, and a 2.x settings page save after a 3.0 save (`jobs/custom-classic-save.php`).
- **#92** (Google as one platform) and **#87** (Google Registration): GA4 and Google Ads read the `google` platform through the settings layer (`wcct_settings_v3`, or the 2.x settings through `LegacyView` until the migration) and share one head block (`Platforms\GoogleTag`): one `gtag.js` loader (the Analytics ID first), `wcctGtag('js')` once, and one `config` per ID, with `window.gtag` defined only when no other code has. Both head script tags carry `data-no-optimize="1" data-cfasync="false"`. The Ads conversion calls `(window.wcctGtag || function () { … })(…)` instead of the global `gtag`, so it survives an optimiser moving the head block. GA4 sends `search` (with `search_term`, product searches only, also when `post_type` is a list) and `sign_up` (with `method: 'WooCommerce'`); `lite_all()` now switches both on. Changed: every `adwords/`, `ga4/` and `all/` fixture (the head block), plus the GA4 footer script on `ga4/` and `all/search` and `registration`, and the Google parts of `consent-on/adwords/*`, `consent-on/ga4/*` and `consent-on/all/*` (the Google consent default still prints first, at `wp_head` 5). `GoogleTagTest` covers the modes, `GT-` IDs, search, sign-up and the Ads conversion read from `wcct_settings_v3`.
- **#111** (check-in) and **#112** (order log): the Purchase confirmation (`window.wcctPurchaseSent`) now checks in on the public REST route `wcct/v1/events` instead of admin-ajax (`wcct_purchase_sent`, and GA4's own `wcct_ga4_purchase_sent`, both removed): with library loaded, consent state, event ID and, when the platform didn't take the Purchase within 10 seconds or the page was hidden first, a "not handed over" check-in too. The page prints the order key with each check-in (`window.wcctOrder` is the fallback on funnel pages); while the page is open the check-in uses `fetch` and retries once on admin-ajax (`action=wcct_events`) when the route answers 401, 403 or 404. GA4's footer script lost its `confirmSent` helper, so every GA4 page with a footer script changed. Fixtures: `*/order-received-classic`, `*/order-received-block`, `*/order-received-customer`, `ga4/` and `all/product-simple`, `product-variable`, `checkout-classic`, `checkout-block`. `PurchaseOnceTest` checks the route in the page and the `template_redirect` thank-you flag; `REST/EventsRouteTest` covers each rejection, the row, sent flag and last purchase a check-in writes, the throttle, the 24-hour prune, the table's keys and the Verify seen ping. With "Respect cookie choices" on, the check-in reports the platform's consent category (GA4 `statistics`, the others `marketing`), and the Custom thank-you page gets an unheld script that only arms the "not handed over" check-in, while its confirming call stays held with the merchant script (`consent-on/` and `custom-placements/order-received-*`, `ConsentPageOutputTest`, `CustomPlacementsTest`).
- **#103** (Pause tracking): no fixture changes. Pauses live in the `wcct_paused_platforms` option (platform IDs; `google` pauses GA4 and Ads). `PauseTest` checks that a paused Google prints nothing for Analytics or Ads (from `wcct_settings_v3` and from the 2.x settings), a paused Meta prints nothing (also no Purchase confirmation on the thank-you pages), a paused Custom script prints no placement, resuming a 2.x Meta that was switched off prints Meta again (`jobs/pause-resume-v1.php`: Resume switches a 2.x integration with an ID back on), and that an add to cart queued before a pause reaches the browser without the paused platform's code (`jobs/pause-pending-add-to-cart.php`). `REST/SettingsRouteTest` covers `POST wcct/v1/settings/pause` and the page cache purge after every settings save and every pause or resume.
- **#122** (settings migration): every request in these tests seeds only `wcct_settings`, so the migration runs first and the page is printed from the `wcct_settings_v3` it writes; no fixture changes. Integrations 2.x had switched off with an ID saved are added to the pauses (`Platforms\Pause`, `wcct_paused_platforms`: facebook, google, or ga4 / adwords alone, twitter, bing_ads, custom), so Resume brings them back; a later 2.x settings save re-reads only the integrations whose switch or ID it changed; Google's mode follows its saved IDs. `MigrationTest` checks what the migration writes (the 2.x option byte for byte, the snapshot, the flags, the pauses, the marker), the lock, Retry, Resume after the migration, the 2.x settings page with malformed settings, and that the 2.x settings fixtures in `tests/fixtures/settings-2x.php` print the same before and after it.
- **#77, #84, #86, #93** (Meta): every Meta event goes through `wcfbq(pixel, event, params, event_id)`, which calls `fbq('trackSingle'|'trackSingleCustom', …, { eventID })` and sends the `/tr/` image only when `fbevents.js` didn't load, at the earliest a second after the base code ran (with Respect cookie choices on, only after marketing consent) (with `eid`, `event_id` and a current `v`). Purchase uses `wcct_{order_id}_purchase`; every other event, PageView included, takes its ID from `wcctMeta.eventId()` in the browser, so cached HTML never repeats one (the helper is printed before the base code). `content_ids` is an array of strings. Advanced matching is SHA-256 hashed: the verified order's billing details (phone with the country calling code), or the logged-in user's `em`. The Meta settings gain View product and Search, both on in the test settings (`facebook/product-*`, `facebook/search`). Only `facebook/*` and `all/*` changed. The Meta head changed on every page, so the captured 2.1.6 pages are skipped.
