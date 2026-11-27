<?php
/**
 * Set-up for OrderPayTest, run before the page: the job's order waits for payment.
 */

// phpcs:disable

global $wcct_job;

$wcct_pending = wc_get_order( $wcct_job['pending_order'] );
$wcct_pending->set_status( 'pending' );
$wcct_pending->save();
