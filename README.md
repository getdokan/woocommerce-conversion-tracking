Conversion Tracking for WooCommerce
====================

Integrates conversion tracking codes from Facebook, Google and various providers.

Why?
---------------

When you are integrating any advertising campaigns, they provide various tracking codes (mainly JavaScript) to insert them various pages of your site so that it can track how the conversion is happening.

This plugin inserts those codes on WooCommerce cart page, checkout success page and after user registration. So you can track who are adding your products to cart, who are buying them and who are registering to your site.

[Download](http://wordpress.org/plugins/woocommerce-conversion-tracking/)

Tests
---------------

- `tests/unit`: pure PHPUnit tests, no WordPress needed (`composer test:unit`).
- `tests/integration`: characterisation tests that request real store pages in a wp-env WordPress + WooCommerce store and compare the tracking output with fixtures. See [tests/integration/README.md](tests/integration/README.md).
- `tests/e2e`: Playwright tests that place orders. See [tests/e2e/README.md](tests/e2e/README.md).

Run the PHPUnit suites (Docker and Node 22 needed for the store):

```sh
composer install                       # dev packages, including PHPUnit
cd tests/integration && npm ci && npm run env:start
npm test                               # runs `composer test` in the store: unit, then characterisation tests
npm run env:stop && cd ../..
composer install --no-dev              # back to a release install
```

Author
---
[Tareq Hasan](http://tareq.weDevs.com)
