<?php
/**
 * Returns the product a product page reports and each product's identifier per platform. Runs in a test store process (see ProductIdTest).
 */

// phpcs:disable

use StorePulse\ConversionTracking\Events\ProductId;

global $wcct_job;

$viewed = function () {
    $product = ProductId::viewed( wc_get_product( 2002 ) );

    return $product ? $product->get_id() : 0;
};

$result = array( 'no_selection' => $viewed() );

// Within the job's rollback.
$hoodie = wc_get_product( 2002 );
$hoodie->set_default_attributes( array( 'color' => 'Red' ) );
$hoodie->save();

$result['default'] = $viewed();

$_REQUEST['attribute_color'] = 'Blue';
$result['url']               = $viewed();

$_REQUEST['attribute_color'] = 'Green';
$result['unknown']           = $viewed();
unset( $_REQUEST['attribute_color'] );

// A product with two attributes: a partial selection opens no variation.
$attributes = array();

foreach ( array( 'Size' => array( 'S', 'M' ), 'Color' => array( 'Blue', 'Red' ) ) as $name => $options ) {
    $attribute = new WC_Product_Attribute();
    $attribute->set_name( $name );
    $attribute->set_options( $options );
    $attribute->set_visible( true );
    $attribute->set_variation( true );
    $attributes[] = $attribute;
}

$cap = new WC_Product_Variable();
$cap->set_props( array( 'name' => 'Cap', 'status' => 'publish', 'attributes' => $attributes ) );
$cap->save();

$cap_variations = array();

foreach ( array( 'S-Blue', 'M-Blue', 'M-Red' ) as $combo ) {
    list( $size, $color ) = explode( '-', $combo );

    $variation = new WC_Product_Variation();
    $variation->set_props( array( 'parent_id' => $cap->get_id(), 'regular_price' => '10.00', 'status' => 'publish', 'attributes' => array( 'size' => $size, 'color' => $color ) ) );
    $variation->save();

    $cap_variations[ $combo ] = $variation->get_id();
}

WC_Product_Variable::sync( $cap->get_id() );

$viewed_cap = function () use ( $cap ) {
    $product = ProductId::viewed( wc_get_product( $cap->get_id() ) );

    return $product ? $product->get_id() : 0;
};

$_REQUEST['attribute_color'] = 'Blue';
$result['cap']               = array(
    'parent'     => $cap->get_id(),
    'variations' => $cap_variations,
    'partial'    => $viewed_cap(),
);

$cap->set_default_attributes( array( 'size' => 'M' ) );
$cap->save();
$result['cap']['url_and_default'] = $viewed_cap();

$_REQUEST['attribute_color'] = array( 'Blue' );
$result['cap']['array_in_url']   = $viewed_cap();
unset( $_REQUEST['attribute_color'] );

$result['event'] = \StorePulse\ConversionTracking\Events\EventFactory::view_product( wc_get_product( 2002 ) )->get_product_ids();

$red = wc_get_product( 2004 );
$red->set_sku( '' );
$red->save();

foreach ( array( 'facebook', 'ga4', 'twitter' ) as $platform ) {
    foreach ( array( 2001, 2003, 2004 ) as $id ) {
        $result['ids'][ $platform ][ $id ] = ProductId::for_product( wc_get_product( $id ), $platform );
    }
}

return $result;
