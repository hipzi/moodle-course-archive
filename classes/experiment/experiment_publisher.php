<?php

namespace local_archive\experiment;

defined('MOODLE_INTERNAL') || die();

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;

final class experiment_publisher {

    public function __construct(
        private readonly AMQPChannel $channel
    ) {
    }

    public function publish(
        array $jobs,
        string $method,
        string $experimentid,
        int $runnumber,
        string $traceid
    ): int {

        scheduling_method::validate($method);

        $published = 0;

        foreach ($jobs as $job) {

            $payload = [
                'jobid' =>
                    $job['jobid'],

                'courseid' =>
                    $job['courseid'],

                'userid' =>
                    $job['userid'],

                'retries' => 0,

                'experimentid' =>
                    $experimentid,

                'method' =>
                    $method,

                'runnumber' =>
                    $runnumber,

                'traceid' =>
                    $traceid,

                'input_sequence' =>
                    $job['input_sequence'],

                'workloadclass' =>
                    $job['workloadclass'],

                'size_mb' =>
                    $job['size_mb'],
            ];

            $properties = [
                'content_type' =>
                    'application/json',

                'delivery_mode' =>
                    AMQPMessage::DELIVERY_MODE_PERSISTENT,

                'message_id' =>
                    $job['jobid'],
            ];

            $priority =
                scheduler::message_priority(
                    $job,
                    $method
                );

            if ($priority !== null) {
                $properties['priority'] = $priority;
            }

            $message = new AMQPMessage(
                json_encode(
                    $payload,
                    JSON_THROW_ON_ERROR
                ),
                $properties
            );

            $this->channel->basic_publish(
                $message,
                queue_topology::EXCHANGE,
                scheduler::routing_key(
                    $job,
                    $method
                )
            );

            $published++;
        }

        return $published;
    }
}
