<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Migration\Flags;

/**
 * Tests for the migration's "Needs attention" flags read by the platform pages
 */
class FlagsTest extends TestCase {

    const SPLIT = array(
        Flags::GOOGLE_ADS_ID_SPLIT => array(
            'platform'       => 'google',
            'fields'         => array( 'ads_id', 'purchase_label' ),
            'saved'          => 'AW-123456/AbCdEfGhIj',
            'ads_id'         => 'AW-123456',
            'purchase_label' => 'AbCdEfGhIj',
        ),
    );

    public function test_the_google_split_shows_until_the_merchant_changes_the_split_fields() {
        $google = array(
            'mode'           => 'both',
            'ads_id'         => 'AW-123456',
            'purchase_label' => 'AbCdEfGhIj',
        );

        $this->assertSame(
            array( 'saved' => 'AW-123456/AbCdEfGhIj', 'ads_id' => 'AW-123456', 'purchase_label' => 'AbCdEfGhIj' ),
            Flags::google_split( self::SPLIT, $google )
        );
        $this->assertNull( Flags::google_split( self::SPLIT, array_merge( $google, array( 'purchase_label' => 'NewLabel123' ) ) ) );
        $this->assertNull( Flags::google_split( self::SPLIT, array_merge( $google, array( 'ads_id' => '' ) ) ) );
        $this->assertNull( Flags::google_split( array(), $google ), 'no flag: a clean upgrade or a new install' );
        $this->assertNull( Flags::google_split( array( Flags::GOOGLE_ADS_ID_SPLIT => 'broken' ), $google ) );
    }

    public function test_the_split_counts_as_changed_once_either_field_differs() {
        $flag = self::SPLIT[ Flags::GOOGLE_ADS_ID_SPLIT ];

        $this->assertFalse( Flags::google_split_changed( $flag, array( 'ads_id' => 'AW-123456', 'purchase_label' => 'AbCdEfGhIj', 'mode' => 'ads' ) ) );
        $this->assertTrue( Flags::google_split_changed( $flag, array( 'ads_id' => 'AW-123456' ) ), 'the label was cleared' );
        $this->assertTrue( Flags::google_split_changed( $flag, array( 'ads_id' => 'AW-999', 'purchase_label' => 'AbCdEfGhIj' ) ) );
    }
}
