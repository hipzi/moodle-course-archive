<?php

namespace local_archive\experiment;

defined('MOODLE_INTERNAL') || die();

final class workload_classifier {
    public const SMALL = 'small';
    public const MEDIUM = 'medium';
    public const LARGE = 'large';

    public const SMALL_MAX_MB = 200.0;
    public const MEDIUM_MAX_MB = 600.0;

    public static function classify(float $sizemb): string {
        if ($sizemb <= self::SMALL_MAX_MB) {
            return self::SMALL;
        }

        if ($sizemb <= self::MEDIUM_MAX_MB) {
            return self::MEDIUM;
        }

        return self::LARGE;
    }

    public static function priority(string $class): int {
        return match ($class) {
            self::SMALL => 3,
            self::MEDIUM => 2,
            self::LARGE => 1,

            default => throw new \invalid_argument_exception(
                'Unknown workload class: ' . $class
            ),
        };
    }
}
