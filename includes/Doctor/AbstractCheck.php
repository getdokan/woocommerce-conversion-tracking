<?php

namespace StorePulse\ConversionTracking\Doctor;

/**
 * Base for checks: local, always applies, and a helper that makes findings for this check.
 */
abstract class AbstractCheck implements Check {

    /**
     * Reads local data.
     *
     * @return string
     */
    public function scope() {
        return self::LOCAL;
    }

    /**
     * Applies to every store.
     *
     * @return bool
     */
    public function applies() {
        return true;
    }

    /**
     * A finding of this check.
     *
     * @param array $args See Finding::__construct().
     *
     * @return Finding
     */
    protected function finding( array $args ) {
        return new Finding( $this->id(), $args );
    }
}
