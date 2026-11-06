<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

/**
 * Base for the wcct/v1 route tests: runs REST steps in one store process, rolled back afterwards
 */
abstract class WCCT_Test_Rest_Case extends TestCase {

    /**
     * Routes every manage_wcct user reads.
     */
    const READ_ROUTES = array( '/wcct/v1/onboarding', '/wcct/v1/backup', '/wcct/v1/catalog', '/wcct/v1/access' );

    /**
     * Run steps (see jobs/rest-requests.php) and return one result per step.
     *
     * @param array  $steps Steps.
     * @param string $job   Job file under jobs/ that ends in rest-requests.php.
     *
     * @return array
     */
    protected function rest( array $steps, $job = 'rest-requests' ) {
        $result = WCCT_Test_WP::run(
            array(
                'type'     => 'file',
                'file'     => dirname( __DIR__ ) . '/jobs/' . $job . '.php',
                'steps'    => $steps,
                'rollback' => true,
            )
        );

        $this->assertNull( $result['error'] );
        $this->assertCount( count( $steps ), $result['value'] );

        return $result['value'];
    }

    /**
     * HTTP status of each request result.
     *
     * @param array $results Results of rest().
     *
     * @return array
     */
    protected static function statuses( array $results ) {
        return array_map(
            function ( $result ) {
                return is_array( $result ) && isset( $result['status'] ) ? $result['status'] : null;
            },
            $results
        );
    }

    /**
     * Whether Pro is loaded in the test store.
     *
     * @return bool
     */
    protected static function pro_loaded() {
        return '' !== (string) getenv( 'WCCT_PRO_DIR' );
    }
}
