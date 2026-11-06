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

## The store

Fixtures are only valid for this store. Change it and every fixture has to be recorded again.

- **Stack:** WordPress and WooCommerce versions pinned in `.wp-env.json` (WordPress 7.1.4, WooCommerce 11.2.2, PHP 8.3), HPOS on, pretty permalinks (`/%postname%/`), the `wcct-test` classic theme.
- **Store options:** US store (`US:CA`) in USD with 2 decimals, no taxes, guest checkout and My account registration on, AJAX add to cart on, no redirect to the cart after adding, coming soon mode off.
- **Pages:** 1001 Shop, 1002 Cart (WooCommerce's cart block), 1003 Checkout (checkout block), 1004 Classic checkout (`[woocommerce_checkout]`), 1005 My account. The home page is the latest posts.
- **Products:** category Clothing with Accessories and Hoodies under it, created on fixed dates so search results keep their order. 2001 Beanie (simple, 18.00, Accessories), 2002 Hoodie (variable, Hoodies) with 2003 Blue (42.00) and 2004 Red (45.00), 2005 T-Shirt (simple, 25.00, Clothing). All descriptions contain "cotton".
- **Coupon:** 2501 `ctsave5`, 5.00 off the cart.
- **Customer:** user 501 `ctcustomer`, Sam Buyer, customer@example.com, billing address in Oakland, CA.
- **Orders:** 3001 guest order by Jane Doe (jane@example.com), 2 × Beanie and 1 × Hoodie Blue, coupon `ctsave5`, 5.00 flat rate shipping, cash on delivery, processing, total 78.00, key `wc_order_ctguest0001`. 3002 customer 501's order, 1 × Beanie, 5.00 shipping, processing, total 23.00, key `wc_order_ctcustomer01`. The thank-you page is viewed inside WooCommerce's email verification grace period, as right after checkout.
- **Tracking settings:** `includes/class-settings.php`, in the shape the settings page saves them, with fake IDs: Meta pixel `111111111111111`, Google Ads `AW-123456789` with label `CtPurchaseLabel`, GA4 `G-CTTEST1234`, X universal tag `ct0x1`, and Custom scripts that use every placeholder. Every event is on.

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
