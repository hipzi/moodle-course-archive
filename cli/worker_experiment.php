<?php

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once(__DIR__ . '/../vendor/autoload.php');

use local_archive\experiment\queue_topology;
use local_archive\experiment\rabbitmq_connection;

use PhpAmqpLib\Message\AMQPMessage;

$queue =
    trim((string) getenv('QUEUE_NAME'));

$workerid =
    trim((string) getenv('WORKER_ID'));

if ($queue === '') {
    throw new RuntimeException(
        'QUEUE_NAME is required.'
    );
}

if ($workerid === '') {
    throw new RuntimeException(
        'WORKER_ID is required.'
    );
}

if (!queue_topology::is_worker_queue($queue)) {
    throw new RuntimeException(
        'Invalid experiment queue: '
        . $queue
    );
}

echo sprintf(
    "[WORKER] id=%s queue=%s\n",
    $workerid,
    $queue
);

$connection =
    rabbitmq_connection::create();

$channel =
    $connection->channel();

queue_topology::declare(
    $channel
);

$channel->basic_qos(
    null,
    1,
    null
);

function experiment_update_job(
    string $jobid,
    array $fields
): void {
    global $DB;

    $job = $DB->get_record(
        'local_archive_job',
        ['jobid' => $jobid],
        '*',
        MUST_EXIST
    );

    foreach ($fields as $field => $value) {
        $job->$field = $value;
    }

    $job->timemodified = time();

    if (
        !array_key_exists(
            'timemodified_ms',
            $fields
        )
    ) {
        $job->timemodified_ms =
            (int) round(
                microtime(true) * 1000
            );
    }

    $DB->update_record(
        'local_archive_job',
        $job
    );
}

$callback = function(
    AMQPMessage $msg
) use (
    $queue,
    $workerid,
    $channel
) {
    global $CFG, $DB;

    echo PHP_EOL;
    echo "----------------------------------------" . PHP_EOL;
    echo "[WORKER] Received message from $queue" . PHP_EOL;

    try {
        $data = json_decode(
            $msg->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (\JsonException $e) {
        echo "[WORKER] Invalid JSON" . PHP_EOL;

        $msg->reject(false);
        return;
    }

    $jobid =
        (string) ($data['jobid'] ?? '');

    $courseid =
        (int) ($data['courseid'] ?? 0);

    $userid =
        (int) ($data['userid'] ?? 0);

    if (
        $jobid === ''
        || $courseid <= 0
        || $userid <= 0
    ) {
        echo "[WORKER] Invalid payload" . PHP_EOL;

        $msg->reject(false);
        return;
    }

    $job = $DB->get_record(
        'local_archive_job',
        ['jobid' => $jobid],
        '*',
        IGNORE_MISSING
    );

    if (!$job) {
        echo "[WORKER] DB job not found: $jobid" . PHP_EOL;

        $msg->reject(false);
        return;
    }

    // Simple duplicate protection.
    if ($job->status === 'success') {
        echo "[WORKER] Job already completed: $jobid" . PHP_EOL;

        $msg->ack();
        return;
    }

    $startedms =
        (int) round(
            microtime(true) * 1000
        );

    experiment_update_job(
        $jobid,
        [
            'status' => 'running',

            'startedat_ms' =>
                $startedms,

            'workerid' =>
                $workerid,

            'timemodified_ms' =>
                $startedms,
        ]
    );

    echo sprintf(
        "[WORKER] job=%s course=%d worker=%s\n",
        $jobid,
        $courseid,
        $workerid
    );

    $archivebase =
        $CFG->dataroot . '/archive';

    if (!is_dir($archivebase)) {
        mkdir(
            $archivebase,
            0770,
            true
        );
    }

    $coursedir =
        $archivebase . '/'
        . $courseid;

    if (!is_dir($coursedir)) {
        mkdir(
            $coursedir,
            0770,
            true
        );
    }

    $dest =
        $coursedir . '/'
        . $jobid;

    if (!is_dir($dest)) {
        mkdir(
            $dest,
            0770,
            true
        );
    }

    // Same Moodle backup mechanism as the existing experiment worker.
    $dirroot =
        realpath(
            __DIR__ . '/../../../../'
        );

    if ($dirroot === false) {
        throw new RuntimeException(
            'Unable to resolve Moodle root.'
        );
    }

    $backupscript =
        $dirroot
        . '/admin/cli/backup.php';

    $cmd =
        escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($backupscript)
        . ' --courseid='
        . $courseid
        . ' --destination='
        . escapeshellarg($dest)
        . ' 2>&1';

    echo "[WORKER] Starting Moodle backup" . PHP_EOL;

    $output = [];
    $returncode = 0;

    $servicestart =
        microtime(true);

    exec(
        $cmd,
        $output,
        $returncode
    );

    $serviceend =
        microtime(true);

    $duration =
        round(
            $serviceend - $servicestart,
            3
        );

    $completedms =
        (int) round(
            $serviceend * 1000
        );

    $releasedms =
        !empty($job->releasedat_ms)
            ? (int) $job->releasedat_ms
            : (int) $job->timecreated_ms;

    $totalsec =
        round(
            (
                $completedms
                - $releasedms
            ) / 1000,
            3
        );

    if ($returncode === 0) {

        $mbzpath = null;

        foreach (
            glob($dest . '/*.mbz')
            as $file
        ) {
            $mbzpath = $file;
            break;
        }

        $fields = [
            'status' =>
                'success',

            'duration_sec' =>
                $duration,

            'total_sec' =>
                $totalsec,

            'completedat_ms' =>
                $completedms,

            'timemodified_ms' =>
                $completedms,
        ];

        if (
            $mbzpath
            && is_readable($mbzpath)
        ) {
            $fields['mbz_size_mb'] =
                round(
                    filesize($mbzpath)
                    / (1024 * 1024),
                    2
                );

            $fields['filepath'] =
                $mbzpath;
        }

        experiment_update_job(
            $jobid,
            $fields
        );

        echo sprintf(
            "[WORKER] SUCCESS job=%s duration=%.3fs total=%.3fs\n",
            $jobid,
            $duration,
            $totalsec
        );

        $msg->ack();

        return;
    }

    echo sprintf(
        "[WORKER] FAILED job=%s return=%d\n",
        $jobid,
        $returncode
    );

    experiment_update_job(
        $jobid,
        [
            'status' =>
                'failed',

            'retries' =>
                ((int) $job->retries) + 1,

            'duration_sec' =>
                $duration,

            'total_sec' =>
                $totalsec,

            'completedat_ms' =>
                $completedms,

            'timemodified_ms' =>
                $completedms,
        ]
    );

    // Failed experiment jobs go to a separate DLQ.
    // They are not automatically retried because a failed
    // measured run should be rerun as a complete attempt.
    $data['failed_at_ms'] =
        $completedms;

    $deadmessage =
        new AMQPMessage(
            json_encode(
                $data,
                JSON_THROW_ON_ERROR
            ),
            [
                'content_type' =>
                    'application/json',

                'delivery_mode' =>
                    AMQPMessage::DELIVERY_MODE_PERSISTENT,

                'message_id' =>
                    $jobid,
            ]
        );

    $channel->basic_publish(
        $deadmessage,
        queue_topology::EXCHANGE,
        'experiment.dead'
    );

    $msg->ack();
};

$channel->basic_consume(
    $queue,
    '',
    false,
    false,
    false,
    false,
    $callback
);

echo "[WORKER] Waiting for messages..." . PHP_EOL;

while ($channel->is_open()) {
    $channel->wait();
}
