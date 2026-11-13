<?php
/**
 * Prints the tracking output found in a saved page, laid out like a fixture, to compare a baseline page with one
 *
 * Usage: php extract-baseline.php <page.html> [home URL to replace with {home}]
 *
 * Finds the plugin's head block (between its HTML comments), the code in WooCommerce's queued
 * JavaScript block, and other inline scripts that call a tracking function.
 */

// phpcs:disable

if ( empty( $argv[1] ) || ! is_readable( $argv[1] ) ) {
    fwrite( STDERR, "Usage: php extract-baseline.php <page.html> [home URL]\n" );
    exit( 1 );
}

require_once dirname( __DIR__ ) . '/includes/class-fixture.php';

$html = str_replace( "\r\n", "\n", file_get_contents( $argv[1] ) );
$head = '';
$js   = '';

if ( preg_match( '/<!-- Starting: Conversion Tracking for WooCommerce.*?<!-- End: Conversion Tracking for WooCommerce Codes -->\n?/s', $html, $match ) ) {
    $head = $match[0];
    $html = str_replace( $head, '', $html );
}

if ( preg_match( '/<!-- WooCommerce JavaScript -->\n<script type="text\/javascript">\njQuery\(function\(\$\) \{ (.*?) \}\);\n<\/script>/s', $html, $match ) ) {
    $js   = $match[1];
    $html = str_replace( $match[0], '', $html );
}

$other = array();

if ( preg_match_all( '/<script\b[^>]*>.*?<\/script>/s', $html, $matches ) ) {
    foreach ( $matches[0] as $script ) {
        if ( preg_match( '/\b(fbq|wcfbq|gtag|wcctGtag|twq|twttr|uetq|ctCustom)\b/', $script ) ) {
            $other[] = $script;
        }
    }
}

$text = "<!-- baseline: " . basename( $argv[1] ) . " -->\n<!-- head -->\n$head\n<!-- other tracking scripts -->\n" . implode( "\n", $other ) . "\n<!-- footer: wc_enqueue_js -->\n$js\n";

echo isset( $argv[2] ) ? WCCT_Test_Fixture::normalise( $text, $argv[2] ) : $text;
