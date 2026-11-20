<?php

use PHPUnit\Runner\BeforeTestHook;
use StorePulse\ConversionTracking\Platforms\PlatformSettings;

/**
 * Forgets the settings read per request before each unit test, as a new request would.
 */
final class WCCT_Test_Reset_Statics implements BeforeTestHook {

    public function executeBeforeTest( string $test ): void {
        PlatformSettings::reset();
    }
}
