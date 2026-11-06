<?php

namespace StorePulse\ConversionTracking\Doctor;

/**
 * One thing a check found: what we found, why it matters, one action and how sure we are.
 */
class Finding {

    /**
     * We know it happens.
     */
    const SURE = 'sure';

    /**
     * It may happen; we can't see far enough to tell.
     */
    const UNSURE = 'unsure';

    /**
     * Worth a look.
     */
    const WARNING = 'warning';

    /**
     * Good to know; not a problem.
     */
    const INFO = 'info';

    /**
     * Action that follows a link.
     */
    const ACTION_LINK = 'link';

    /**
     * Action that runs the check again.
     */
    const ACTION_RECHECK = 'recheck';

    /**
     * Check ID.
     *
     * @var string
     */
    private $check;

    /**
     * Fields, see make().
     *
     * @var array
     */
    private $data;

    /**
     * Create a finding.
     *
     * @param string $check Check ID.
     * @param array  $args  {
     *     @type string $key        Tells findings of one check apart, for example a plugin slug.
     *     @type string $found      What we found (the title).
     *     @type string $why        Why it matters.
     *     @type array  $action     { label, url, type (link|recheck), external, confirm }; confirm is asked before the link opens.
     *     @type string $confidence sure|unsure.
     *     @type string $severity   warning|info.
     *     @type string $label      Short category shown in the pill, for example "Duplicate".
     *     @type array  $tags       Short tags, for example the platform.
     *     @type array  $steps      Ordered steps, plain text.
     *     @type mixed  $evidence   What the finding is based on; a change makes a dismissed finding come back.
     * }
     */
    public function __construct( $check, array $args ) {
        $this->check = self::id( $check );
        $this->data  = array(
            'key'        => isset( $args['key'] ) && is_scalar( $args['key'] ) ? (string) $args['key'] : '',
            'found'      => self::text( isset( $args['found'] ) ? $args['found'] : '' ),
            'why'        => self::text( isset( $args['why'] ) ? $args['why'] : '' ),
            'action'     => self::action( isset( $args['action'] ) ? $args['action'] : array() ),
            'confidence' => isset( $args['confidence'] ) && self::UNSURE === $args['confidence'] ? self::UNSURE : self::SURE,
            'severity'   => isset( $args['severity'] ) && self::INFO === $args['severity'] ? self::INFO : self::WARNING,
            'label'      => self::text( isset( $args['label'] ) ? $args['label'] : '' ),
            'tags'       => self::texts( isset( $args['tags'] ) ? $args['tags'] : array() ),
            'steps'      => self::texts( isset( $args['steps'] ) ? $args['steps'] : array() ),
            'evidence'   => array_key_exists( 'evidence', $args ) ? $args['evidence'] : null,
        );
    }

    /**
     * Check ID.
     *
     * @return string
     */
    public function check() {
        return $this->check;
    }

    /**
     * Whether it is worth a look rather than good to know.
     *
     * @return bool
     */
    public function is_warning() {
        return self::WARNING === $this->data['severity'];
    }

    /**
     * Identity of the finding: the check, its key and its evidence. Dismissals are stored under it.
     *
     * @return string 32 hex characters.
     */
    public function hash() {
        return md5( $this->check . '|' . $this->data['key'] . '|' . wp_json_encode( $this->data['evidence'] ) );
    }

    /**
     * The finding as the doctor route returns it, without the evidence.
     *
     * @return array
     */
    public function to_array() {
        $data = $this->data;

        unset( $data['evidence'] );

        return array_merge(
            array(
                'hash'  => $this->hash(),
                'check' => $this->check,
            ),
            $data
        );
    }

    /**
     * A clean check ID.
     *
     * @param mixed $id Raw ID.
     *
     * @return string
     */
    public static function id( $id ) {
        return is_string( $id ) ? strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $id ) ) : '';
    }

    /**
     * Plain text: tags stripped, whitespace collapsed.
     *
     * @param mixed $text Raw text.
     *
     * @return string
     */
    private static function text( $text ) {
        if ( ! is_scalar( $text ) ) {
            return '';
        }

        return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $text ) ) );
    }

    /**
     * A list of plain texts without empty ones.
     *
     * @param mixed $texts Raw list.
     *
     * @return string[]
     */
    private static function texts( $texts ) {
        return array_values( array_filter( array_map( array( self::class, 'text' ), is_array( $texts ) ? $texts : array() ), 'strlen' ) );
    }

    /**
     * A clean action; a link needs a URL, else it becomes Check again.
     *
     * @param mixed $action Raw action.
     *
     * @return array
     */
    private static function action( $action ) {
        $action = is_array( $action ) ? $action : array();
        $url    = isset( $action['url'] ) && is_string( $action['url'] ) ? self::url( $action['url'] ) : '';
        $type   = '' !== $url && ( ! isset( $action['type'] ) || self::ACTION_RECHECK !== $action['type'] ) ? self::ACTION_LINK : self::ACTION_RECHECK;

        return array(
            'type'     => $type,
            'label'    => self::text( isset( $action['label'] ) ? $action['label'] : '' ),
            'url'      => self::ACTION_LINK === $type ? $url : '',
            'external' => self::ACTION_LINK === $type && ! empty( $action['external'] ),
            'confirm'  => self::text( isset( $action['confirm'] ) ? $action['confirm'] : '' ),
        );
    }

    /**
     * An app route (#/...) or an http(s) URL; anything else is dropped.
     *
     * @param string $url Raw URL.
     *
     * @return string
     */
    private static function url( $url ) {
        $url = trim( $url );

        if ( 0 === strpos( $url, '#/' ) ) {
            return (string) preg_replace( '/[^A-Za-z0-9_\-\/#?=&.]/', '', $url );
        }

        return preg_match( '#^https?://#i', $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
    }
}
