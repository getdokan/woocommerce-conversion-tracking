<?php
/**
 * Unit test bootstrap
 *
 * Loads only the pure helper classes. WordPress and WooCommerce are not loaded; a few of their functions are stubbed.
 */
require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-ga4-data.php';
require_once __DIR__ . '/unit/Fakes/InMemoryOptionStore.php';
require_once __DIR__ . '/unit/Fakes/InMemorySecretStorage.php';
require_once __DIR__ . '/unit/Fakes/wp-functions.php';
require_once dirname( __DIR__ ) . '/includes/class-abstract-integration.php';
require_once __DIR__ . '/unit/Fakes/FakeIntegration.php';
require_once __DIR__ . '/unit/Fakes/FakeOrder.php';
require_once __DIR__ . '/unit/Fakes/FakeSession.php';
