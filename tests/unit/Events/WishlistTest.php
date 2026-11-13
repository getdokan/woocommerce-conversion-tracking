<?php

use PHPUnit\Framework\TestCase;
use StorePulse\ConversionTracking\Events\Wishlist;

/**
 * Tests for the wishlist helpers
 */
class WishlistTest extends TestCase {

    public function test_the_variation_is_the_product_added() {
        $this->assertSame( 2004, Wishlist::product_id( 2002, 2004 ) );
        $this->assertSame( 2001, Wishlist::product_id( '2001', '' ) );
        $this->assertSame( 2001, Wishlist::product_id( 2001, 0 ) );
    }

    public function test_bad_ids_read_as_zero() {
        $this->assertSame( 0, Wishlist::product_id( 'abc' ) );
        $this->assertSame( 0, Wishlist::product_id( -3, null ) );
        $this->assertSame( 0, Wishlist::product_id( array( 1 ), array( 2 ) ) );
    }

    public function test_the_triggers_cover_both_yith_rendering_methods() {
        $this->assertSame( array( 'added_to_wishlist', 'yith_wcwl_reload_fragments' ), Wishlist::TRIGGERS );
    }
}
