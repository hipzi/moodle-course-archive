<?php

namespace local_archive\experiment;

defined('MOODLE_INTERNAL') || die();

final class scheduling_method {
    public const FIFO = 'fifo';
    public const NATIVE_PRIORITY = 'native_priority';
    public const SIZE_SJF = 'size_sjf';
    public const MULTICLASS = 'multiclass';

    public const ALL = [
        self::FIFO,
        self::NATIVE_PRIORITY,
        self::SIZE_SJF,
        self::MULTICLASS,
    ];

    public static function validate(string $method): void {
        if (!in_array($method, self::ALL, true)) {
            throw new \invalid_argument_exception(
                'Unknown scheduling method: ' . $method
            );
        }
    }
}
