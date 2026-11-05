<?php
// phpcs:disable

/**
 * Turns a recorded request into fixture text, and reads and writes fixture files
 */
class WCCT_Test_Fixture {

    /**
     * Fixture text for a recorded request
     *
     * @param string $title  Fixture title.
     * @param array  $result Result from bin/run.php.
     *
     * @return string
     */
    public static function format( $title, array $result ) {
        $regions = array(
            'head'   => array(),
            'body'   => array(),
            'footer' => array(),
        );

        foreach ( $result['chunks'] as $chunk ) {
            $regions[ $chunk[0] ][] = $chunk[1];
        }

        $text  = "<!-- wcct-fixture: $title -->\n";
        $text .= '<!-- page: ' . implode( ' ', $result['page'] ) . " -->\n";

        foreach ( $regions as $region => $outputs ) {
            $text .= "<!-- $region -->\n" . implode( "\n", $outputs ) . "\n";
        }

        $text .= "<!-- footer: wc_enqueue_js -->\n" . implode( '', $result['queued_js'] ) . "\n";
        $text .= "<!-- scripts -->\n";

        foreach ( $result['scripts'] as $script ) {
            if ( false !== $script['ver'] ) {
                $script['ver'] = '{version}';
            }

            $text .= json_encode( $script, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
        }

        return self::normalise( $text, $result['home'] );
    }

    /**
     * Replace the parts that change between runs and environments
     *
     * @param string $text Fixture text.
     * @param string $home Home URL of the test store.
     *
     * @return string
     */
    public static function normalise( $text, $home ) {
        $home = rtrim( $home, '/' );
        $text = str_replace( "\r\n", "\n", $text );
        $text = str_replace( array( $home, str_replace( '/', '\/', $home ), rawurlencode( $home ) ), array( '{home}', '{home}', '{home}' ), $text );

        // Random IDs, for example GA4's add to cart entry IDs.
        return preg_replace( '/[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/', '{uuid}', $text );
    }
}
