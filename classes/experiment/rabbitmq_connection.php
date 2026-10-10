<?php

namespace local_archive\experiment;

defined('MOODLE_INTERNAL') || die();

use PhpAmqpLib\Connection\AMQPStreamConnection;

final class rabbitmq_connection {

    public static function create(): AMQPStreamConnection {

        $host = getenv('RABBITMQ_HOST');
        $port = (int) (getenv('RABBITMQ_PORT') ?: 5672);
        $user = getenv('RABBITMQ_USER');
        $password = getenv('RABBITMQ_PASSWORD');
        $vhost = getenv('RABBITMQ_VHOST') ?: '/';

        if (!$host) {
            throw new \RuntimeException(
                'RABBITMQ_HOST is required.'
            );
        }

        if (!$user) {
            throw new \RuntimeException(
                'RABBITMQ_USER is required.'
            );
        }

        if ($password === false || $password === '') {
            throw new \RuntimeException(
                'RABBITMQ_PASSWORD is required.'
            );
        }

        return new AMQPStreamConnection(
            $host,
            $port,
            $user,
            $password,
            $vhost
        );
    }
}
