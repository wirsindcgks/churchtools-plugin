<?php

/**
 * Deliberately not a full WordPress test bootstrap (wp-phpunit, a test database,
 * wp-load.php, ...) — these tests target the plugin's pure/near-pure logic
 * (Crypto roundtrip, input sanitization, API response mapping), not anything that
 * needs a running WordPress. Pulling in the full WP test suite for that would be a
 * lot of infrastructure (and CI time) for functions that don't touch the database,
 * hooks, or output. Instead, only the handful of WP functions these specific
 * classes call are stubbed below — see each stub's comment for why it's needed.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Crypto::key() derives its encryption key from AUTH_KEY when defined, so a fixed
// test value keeps encrypt()/decrypt() deterministic without needing wp_salt().
define('AUTH_KEY', 'phpunit-test-auth-key-do-not-use-in-production');

/**
 * In-memory stand-in for the options table, only as deep as SettingsPage::get()
 * needs: reading a single named option. Tests populate it via ctp_test_set_option()
 * before exercising code that calls SettingsPage::get()/resolveCalendarIds().
 */
$GLOBALS['ctp_test_options'] = [];

function ctp_test_set_option(string $name, $value): void
{
    $GLOBALS['ctp_test_options'][$name] = $value;
}

function ctp_test_reset_options(): void
{
    $GLOBALS['ctp_test_options'] = [];
}

function get_option(string $name, $default = false)
{
    return $GLOBALS['ctp_test_options'][$name] ?? $default;
}

/**
 * Mirrors WP core's actual merge behaviour (array args override matching default
 * keys, defaults fill in the rest) closely enough for SettingsPage::get()'s use —
 * it always passes two arrays, never the string/object forms wp_parse_args() also
 * accepts.
 */
function wp_parse_args($args, $defaults = [])
{
    $parsedArgs = is_array($args) ? $args : (array) $args;

    return array_merge($defaults, $parsedArgs);
}

/**
 * SyncEngine::toMysqlDate() converts ChurchTools' UTC timestamps into this
 * timezone before storing them. Fixed to a non-UTC zone so a test asserting on the
 * converted value would actually catch a missing/broken conversion.
 */
function wp_timezone(): DateTimeZone
{
    return new DateTimeZone('Europe/Berlin');
}
