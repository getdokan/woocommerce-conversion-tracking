<?php

require_once __DIR__ . '/DoctorCheckTestCase.php';

use StorePulse\ConversionTracking\Doctor\Checks\IdFormatsCheck;

/**
 * Saved IDs that don't match their format, and IDs a Purchase needs that are missing
 */
class IdFormatsCheckTest extends DoctorCheckTestCase {

    private function valid() {
        return array(
            'facebook' => array( 'pixel_id' => '123456789012345' ),
            'google'   => array( 'mode' => 'both', 'measurement_id' => 'G-ABC123', 'ads_id' => 'AW-123456', 'purchase_label' => 'AbC_1-x', 'tag_id' => 'GT-XYZ' ),
            'twitter'  => array( 'pixel_id' => 'o8x2k', 'purchase_event_id' => 'tw-o8x2k-abc12' ),
        );
    }

    public function test_valid_ids_give_nothing() {
        $this->platforms( $this->valid() );

        $this->assertSame( array(), $this->run_check( new IdFormatsCheck() ) );
        $this->assertSame( 'Your platform IDs look valid', ( new IdFormatsCheck() )->label() );
    }

    /**
     * @dataProvider malformed_ids
     */
    public function test_a_malformed_id_links_to_its_field( $platform, $field, $value, $title ) {
        $platforms                        = $this->valid();
        $platforms[ $platform ][ $field ] = $value;
        $this->platforms( $platforms );

        $findings = $this->run_check( new IdFormatsCheck() );

        $this->assertCount( 1, $findings );
        $this->assertSame( $title, $findings[0]['found'] );
        $this->assertSame( array( 'link', '#/platforms/' . $platform . '?field=' . $field ), array( $findings[0]['action']['type'], $findings[0]['action']['url'] ) );
        $this->assertSame( array( 'sure', 'warning', 'ID' ), array( $findings[0]['confidence'], $findings[0]['severity'], $findings[0]['label'] ) );
    }

    public function malformed_ids() {
        return array(
            'Meta access token'  => array( 'facebook', 'pixel_id', 'EAAB12345', 'Your Meta Pixel ID doesn\'t look right' ),
            'GA4 UA id'          => array( 'google', 'measurement_id', 'UA-12345-1', 'Your Google Analytics Measurement ID doesn\'t look right' ),
            'GT tag id'          => array( 'google', 'tag_id', 'G-NOTGT', 'Your Google tag ID doesn\'t look right' ),
            'AW id'              => array( 'google', 'ads_id', 'AW-12AB', 'Your Google Ads conversion ID doesn\'t look right' ),
            'Ads label with id'  => array( 'google', 'purchase_label', 'AW-123/abc', 'Your Google Ads conversion label doesn\'t look right' ),
            'X pixel'            => array( 'twitter', 'pixel_id', 'TOO-LONG-1', 'Your X Pixel ID doesn\'t look right' ),
            'X purchase eventid' => array( 'twitter', 'purchase_event_id', 'o8x2k-abc', 'Your X Purchase event ID doesn\'t look right' ),
        );
    }

    public function test_a_purchase_needs_the_ads_label_and_the_x_event_id() {
        $platforms                                  = $this->valid();
        $platforms['google']['purchase_label']      = '';
        $platforms['twitter']['purchase_event_id'] = '';
        $this->platforms( $platforms );

        $findings = $this->run_check( new IdFormatsCheck() );

        $this->assertSame( array( 'Your Google Ads conversion label looks incomplete', 'Your X Purchase event ID looks incomplete' ), array_column( $findings, 'found' ) );
        $this->assertSame( 'Google can\'t count purchases for your campaigns until the Ads conversion label is filled in.', $findings[0]['why'] );
    }

    public function test_not_applicable_ids_are_left_alone() {
        $platforms = $this->valid();

        // Analytics only: the Ads fields don't apply.
        $platforms['google'] = array( 'mode' => 'analytics', 'measurement_id' => 'G-ABC123', 'ads_id' => 'junk', 'purchase_label' => '' );
        // Purchase off: X doesn't need its Purchase event ID.
        $platforms['twitter'] = array( 'pixel_id' => 'o8x2k', 'purchase_event_id' => '', 'events' => array( 'page_view' ) );
        // A Pro event ID is locked on Free.
        $platforms['twitter']['cart_event_id'] = 'junk';
        $this->platforms( $platforms );

        $this->assertSame( array(), $this->run_check( new IdFormatsCheck() ) );
    }

    public function test_what_the_save_accepts_is_not_reported() {
        $platforms                               = $this->valid();
        $platforms['google']['measurement_id']   = 'g-abc123';
        $platforms['twitter']['pixel_id']        = 'O8X2K';
        $platforms['facebook']['pixel_id']       = ' 123456789012345 ';
        $this->platforms( $platforms );

        $this->assertSame( array(), $this->run_check( new IdFormatsCheck() ), 'The save cleans and cases these the same way' );
    }

    public function test_a_pattern_that_does_not_compile_is_skipped() {
        $this->platforms( $this->valid() );
        $this->filter(
            'wcct_doctor_ids',
            function ( $ids ) {
                $ids[] = array( 'platform' => 'facebook', 'platform_label' => 'Meta', 'field' => 'broken', 'label' => 'Broken', 'value' => 'abc', 'pattern' => '([', 'hint' => '' );

                return $ids;
            }
        );

        $this->assertSame( array(), $this->run_check( new IdFormatsCheck() ) );
    }

    public function test_ids_from_the_filter_are_checked_too() {
        $this->platforms( $this->valid() );
        $this->filter(
            'wcct_doctor_ids',
            function ( $ids ) {
                $ids[] = array( 'platform' => 'facebook', 'platform_label' => 'Meta', 'field' => 'pixels', 'label' => 'Second pixel ID', 'value' => '12', 'pattern' => '^[0-9]{15,16}$', 'hint' => '' );

                return $ids;
            }
        );

        $this->assertSame( array( 'Your Meta Second pixel ID doesn\'t look right' ), array_column( $this->run_check( new IdFormatsCheck() ), 'found' ) );
    }
}
