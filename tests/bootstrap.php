<?php
/**
 * Unit test bootstrap
 *
 * Loads only the pure helper classes. WordPress and WooCommerce are not loaded.
 */
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-ga4-data.php';
require_once __DIR__ . '/unit/Fakes/InMemoryOptionStore.php';
require_once __DIR__ . '/unit/Fakes/InMemorySecretStorage.php';
