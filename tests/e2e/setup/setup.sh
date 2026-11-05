#!/bin/sh
# Prepares the e2e store. Runs inside the wp-env cli container (npm run env:setup) and is safe to re-run.
set -e
cd /var/www/html

wp plugin activate woocommerce
wp plugin activate woocommerce-conversion-tracking

# Pro is there only when .wp-env.override.json maps it in.
if wp plugin is-installed wc-conversion-tracking-pro; then
    wp plugin activate wc-conversion-tracking-pro
fi

wp theme activate storefront
wp rewrite structure '/%postname%/' --hard
wp eval-file wp-content/plugins/woocommerce-conversion-tracking/tests/e2e/setup/seed.php
wp plugin list --status=active --fields=name,version --skip-update-check
