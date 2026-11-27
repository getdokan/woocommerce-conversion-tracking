<?php
// phpcs:disable

/**
 * Starts WordPress jobs in child processes and holds the paths they need
 */
class WCCT_Test_WP {

    /**
     * Paths set by the bootstrap: tests_dir, config, plugins, roots, theme_root
     *
     * @var array
     */
    public static $env = array();

    /**
     * Set up the paths from the environment
     *
     * @param array $plugins Plugin main files to load, in order. WooCommerce first.
     *
     * @return void
     */
    public static function configure( array $plugins ) {
        $tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : getenv( 'WP_PHPUNIT__DIR' );
        $tests_dir = $tests_dir ? rtrim( $tests_dir, '/' ) : rtrim( sys_get_temp_dir(), '/' ) . '/wordpress-tests-lib';
        $config    = getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) ? getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) : $tests_dir . '/wp-tests-config.php';

        if ( ! file_exists( $tests_dir . '/includes/install.php' ) || ! file_exists( $config ) ) {
            self::abort( "No WordPress test library at $tests_dir (set WP_TESTS_DIR, or run the suite in wp-env, see tests/integration/README.md)." );
        }

        foreach ( $plugins as $file ) {
            if ( ! file_exists( $file ) ) {
                self::abort( "Plugin file not found: $file" );
            }
        }

        self::$env = array(
            'tests_dir'  => $tests_dir,
            'config'     => $config,
            'plugins'    => array_values( $plugins ),
            // Output is recorded for every plugin except WooCommerce.
            'roots'      => array_map( 'dirname', array_slice( array_values( $plugins ), 1 ) ),
            'theme_root' => dirname( __DIR__ ) . '/theme',
            'tmp'        => rtrim( sys_get_temp_dir(), '/' ) . '/wcct-tests-' . getmypid(),
        );

        if ( ! is_dir( self::$env['tmp'] ) ) {
            mkdir( self::$env['tmp'], 0777, true );
        }
    }

    /**
     * Fresh database, fresh WordPress install, then the seeded store
     *
     * @param string[] $seed_files PHP files that build the store, each run in a new process.
     *
     * @return array The seed files' summaries, merged.
     */
    public static function install_store( array $seed_files ) {
        self::run( array( 'type' => 'reset' ) );

        $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( self::$env['tests_dir'] . '/includes/install.php' ) . ' ' . escapeshellarg( self::$env['config'] ) . ' 2>&1';
        exec( $command, $output, $status );

        if ( 0 !== $status ) {
            self::abort( "WordPress install failed:\n" . implode( "\n", $output ) );
        }

        $summary = array();

        foreach ( $seed_files as $seed_file ) {
            $result = self::run(
                array(
                    'type' => 'file',
                    'file' => $seed_file,
                )
            );

            if ( $result['error'] || empty( $result['value'] ) ) {
                self::abort( 'Seeding the store failed in ' . basename( $seed_file ) . ': ' . ( $result['error'] ? $result['error'] : 'no summary returned' ) );
            }

            $summary = array_merge( $summary, $result['value'] );
        }

        return $summary;
    }

    /**
     * Run a job in a child process and return its result
     *
     * @param array $job Job, see bin/run.php.
     *
     * @return array
     */
    public static function run( array $job ) {
        static $count = 0;

        ++$count;
        $file = self::$env['tmp'] . '/job-' . $count . '.json';
        $job  = array_merge(
            array(
                'config'     => self::$env['config'],
                'plugins'    => self::$env['plugins'],
                'roots'      => self::$env['roots'],
                'theme_root' => self::$env['theme_root'],
                'options'    => array(),
                'user_meta'  => array(),
                'user_id'    => 0,
                'rollback'   => false,
                'result'     => self::$env['tmp'] . '/result-' . $count . '.json',
            ),
            $job
        );

        file_put_contents( $file, json_encode( $job ) );

        $command = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bin/run.php' ) . ' ' . escapeshellarg( $file ) . ' 2>&1';
        exec( $command, $output );

        $result = file_exists( $job['result'] ) ? json_decode( file_get_contents( $job['result'] ), true ) : null;

        @unlink( $file );
        @unlink( $job['result'] );

        if ( ! is_array( $result ) ) {
            return array( 'error' => "The job wrote no result. Process output:\n" . implode( "\n", $output ) );
        }

        return $result;
    }

    /**
     * Stop the suite with a message
     *
     * @param string $message Message.
     *
     * @return void
     */
    public static function abort( $message ) {
        fwrite( STDERR, $message . PHP_EOL );
        exit( 1 );
    }
}
