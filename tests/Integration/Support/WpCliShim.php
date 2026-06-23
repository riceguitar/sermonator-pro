<?php

declare(strict_types=1);

namespace Sermonator\Tests\Integration\Support;

/**
 * A tiny stand-in for the global `WP_CLI` class so the thin
 * Sermonator\Cli\MigrationCommand methods run under the wp-env phpunit runtime —
 * which is a plain `php` process where WP_CLI is NOT defined.
 *
 * The command guards every output call with class_exists('WP_CLI'); installing this
 * shim makes that guard true so the command's reporting path is exercised, and lets
 * the test assert exactly what the operator would have seen.
 *
 *  - log / line / success / warning : append to a captured buffer (readable via
 *    WpCliShim::output()).
 *  - error : appends, then halts like the real runner by throwing an ExitException
 *    (the command must therefore reserve error() for genuinely fatal cases, not for
 *    service refusals, which it reports as warnings).
 *  - The real `\WP_CLI` global class is aliased to this shim so the command's
 *    `\WP_CLI::log(...)` resolves here.
 */
final class WpCliShim {
    /** @var list<string> */
    private static array $buffer = array();

    /**
     * Alias this shim to the global \WP_CLI class name (once) so the production
     * command's \WP_CLI:: static calls resolve to the shim under phpunit.
     */
    public static function install(): void {
        if ( ! class_exists( '\\WP_CLI', false ) ) {
            class_alias( self::class, '\\WP_CLI' );
        }
    }

    public static function reset(): void {
        self::$buffer = array();
    }

    /** The full captured operator-facing output, newline-joined. */
    public static function output(): string {
        return implode( "\n", self::$buffer );
    }

    // --- WP_CLI surface the command uses -------------------------------------

    public static function log( string $message ): void {
        self::$buffer[] = $message;
    }

    public static function line( string $message = '' ): void {
        self::$buffer[] = $message;
    }

    public static function success( string $message ): void {
        self::$buffer[] = 'Success: ' . $message;
    }

    public static function warning( string $message ): void {
        self::$buffer[] = 'Warning: ' . $message;
    }

    /**
     * Mirrors WP_CLI::error()'s halting behaviour. The command should NOT call this
     * for ordinary service refusals (those are warnings) — only for genuinely fatal
     * preconditions — so a test that hits error() is asserting a fatal path.
     */
    public static function error( string $message ): void {
        self::$buffer[] = 'Error: ' . $message;
        throw new \RuntimeException( 'WP_CLI::error: ' . $message );
    }
}
