<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../vendor/autoload.php');

use local_archive\experiment\queue_topology;
use local_archive\experiment\rabbitmq_connection;

[$options, $unrecognized] = cli_get_params(
    [
        'experiment' => null,
        'help' => false,
    ],
    [
        'h' => 'help',
    ]
);

$experimentid =
    trim((string) $options['experiment']);

if ($experimentid === '') {
    cli_error(
        '--experiment is required.'
    );
}

$jobs = $DB->get_records(
    'local_archive_job',
    [
        'experimentid' =>
            $experimentid,
    ],
    'input_sequence ASC'
);

if (!$jobs) {
    cli_error(
        'Experiment not found: '
        . $experimentid
    );
}

foreach ($jobs as $job) {
    if ($job->status !== 'prepared') {
        cli_error(
            sprintf(
                'Job %s has status %s. '
                . 'All jobs must be prepared.',
                $job->jobid,
                $job->status
            )
        );
    }
}

// Safety check: workers must still be stopped.
$connection =
    rabbitmq_connection::create();

$channel =
    $connection->channel();

queue_topology::declare($channel);

foreach (queue_topology::worker_queues() as $queue) {

    [
        $queuename,
        $messagecount,
        $consumercount,
    ] = $channel->queue_declare(
        $queue,
        true
    );

    if ($consumercount > 0) {
        cli_error(
            sprintf(
                'Queue %s has %d active consumer(s).',
                $queue,
                $consumercount
            )
        );
    }
}

$releasedms =
    (int) round(microtime(true) * 1000);

$nowsec = time();

$DB->execute(
    "
    UPDATE {local_archive_job}
       SET releasedat_ms = :releasedat,
           status = :status,
           timemodified = :timemodified,
           timemodified_ms = :timemodifiedms
     WHERE experimentid = :experimentid
       AND status = :oldstatus
    ",
    [
        'releasedat' =>
            $releasedms,

        'status' =>
            'queued',

        'timemodified' =>
            $nowsec,

        'timemodifiedms' =>
            $releasedms,

        'experimentid' =>
            $experimentid,

        'oldstatus' =>
            'prepared',
    ]
);

$channel->close();
$connection->close();

echo PHP_EOL;
echo "Experiment released." . PHP_EOL;
echo "Experiment ID : $experimentid" . PHP_EOL;
echo "Jobs          : " . count($jobs) . PHP_EOL;
echo "Release ms    : $releasedms" . PHP_EOL;
echo PHP_EOL;
echo "Start the configured workers now." . PHP_EOL;
