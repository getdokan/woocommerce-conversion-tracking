<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Privacy\OrderUrl;

/**
 * Tests for the order URL cleaner; the JavaScript runs in Node when it is installed
 */
class OrderUrlTest extends TestCase {

    protected function tearDown(): void {
        unset( $GLOBALS['wcct_test_filters'] );
    }

    public function test_the_key_is_always_removed() {
        $GLOBALS['wcct_test_filters']['wcct_order_url_params'] = function () {
            return array( 'Order_ID', 5 );
        };

        $this->assertSame( array( 'key', 'order_id' ), OrderUrl::params() );
    }

    /**
     * @dataProvider urls
     */
    public function test_the_cleaner_removes_order_parameters( $url, $expected ) {
        $this->assertSame( $expected, $this->run_js( '(' . OrderUrl::cleaner_js() . ')(' . json_encode( $url ) . ')' ) );
    }

    public function urls() {
        return array(
            'thank-you page'    => array( 'https://shop.test/checkout/order-received/1310/?key=wc_order_abc123', 'https://shop.test/checkout/order-received/1310/' ),
            'other params kept' => array( 'https://shop.test/?page_id=7&order-received=1310&key=wc_order_abc&utm_source=x#top', 'https://shop.test/?page_id=7&order-received=1310&utm_source=x#top' ),
            'every name'        => array( 'https://shop.test/thanks/?order=5&order_id=5&order-pay=5&order_key=wc_order_a&KEY=b&a=1', 'https://shop.test/thanks/?a=1' ),
            'key in a value'    => array( 'https://shop.test/thanks/?ref=wc_order_abc&x=1', 'https://shop.test/thanks/?x=1' ),
            'encoded name'      => array( 'https://shop.test/?k%65y=wc_order_abc', 'https://shop.test/' ),
            'no query'          => array( 'https://shop.test/shop/', 'https://shop.test/shop/' ),
            'similar names'     => array( 'https://shop.test/shop/?orderby=price&monkey=1', 'https://shop.test/shop/?orderby=price&monkey=1' ),
            'sort order kept'   => array( 'https://shop.test/blog/?orderby=date&order=desc', 'https://shop.test/blog/?orderby=date&order=desc' ),
            'order number gone' => array( 'https://shop.test/thanks/?order=1310&x=1', 'https://shop.test/thanks/?x=1' ),
            'bad encoding'      => array( 'https://shop.test/thanks/?a=%E0%A4%A&key=1', 'https://shop.test/thanks/?a=%E0%A4%A' ),
        );
    }

    public function test_the_head_script_keeps_the_order_and_cleans_the_address_bar() {
        $script = OrderUrl::head_script( 1310 );
        $js     = 'var replaced = null, listeners = 0;
            var window = { location: { href: "https://shop.test/checkout/order-received/1310/?key=wc_order_abc&x=1" }, history: { state: null, replaceState: function (s, t, u) { replaced = u; } } };
            var document = { addEventListener: function () { listeners++; } };
            ' . $script . '
            JSON.stringify([window.wcctOrder, replaced, listeners]);';

        $this->assertSame( '[{"id":1310,"key":"wc_order_abc"},"https://shop.test/checkout/order-received/1310/?x=1",1]', $this->run_js( $js ) );
    }

    public function test_the_head_script_leaves_a_clean_url_alone() {
        $js = 'var replaced = null;
            var window = { location: { href: "https://shop.test/checkout/order-received/1310/" }, history: { replaceState: function (s, t, u) { replaced = u; } } };
            var document = { addEventListener: function () {} };
            ' . OrderUrl::head_script( 1310 ) . '
            JSON.stringify([window.wcctOrder, replaced]);';

        $this->assertSame( '[{"id":1310,"key":""},null]', $this->run_js( $js ) );
    }

    public function test_the_head_script_finds_a_funnel_key_and_survives_bad_encoding() {
        $run = function ( $href ) {
            return $this->run_js(
                'var window = { location: { href: ' . json_encode( $href ) . ' }, history: { replaceState: function () {} } };
                var document = { addEventListener: function () {} };
                ' . OrderUrl::head_script( 0 ) . '
                JSON.stringify(window.wcctOrder);'
            );
        };

        $this->assertSame( '{"id":0,"key":"wc_order_abc"}', $run( 'https://shop.test/funnel/thanks/?ref=1&ok=wc_order_abc' ) );
        $this->assertSame( '{"id":0,"key":"%E0%A4%A"}', $run( 'https://shop.test/thanks/?key=%E0%A4%A' ) );
    }

    private function run_js( $expression ) {
        $node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );

        if ( '' === $node ) {
            $this->markTestSkipped( 'Node is not installed.' );
        }

        $file = tempnam( sys_get_temp_dir(), 'wcct' );
        file_put_contents( $file, 'process.stdout.write(String(require("vm").runInNewContext(' . json_encode( $expression ) . ')));' );
        $output = shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $file ) );
        unlink( $file );

        return $output;
    }
}
