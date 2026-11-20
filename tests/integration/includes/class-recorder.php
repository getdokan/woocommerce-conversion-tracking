<?php
// phpcs:disable

/**
 * Records what the plugins under test print during one request
 *
 * Every hook callback defined in a plugin directory is wrapped just before its hook runs,
 * so the recorder sees its echoed output, the code it passes to wc_enqueue_js() and the
 * scripts it enqueues. Nothing about the plugins' internals is assumed.
 */
class WCCT_Test_Recorder {

    /**
     * Plugin directories whose callbacks are recorded
     *
     * @var string[]
     */
    private $roots = array();

    /**
     * Whether recording is paused, for example while the cart is prepared
     *
     * @var bool
     */
    private $paused = false;

    /**
     * Plugin callbacks running; only the outermost one records, as it sees the nested ones' output too
     *
     * @var int
     */
    public $depth = 0;

    /**
     * Callback key => whether it belongs to a plugin under test
     *
     * @var array
     */
    private $known = array();

    /**
     * Printed output as [ region, output ], in page order
     *
     * @var array
     */
    public $chunks = array();

    /**
     * Text added to WooCommerce's wc_enqueue_js() queue, in call order
     *
     * @var string[]
     */
    public $queued_js = array();

    /**
     * Script handles the plugins enqueued, registered or added inline code to
     *
     * @var string[]
     */
    public $scripts = array();

    /**
     * Constructor
     *
     * @param string[] $roots Plugin directories.
     */
    public function __construct( array $roots ) {
        foreach ( $roots as $root ) {
            $real = realpath( $root );

            if ( $real ) {
                $this->roots[] = rtrim( $real, '/' ) . '/';
            }
        }
    }

    /**
     * Stop recording until resume()
     *
     * @return void
     */
    public function pause() {
        $this->paused = true;
    }

    /**
     * Start recording again
     *
     * @return void
     */
    public function resume() {
        $this->paused = false;
    }

    /**
     * Whether recording is paused
     *
     * @return bool
     */
    public function is_paused() {
        return $this->paused;
    }

    /**
     * Wrap the plugins' callbacks on a hook that is about to run; hooked to 'all'
     *
     * @param string $hook Hook name.
     *
     * @return void
     */
    public function on_hook( $hook ) {
        global $wp_filter;

        if ( ! isset( $wp_filter[ $hook ] ) || ! $wp_filter[ $hook ] instanceof WP_Hook ) {
            return;
        }

        foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
            foreach ( $callbacks as $id => $callback ) {
                if ( $callback['function'] instanceof WCCT_Test_Recorded_Callback ) {
                    continue;
                }

                $key = $hook . '|' . $priority . '|' . $id;

                if ( ! isset( $this->known[ $key ] ) ) {
                    $this->known[ $key ] = $this->is_plugin_callback( $callback['function'] );
                }

                if ( $this->known[ $key ] ) {
                    $wp_filter[ $hook ]->callbacks[ $priority ][ $id ]['function'] = new WCCT_Test_Recorded_Callback( $this, $callback['function'] );
                }
            }
        }
    }

    /**
     * Whether a callback is defined in one of the plugin directories
     *
     * @param mixed $callback Hook callback.
     *
     * @return bool
     */
    private function is_plugin_callback( $callback ) {
        try {
            if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
                $callback = explode( '::', $callback, 2 );
            }

            if ( is_array( $callback ) && 2 === count( $callback ) ) {
                $class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
                $reflection = method_exists( $class, $callback[1] ) ? new ReflectionMethod( $class, $callback[1] ) : new ReflectionClass( $class );
            } elseif ( $callback instanceof Closure || is_string( $callback ) ) {
                $reflection = new ReflectionFunction( $callback );
            } elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
                $reflection = new ReflectionMethod( $callback, '__invoke' );
            } else {
                return false;
            }
        } catch ( ReflectionException $e ) {
            return false;
        }

        $file = $reflection->getFileName();

        if ( ! $file ) {
            return false;
        }

        $file = realpath( $file );

        foreach ( $this->roots as $root ) {
            if ( 0 === strpos( $file, $root ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * State before a plugin callback runs
     *
     * @return array
     */
    public function before_callback() {
        return array(
            'queued_js' => isset( $GLOBALS['wc_queued_js'] ) ? (string) $GLOBALS['wc_queued_js'] : '',
            'scripts'   => $this->scripts_snapshot(),
        );
    }

    /**
     * Record what a plugin callback printed, queued and enqueued
     *
     * @param array  $before State from before_callback().
     * @param string $output Printed output.
     *
     * @return void
     */
    public function after_callback( array $before, $output ) {
        if ( '' !== $output ) {
            $this->chunks[] = array( $this->region(), $output );
        }

        $queued = isset( $GLOBALS['wc_queued_js'] ) ? (string) $GLOBALS['wc_queued_js'] : '';

        // Kept exactly as queued, so the joined entries are the text WooCommerce prints.
        if ( $queued !== $before['queued_js'] ) {
            $this->queued_js[] = 0 === strpos( $queued, $before['queued_js'] ) ? substr( $queued, strlen( $before['queued_js'] ) ) : $queued;
        }

        foreach ( $this->scripts_snapshot() as $handle => $hash ) {
            if ( ( ! isset( $before['scripts'][ $handle ] ) || $before['scripts'][ $handle ] !== $hash ) && ! in_array( $handle, $this->scripts, true ) ) {
                $this->scripts[] = $handle;
            }
        }
    }

    /**
     * Page region being printed
     *
     * @return string
     */
    private function region() {
        if ( doing_action( 'wp_head' ) ) {
            return 'head';
        }

        if ( doing_action( 'wp_footer' ) ) {
            return 'footer';
        }

        return 'body';
    }

    /**
     * Hash of every registered script and whether it is enqueued
     *
     * @return array Handle => hash.
     */
    private function scripts_snapshot() {
        if ( ! isset( $GLOBALS['wp_scripts'] ) || ! $GLOBALS['wp_scripts'] instanceof WP_Scripts ) {
            return array();
        }

        $scripts  = $GLOBALS['wp_scripts'];
        $snapshot = array();

        foreach ( $scripts->registered as $handle => $script ) {
            $snapshot[ $handle ] = md5( serialize( array( $script->src, $script->deps, $script->ver, $script->extra, in_array( $handle, $scripts->queue, true ) ) ) );
        }

        return $snapshot;
    }

    /**
     * Describe the recorded scripts as WordPress holds them at the end of the request
     *
     * @return array
     */
    public function describe_scripts() {
        $described = array();

        if ( ! isset( $GLOBALS['wp_scripts'] ) || ! $GLOBALS['wp_scripts'] instanceof WP_Scripts ) {
            return $described;
        }

        $scripts = $GLOBALS['wp_scripts'];

        foreach ( $this->scripts as $handle ) {
            if ( ! isset( $scripts->registered[ $handle ] ) ) {
                continue;
            }

            $script      = $scripts->registered[ $handle ];
            $described[] = array(
                'handle'    => $handle,
                'src'       => (string) $script->src,
                'ver'       => false === $script->ver ? false : (string) $script->ver,
                'deps'      => array_values( (array) $script->deps ),
                'in_footer' => ! empty( $script->extra['group'] ),
                'printed'   => in_array( $handle, $scripts->done, true ),
                'extra'     => array_diff_key( $script->extra, array( 'group' => true ) ),
            );
        }

        return $described;
    }
}

/**
 * A plugin callback wrapped by WCCT_Test_Recorder
 */
class WCCT_Test_Recorded_Callback {

    /**
     * Recorder
     *
     * @var WCCT_Test_Recorder
     */
    private $recorder;

    /**
     * Original callback
     *
     * @var callable
     */
    private $callback;

    /**
     * Constructor
     *
     * @param WCCT_Test_Recorder $recorder Recorder.
     * @param callable           $callback Original callback.
     */
    public function __construct( WCCT_Test_Recorder $recorder, $callback ) {
        $this->recorder = $recorder;
        $this->callback = $callback;
    }

    /**
     * Run the original callback and record what it did
     *
     * @param mixed ...$args Hook arguments.
     *
     * @return mixed
     */
    public function __invoke( ...$args ) {
        if ( $this->recorder->is_paused() || $this->recorder->depth > 0 ) {
            return call_user_func_array( $this->callback, $args );
        }

        $before = $this->recorder->before_callback();
        $output = '';

        ++$this->recorder->depth;
        ob_start();

        try {
            $value = call_user_func_array( $this->callback, $args );
        } finally {
            $output = (string) ob_get_clean();
            --$this->recorder->depth;
            $this->recorder->after_callback( $before, $output );
            echo $output;
        }

        return $value;
    }
}
