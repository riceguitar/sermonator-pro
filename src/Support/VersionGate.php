<?php

declare(strict_types=1);

namespace Sermonator\Support;

final class VersionGate {
    public const MIN_PHP = '8.1';
    public const MIN_WP  = '6.0';

    public function __construct(
        private readonly string $phpVersion,
        private readonly string $wpVersion
    ) {}

    public function isSatisfied(): bool {
        return version_compare( $this->phpVersion, self::MIN_PHP, '>=' )
            && version_compare( $this->wpVersion, self::MIN_WP, '>=' );
    }

    public function failureMessage(): string {
        if ( version_compare( $this->phpVersion, self::MIN_PHP, '>=' ) && version_compare( $this->wpVersion, self::MIN_WP, '>=' ) ) {
            return '';
        }

        $problems = array();
        if ( version_compare( $this->phpVersion, self::MIN_PHP, '<' ) ) {
            $problems[] = sprintf( 'PHP %s+ (you have %s)', self::MIN_PHP, $this->phpVersion );
        }
        if ( version_compare( $this->wpVersion, self::MIN_WP, '<' ) ) {
            $problems[] = sprintf( 'WordPress %s+ (you have %s)', self::MIN_WP, $this->wpVersion );
        }

        return 'Sermonator requires ' . implode( ' and ', $problems )
            . '. The plugin will not run until you update; your existing sermon data is untouched.';
    }
}
