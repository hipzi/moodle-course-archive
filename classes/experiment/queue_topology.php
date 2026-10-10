<?php

namespace local_archive\experiment;

defined('MOODLE_INTERNAL') || die();

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;

final class queue_topology {

    public const EXCHANGE =
        'course_archive.experiment';

    public const FIFO =
        'course_archive.exp.fifo';

    public const PRIORITY =
        'course_archive.exp.priority';

    public const SJF =
        'course_archive.exp.sjf';

    public const SMALL =
        'course_archive.exp.small';

    public const MEDIUM =
        'course_archive.exp.medium';

    public const LARGE =
        'course_archive.exp.large';

    public const DEAD =
        'course_archive.exp.dead';

    public static function queues(): array {
        return [
            self::FIFO,
            self::PRIORITY,
            self::SJF,
            self::SMALL,
            self::MEDIUM,
            self::LARGE,
            self::DEAD,
        ];
    }

    public static function worker_queues(): array {
        return [
            self::FIFO,
            self::PRIORITY,
            self::SJF,
            self::SMALL,
            self::MEDIUM,
            self::LARGE,
        ];
    }

    public static function declare(
        AMQPChannel $channel
    ): void {

        $channel->exchange_declare(
            self::EXCHANGE,
            'topic',
            false,
            true,
            false
        );

        self::declare_standard_queue(
            $channel,
            self::FIFO
        );

        self::declare_standard_queue(
            $channel,
            self::SJF
        );

        self::declare_standard_queue(
            $channel,
            self::SMALL
        );

        self::declare_standard_queue(
            $channel,
            self::MEDIUM
        );

        self::declare_standard_queue(
            $channel,
            self::LARGE
        );

        self::declare_standard_queue(
            $channel,
            self::DEAD
        );

        $priorityargs = new AMQPTable([
            'x-max-priority' => 3,
        ]);

        $channel->queue_declare(
            self::PRIORITY,
            false,
            true,
            false,
            false,
            false,
            $priorityargs
        );

        $bindings = [
            self::FIFO => 'experiment.fifo',

            self::PRIORITY =>
                'experiment.priority',

            self::SJF =>
                'experiment.sjf',

            self::SMALL =>
                'experiment.class.small',

            self::MEDIUM =>
                'experiment.class.medium',

            self::LARGE =>
                'experiment.class.large',

            self::DEAD =>
                'experiment.dead',
        ];

        foreach ($bindings as $queue => $routingkey) {
            $channel->queue_bind(
                $queue,
                self::EXCHANGE,
                $routingkey
            );
        }
    }

    public static function is_worker_queue(
        string $queue
    ): bool {
        return in_array(
            $queue,
            self::worker_queues(),
            true
        );
    }

    private static function declare_standard_queue(
        AMQPChannel $channel,
        string $queue
    ): void {
        $channel->queue_declare(
            $queue,
            false,
            true,
            false,
            false
        );
    }
}
