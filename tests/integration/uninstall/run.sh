#!/usr/bin/env bash
# Uninstall test (#121), run inside wp-env's cli container: `npm run test:uninstall` from tests/integration.
#
# Each scenario installs a throwaway site (own table prefix, plugins folder and uploads folder, so the
# characterisation store is untouched), activates WooCommerce, Free and Pro, seeds every kind of data
# (seed.php), deletes one plugin with `wp plugin uninstall`, checks what is left (check.php), deletes the
# other and checks that only the order sent flags remain. Plugins are copied, so deleting them is real.
# Without Pro mapped, only Free is installed and deleted, and nothing may be left after it.
#
# Environment: WCCT_PRO_DIR (Pro checkout, default next to Free), WC_DIR (WooCommerce, default next to Free).

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
LITE_SRC="$(cd "$HERE/../../.." && pwd)"
PRO_SRC="${WCCT_PRO_DIR:-$(dirname "$LITE_SRC")/wc-conversion-tracking-pro}"
WC_SRC="${WC_DIR:-$(dirname "$LITE_SRC")/woocommerce}"
WP_PATH="${WP_PATH:-/var/www/html}"
WORK=/tmp/wcct-uninstall
PLUGINS="$WORK/plugins"
UPLOADS="wp-content/uploads-wcct-uninstall"
LITE=woocommerce-conversion-tracking
PRO=wc-conversion-tracking-pro
BASE_EXTRA="define( 'WP_PLUGIN_DIR', '$PLUGINS' ); define( 'WP_PLUGIN_URL', 'http://localhost/wcct-plugins' ); define( 'UPLOADS', '$UPLOADS' );"
PREFIX=""
EXTRA=""
URLS=""

wpc() {
    WORDPRESS_TABLE_PREFIX="$PREFIX" WORDPRESS_CONFIG_EXTRA="$EXTRA" wp --path="$WP_PATH" "$@"
}

drop_tables() {
    local tables
    tables=$(wpc db query "SELECT GROUP_CONCAT(table_name SEPARATOR ', ') FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '${PREFIX%_}\\_%'" --skip-column-names)

    if [ -n "$tables" ] && [ "$tables" != "NULL" ]; then
        wpc db query "DROP TABLE $tables"
    fi
}

# copy_plugins <plugin folder>...
copy_plugins() {
    rm -rf "$WORK" "${WP_PATH:?}/$UPLOADS"
    mkdir -p "$PLUGINS"
    ln -s "$WC_SRC" "$PLUGINS/woocommerce"

    local plugin src
    for plugin in "$@"; do
        src="$LITE_SRC"
        [ "$plugin" = "$PRO" ] && src="$PRO_SRC"
        mkdir -p "$PLUGINS/$plugin"
        tar -C "$src" --exclude=./node_modules --exclude=./tests --exclude=./.git --exclude=./src --exclude=./build -cf - . | tar -C "$PLUGINS/$plugin" -xf -
    done
}

check_all() {
    local url
    for url in $URLS; do
        wpc --url="$url" --skip-plugins eval-file "$HERE/check.php" "$1"
    done
}

# scenario <table prefix> <single|multisite> <deleted first> [deleted second]
scenario() {
    local multisite="$2" first="$3" second="${4:-}" network="" url domain

    PREFIX="$1"
    EXTRA="$BASE_EXTRA"
    URLS="localhost"

    echo "== $multisite: delete $first${second:+, then $second}"

    drop_tables
    copy_plugins "$first" $second

    if [ "$multisite" = multisite ]; then
        wpc core multisite-install --url=localhost --title=Uninstall --admin_user=admin --admin_password=admin --admin_email=admin@example.org --skip-email --skip-config > /dev/null
        # The network takes its domain from WP_SITEURL, which wp-env sets.
        domain=$(wpc db query "SELECT domain FROM ${PREFIX}site WHERE id = 1" --skip-column-names)
        EXTRA="$BASE_EXTRA define( 'MULTISITE', true ); define( 'SUBDOMAIN_INSTALL', false ); define( 'DOMAIN_CURRENT_SITE', '$domain' ); define( 'PATH_CURRENT_SITE', '/' ); define( 'SITE_ID_CURRENT_SITE', 1 ); define( 'BLOG_ID_CURRENT_SITE', 1 );"
        wpc site create --slug=second --quiet
        URLS="$domain $domain/second/"
        network="--network"
    else
        wpc core install --url=localhost --title=Uninstall --admin_user=admin --admin_password=admin --admin_email=admin@example.org --skip-email > /dev/null
    fi

    wpc plugin activate woocommerce $network --quiet
    wpc plugin activate "$LITE" ${second:+"$PRO"} $network --quiet

    for url in $URLS; do
        # Network activation doesn't install WooCommerce's tables on the other sites.
        wpc --url="$url" --skip-plugins="$LITE,$PRO" eval 'WC_Install::install();' 2> /dev/null
        wpc --url="$url" eval-file "$HERE/seed.php"
    done

    wpc plugin deactivate ${second:+"$PRO"} "$LITE" $network --quiet
    wpc plugin uninstall "$first"

    if [ -z "$second" ]; then
        check_all none
    else
        check_all "$([ "$first" = "$LITE" ] && echo pro || echo free)"

        if [ "$first" = "$LITE" ]; then
            # Data left by a Free that had no uninstall (2.x/1.x): Pro's uninstall takes it once Free is gone.
            for url in $URLS; do
                wpc --url="$url" --skip-plugins eval '
                    update_option( "wcct_settings", array( "facebook" => array( "enabled" => true ) ) );
                    update_option( "woocommerce_wc_conv_tracking_settings", array( "fb_pixel" => "1" ) );
                    update_option( "woocommerce-conversion-tracking_allow_tracking", "no" );
                    $ids = get_posts( array( "post_type" => "product", "post_status" => "any", "fields" => "ids" ) );
                    foreach ( $ids as $id ) {
                        add_post_meta( $id, "_wc_conv_track", "<script>/* 1.x */</script>" );
                    }
                    $ids || WP_CLI::error( "No product to add the 1.x meta to." );'
            done
        fi

        wpc plugin uninstall "$second"
        check_all none
    fi

    drop_tables
    rm -rf "$WORK" "${WP_PATH:?}/$UPLOADS"
}

if [ -f "$PRO_SRC/woocommerce-conversion-tracking-pro.php" ]; then
    for setup in single multisite; do
        scenario "wcctu${setup:0:1}_" "$setup" "$LITE" "$PRO"
        scenario "wcctu${setup:0:1}_" "$setup" "$PRO" "$LITE"
    done
else
    echo "Pro not found at $PRO_SRC: testing Free alone. Map Pro into wp-env (README) to test both."
    scenario wcctus_ single "$LITE"
    scenario wcctum_ multisite "$LITE"
fi

echo "Uninstall test passed."
