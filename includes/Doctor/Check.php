<?php

namespace StorePulse\ConversionTracking\Doctor;

/**
 * One Tracking Doctor detector, registered through the wcct_doctor_checks filter.
 */
interface Check {

    /**
     * Reads this site's data only; also runs in the quiet re-check after a plugin is activated.
     */
    const LOCAL = 'local';

    /**
     * Makes HTTP requests (a loopback to this site); runs only when the merchant asks, within the time budget.
     */
    const REMOTE = 'remote';

    /**
     * Stable ID: lowercase letters, digits, - and _.
     *
     * @return string
     */
    public function id();

    /**
     * Line shown in the Working list when nothing is found; read after run().
     *
     * @return string
     */
    public function label();

    /**
     * Check::LOCAL or Check::REMOTE.
     *
     * @return string
     */
    public function scope();

    /**
     * Whether the check applies to this store; checks that don't are left out of "We checked N things".
     *
     * @return bool
     */
    public function applies();

    /**
     * Run the check. It must never change a setting, here or in another plugin.
     *
     * @param Context $context Shared data, the time budget and the loopback helper.
     *
     * @return Finding[] Empty when nothing is found.
     */
    public function run( Context $context );
}
