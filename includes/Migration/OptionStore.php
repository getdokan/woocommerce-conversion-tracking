<?php

namespace StorePulse\ConversionTracking\Migration;

/**
 * Option access used by the migration trigger and its lock.
 */
interface OptionStore {

    /**
     * Whether the option exists.
     *
     * @param string $name  Option name.
     * @param bool   $fresh Read the database instead of the cache.
     *
     * @return bool
     */
    public function exists( string $name, bool $fresh = false ): bool;

    /**
     * Raw value read from the database, null when missing.
     *
     * @param string $name Option name.
     *
     * @return string|null
     */
    public function read( string $name ): ?string;

    /**
     * Atomically insert the option, failing when it already exists.
     *
     * @param string $name  Option name.
     * @param string $value Raw value.
     *
     * @return bool
     */
    public function insert( string $name, string $value ): bool;

    /**
     * Atomically swap the value, only while it still equals $expected.
     *
     * @param string $name     Option name.
     * @param string $expected Value the caller last read.
     * @param string $value    New value.
     *
     * @return bool
     */
    public function replace( string $name, string $expected, string $value ): bool;

    /**
     * Delete the option, only while its value still equals $expected.
     *
     * @param string $name     Option name.
     * @param string $expected Value the caller wrote.
     *
     * @return bool
     */
    public function delete( string $name, string $expected ): bool;

    /**
     * Save an autoloaded option through the regular options API.
     *
     * @param string $name  Option name.
     * @param string $value Value.
     *
     * @return bool
     */
    public function save( string $name, string $value ): bool;
}
