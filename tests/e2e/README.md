# End-to-end tests

Playwright tests that place real guest orders in a throwaway WooCommerce store and check what the tracking tags send.

The store runs in Docker with [wp-env](https://www.npmjs.com/package/@wordpress/env): WordPress, WooCommerce, the Storefront theme, this plugin and, when mapped in, Pro. Every spec runs with two automatic fixtures:

- **`tracker`** answers every request that leaves the test site, so no test reaches a real ad platform. It records each request to a platform host (Meta, Google, X, Microsoft) with its URL, body, referer and the page that sent it.
- **`jsErrors`** fails the test on any uncaught exception or `console.error` on any page.

`specs/purchase.spec.ts` places one order on classic checkout and one on block checkout, paid by cash on delivery, and fails unless every tag with a test ID in `config/test-ids.json` (Meta, GA4, Google Ads, X) sent its Purchase hit.

`specs/order-key.spec.ts` places the same guest orders and fails when any request to a platform host, from the thank-you page or the next page view, has `wc_order_` or `key=` in its URL, body or referer. It also checks that the thank-you page shows the order on first load, that Google gets a cleaned page location on a page with order parameters but no order key (an archive's `order=desc` survives), and that no platform gets the key on a funnel page whose URL carries one. With Pro mapped in, X's optional events (Add to cart, Start checkout, Registration) and Microsoft are seeded too, so their requests are checked as well.

`specs/add-to-cart.spec.ts` adds to the cart from the product form, a shop-page AJAX button and a block Product Button (the `/block-shop/` page), and expects exactly one add to cart hit per tag, also after one more page view. Its last test turns on the `ct-e2e-page-cache.php` mu-plugin (a worst-case page cache that serves the same HTML to every browser) and checks a second browser never sends the first browser's add to cart. `specs/sign-up.spec.ts` signs up on My account and at classic checkout and expects one Meta `CompleteRegistration`, and none on the next page view.

`specs/custom-scripts.spec.ts` turns on the Custom script's three placements for its browser (the `ct_e2e_custom_scripts` cookie of `ct-e2e-helpers.php`), each sending a hit to `custom.ct-e2e.test`, and expects the header script once on every page, the thank-you script once per order (none after a reload) with the order placeholders filled, and the sign-up script once per new account.

`specs/begin-checkout.spec.ts` loads block and classic checkout and checks that Meta `InitiateCheckout` and GA4 `begin_checkout` are each sent once, and none on the thank-you page. With Pro active, setup also saves X's Start checkout event ID and a Microsoft UET tag (`config/checkout-ids.json`), and the spec checks their hits too; `utils/stubs` has stand-ins for X's `uwt.js` and Microsoft's `bat.js` too.

`specs/settings.spec.ts` covers Settings > Integration access and Settings > Plan. On Free (no Pro mapped) it checks the locked access preview, the plan comparison with no price, and that every Upgrade, See pricing, Compare plans and plugins-list Get PRO link opens the WooCommerce.com product page. With Pro it checks Manage subscription, the confirmation before a role is granted (Cancel and Escape leave it off), the save, the Dokan vendor warning, and that a granted shop manager (`ct_e2e_shop_manager` / `password`, seeded by setup) sees the list read-only and gets 403 from the access route. Run it once without and once with Pro to cover both.

## Run it locally

You need Docker and Node 22.13 or newer. The plugin also needs its Composer packages and its built admin bundles, so run `composer install` and `npm ci && npm run build` in the plugin root once.

```sh
cd tests/e2e
npm install
npx playwright install chromium
npm run env:start   # starts the store at http://localhost:8894 and seeds it
npm test
npm run env:stop
```

`npm run env:start` runs `npm run env:setup` after start. That script (`setup/setup.sh` and `setup/seed.php`) activates the plugins, sets pretty permalinks, enables cash on delivery, creates the product and the classic checkout page, and saves the tracking settings with the test IDs. It is safe to run again on its own.

Other scripts: `test:headed`, `test:ui`, `test:debug`, `test:report`, `env:reset` (empty database, then setup), `env:destroy`. Use `WP_BASE_URL` to point the tests at another URL, and `WP_ENV_PORT` to start wp-env on another port (set `WP_BASE_URL` to match).

### With Pro

Create `tests/e2e/.wp-env.override.json` (it is git-ignored) that maps your Pro checkout, then run `npm run env:start` again:

```json
{
    "mappings": {
        "wp-content/plugins/wc-conversion-tracking-pro": "../../../wc-conversion-tracking-pro"
    }
}
```

The path above works when Pro sits next to this plugin in `wp-content/plugins`. Setup activates Pro whenever it is mapped in.

### Real platform libraries

The tracker serves small stand-ins for `fbevents.js`, `gtag.js`, X's `uwt.js` and Microsoft's `bat.js` (`utils/stubs`). They turn `fbq()`, `gtag()`, `twq()` and `uetq` calls into hits like the real libraries send (`facebook.com/tr?ev=…`, `google-analytics.com/g/collect?en=…`, `googleadservices.com/pagead/conversion/…`, `analytics.twitter.com/1/i/adsct?txn_id=…`, `bat.bing.com/action/0?…`), reading the page URL and referrer from the browser the same way. To use the real libraries instead, run `CT_E2E_LIVE_TAGS=1 npm test`. Their hits are still answered locally.

## Layout

| Path | What it is |
| --- | --- |
| `.wp-env.json` | The store. WordPress and WooCommerce versions are pinned here; CI uses the current releases unless asked for the pinned ones. |
| `setup/` | Seeding, run inside the wp-env cli container. |
| `mu-plugins/ct-e2e-helpers.php` | Test-site helpers: the `ct_e2e_checkout=classic` cookie makes the shortcode page the checkout page, the `ct_e2e_custom_scripts` cookie turns on the Custom script placements, and wordpress.org calls are blocked. |
| `mu-plugins/ct-e2e-page-cache.php` | With the `ct_e2e_page_cache` cookie, caches logged-out GET pages per URL and serves them to every browser (`X-CT-Cache: HIT`); `/?ct_e2e_page_cache_flush=1` empties it. |
| `config/test-ids.json` | Test IDs. A tag listed here is enabled in the store and must send a Purchase hit. |
| `utils/fixtures.ts` | `test` and `expect` with the `tracker` and `jsErrors` fixtures. Import these in every spec. |
| `utils/network.ts` | The tracker: `platformRequests(platform?)`, `purchaseHits(tag)`, `expectPurchase(tag)`, `eventHits(tag, event)`. |
| `utils/platforms.ts` | Platform hosts and the test IDs. |
| `utils/checkout.ts` | `addProductToCart()` and `placeGuestOrder(page, 'classic' \| 'block')`. |
| `specs/` | The tests. |

## Writing a spec

```ts
import { test, expect } from '../utils/fixtures';
import { placeGuestOrder } from '../utils/checkout';

test('the GA4 purchase carries the order number', async ({ page, tracker }) => {
    const order = await placeGuestOrder(page, 'block');
    const [hit] = await tracker.expectPurchase('ga4');

    expect(hit.params.some(p => p.get('ep.transaction_id') === String(order.id))).toBe(true);
});
```

`tracker.platformRequests()` returns every captured request with `url`, `postData`, `referer` and `pageUrl`, which is what a check like the order-key test needs.

To check a new platform, add its test ID to `config/test-ids.json`, save it in `setup/seed.php`, and add its Purchase check to `PURCHASE` in `utils/network.ts`.

## CI

`.github/workflows/e2e.yml` runs the suite on every pull request and on pushes to `develop`, `master` and `feature/ct-revamp`. It builds the plugin like a release, checks out Pro next to it (the branch with the same name, else `develop`), starts wp-env on the current WordPress and WooCommerce, and uploads the Playwright report, traces and `debug.log` when a test fails. Fork pull requests cannot read secrets, so they test without Pro.

Pull requests in `wc-conversion-tracking-pro` start this workflow through their `e2e-on-lite.yml` and get its result back as the `e2e` status.

Secrets:

- `CT_PRO_TOKEN` (this repository): fine-grained token with Contents: Read and Commit statuses: Read and write on `getdokan/wc-conversion-tracking-pro`.
- `CT_LITE_DISPATCH_TOKEN` (`wc-conversion-tracking-pro`): fine-grained token with Actions: Read and write on this repository.
