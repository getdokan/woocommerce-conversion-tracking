<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * Base for tests that request store pages and compare the plugins' output with fixture files
 *
 * WCCT_UPDATE_FIXTURES=1 writes the fixtures instead of comparing. A mismatch writes the
 * actual output to .output/ next to the fixtures directory, so CI can upload it.
 */
abstract class WCCT_Test_Page_Case extends TestCase {

    /**
     * Results by request, shared by the tests in one run
     *
     * @var array
     */
    private static $results = array();

    /**
     * Directory holding this suite's fixtures
     *
     * @return string
     */
    abstract protected function fixtures_dir();

    /**
     * Request a page with the given tracking settings
     *
     * @param string $page     Page name from WCCT_Test_Pages.
     * @param array  $settings The wcct_settings option.
     * @param array  $options  Other options for this request.
     *
     * @return array Result from bin/run.php.
     */
    protected function request_page( $page, array $settings, array $options = array() ) {
        $request = WCCT_Test_Pages::get( $page );
        $key     = md5( serialize( array( $page, $settings, $options ) ) );

        if ( isset( self::$results[ $key ] ) ) {
            return self::$results[ $key ];
        }

        $job = array(
            'type'      => 'request',
            'rollback'  => true,
            'request'   => array( 'path' => $request['path'] ),
            'user_id'   => isset( $request['user_id'] ) ? $request['user_id'] : 0,
            'user_meta' => isset( $request['user_meta'] ) ? $request['user_meta'] : array(),
            'options'   => array_merge( isset( $request['options'] ) ? $request['options'] : array(), $options, array( 'wcct_settings' => $settings ) ),
        );

        if ( array_key_exists( 'cart', $request ) ) {
            $job['cart'] = $request['cart'];
        }

        $result = WCCT_Test_WP::run( $job );

        if ( $result['error'] ) {
            $this->fail( "Requesting $page ({$request['path']}) failed: {$result['error']}" );
        }

        $this->assertSame( array(), $result['not_on_page'], "Recorded output missing from the page HTML of $page" );

        // WCCT_KEEP_HTML=1 keeps each full page under .output/html/ for debugging.
        if ( getenv( 'WCCT_KEEP_HTML' ) ) {
            $dir = $this->output_dir() . '/html';
            is_dir( $dir ) || mkdir( $dir, 0777, true );
            file_put_contents( $dir . '/' . $page . '-' . substr( $key, 0, 8 ) . '.html', $result['html'] );
        }

        return self::$results[ $key ] = $result;
    }

    /**
     * All output the plugins printed or queued on a page, normalised
     *
     * @param array $result Result from request_page().
     *
     * @return string
     */
    protected function output_of( array $result ) {
        return WCCT_Test_Fixture::format( '', $result );
    }

    /**
     * Event JavaScript of a page: WooCommerce's queued JavaScript and the code added to inline scripts
     *
     * @param array $result Result from request_page().
     *
     * @return string
     */
    protected function event_js( array $result ) {
        $code = implode( '', $result['queued_js'] );

        foreach ( $result['scripts'] as $script ) {
            $after = isset( $script['extra']['after'] ) && is_array( $script['extra']['after'] ) ? $script['extra']['after'] : array();
            $code .= implode( '', array_filter( $after, 'is_string' ) );
        }

        return $code;
    }

    /**
     * Assert a request's output matches its fixture file
     *
     * @param string $name   Fixture name, for example "facebook/home".
     * @param array  $result Result from request_page().
     *
     * @return void
     */
    protected function assertMatchesFixture( $name, array $result ) {
        $actual = WCCT_Test_Fixture::format( $name, $result );
        $file   = $this->fixtures_dir() . '/' . $name . '.html';

        if ( getenv( 'WCCT_UPDATE_FIXTURES' ) ) {
            is_dir( dirname( $file ) ) || mkdir( dirname( $file ), 0777, true );
            file_put_contents( $file, $actual );
            $this->addToAssertionCount( 1 );

            return;
        }

        $expected = file_exists( $file ) ? file_get_contents( $file ) : null;

        if ( $expected !== $actual ) {
            $out = $this->output_dir() . '/' . $name . '.html';
            is_dir( dirname( $out ) ) || mkdir( dirname( $out ), 0777, true );
            file_put_contents( $out, $actual );
        }

        if ( null === $expected ) {
            $this->fail( "No fixture $name.html. Record it with WCCT_UPDATE_FIXTURES=1 once the output is right." );
        }

        $this->assertSame( $expected, $actual, "The tracking output of $name changed. If the change is intended, update the fixture (see tests/integration/README.md)." );
    }

    /**
     * Pages whose captured HTML is known to differ from today's output on purpose, page => reason
     *
     * @return array
     */
    protected function captured_page_differences() {
        return array();
    }

    /**
     * Markup removed from the output on purpose, taken out of the captured pages before comparing, markup => reason
     *
     * @return array
     */
    protected function captured_page_removals() {
        return array();
    }

    /**
     * Like captured_page_removals(), for removed markup that varies: regular expression => reason
     *
     * @return array
     */
    protected function captured_page_removal_patterns() {
        return array();
    }

    /**
     * Assert today's output is all in the page captured from the released versions (html/<page>.html in the fixtures)
     *
     * The captured pages are the full storefront HTML the fixtures were derived from. WCCT_CAPTURE_HTML=1
     * writes them from the current run instead; run that against the released code only.
     *
     * @param string $page   Page name.
     * @param array  $result Result from request_page().
     *
     * @return void
     */
    protected function assertInCapturedPage( $page, array $result ) {
        $file = $this->fixtures_dir() . '/html/' . $page . '.html';

        if ( getenv( 'WCCT_CAPTURE_HTML' ) ) {
            is_dir( dirname( $file ) ) || mkdir( dirname( $file ), 0777, true );
            file_put_contents( $file, WCCT_Test_Fixture::normalise( $result['html'], $result['home'] ) );
            $this->addToAssertionCount( 1 );

            return;
        }

        $differences = $this->captured_page_differences();

        if ( isset( $differences[ $page ] ) ) {
            $this->markTestSkipped( "Captured page $page differs on purpose: {$differences[ $page ]}" );
        }

        if ( ! file_exists( $file ) ) {
            $this->fail( "No captured page html/$page.html. Capture it from the released versions with WCCT_CAPTURE_HTML=1." );
        }

        $captured = str_replace( array_keys( $this->captured_page_removals() ), '', file_get_contents( $file ) );

        foreach ( array_keys( $this->captured_page_removal_patterns() ) as $pattern ) {
            $captured = preg_replace( $pattern, '', $captured );
        }

        $outputs  = array_map(
            function ( $chunk ) {
                return $chunk[1];
            },
            $result['chunks']
        );

        if ( $result['queued_js'] ) {
            $outputs[] = implode( '', $result['queued_js'] );
        }

        foreach ( $outputs as $output ) {
            $this->assertStringContainsString( WCCT_Test_Fixture::normalise( $output, $result['home'] ), $captured, "Output of $page is not in the captured page html/$page.html" );
        }
    }

    /**
     * Where actual output is written when it differs from a fixture
     *
     * @return string
     */
    protected function output_dir() {
        return dirname( $this->fixtures_dir() ) . '/.output';
    }
}
