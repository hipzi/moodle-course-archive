<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require(__DIR__ . '/../vendor/autoload.php');

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$queue = $argv[1] ?? null;

if (!$queue) {
    echo "Usage: php worker_experiment.php <queue>\n";
    echo "Example: php worker_experiment.php archive.single\n";
    exit(1);
}

$exchange = 'archive.direct';

$routingkey = match ($queue) {
    'archive.small'  => 'backup.course.small',
    'archive.medium' => 'backup.course.medium',
    'archive.large'  => 'backup.course.large',
    'archive.single' => 'backup.course.single',
    default          => $queue,
};

echo "[EXP] Starting worker for queue={$queue} (exchange={$exchange}, routing={$routingkey})\n";

$rabbithost = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
$rabbitport = (int)(getenv('RABBITMQ_PORT') ?: 5672);
$rabbituser = getenv('RABBITMQ_USER') ?: 'guest';
$rabbitpass = getenv('RABBITMQ_PASSWORD') ?: 'guest';
$rabbitvhost = getenv('RABBITMQ_VHOST') ?: '/';

$connection = new AMQPStreamConnection(
    $rabbithost,
    $rabbitport,
    $rabbituser,
    $rabbitpass,
    $rabbitvhost
);

$channel = $connection->channel();

$channel->exchange_declare($exchange, 'direct', false, true, false);

$channel->queue_declare($queue, false, true, false, false);
$channel->queue_bind($queue, $exchange, $routingkey);

$channel->queue_declare('archive.dead', false, true, false, false);
$channel->queue_bind('archive.dead', $exchange, 'backup.course.dead');

$channel->basic_qos(null, 1, null);

function archive_update_job(string $jobid, array $fields): void {
    global $DB;

    $job = $DB->get_record('local_archive_job', ['jobid' => $jobid], '*', IGNORE_MISSING);
    if (!$job) {
        return;
    }

    foreach ($fields as $k => $v) {
        $job->$k = $v;
    }

    $job->timemodified = time();

    if (!array_key_exists('timemodified_ms', $fields)) {
        $job->timemodified_ms = (int) round(microtime(true) * 1000);
    }

    $DB->update_record('local_archive_job', $job);
}

$callback = function (AMQPMessage $msg) use ($queue, $channel, $exchange, $routingkey) {
    global $CFG, $DB;

    echo "----\n";
    echo "[EXP] Received message from {$queue}\n";

    $data = json_decode($msg->body, true) ?: [];

    $jobid    = $data['jobid']    ?? '(no-jobid)';
    $courseid = (int)($data['courseid'] ?? 0);
    $userid   = (int)($data['userid'] ?? 0);
    $retries  = (int)($data['retries'] ?? 0);

    echo "[EXP] jobid={$jobid} courseid={$courseid} userid={$userid} retries={$retries}\n";

    $dirroot = realpath(__DIR__ . '/../../../../');

    $archivebase = $CFG->dataroot . "/archive";
    if (!is_dir($archivebase)) {
        mkdir($archivebase, 0777, true);
    }

    $courseDir = "{$archivebase}/{$courseid}";
    if (!is_dir($courseDir)) {
        mkdir($courseDir, 0777, true);
    }

    $dest = "{$courseDir}/{$jobid}";
    if (!is_dir($dest)) {
        mkdir($dest, 0777, true);
    }

    archive_update_job($jobid, [
        'status'          => 'running',
        'timemodified_ms' => (int) round(microtime(true) * 1000),
    ]);

    $job = $DB->get_record('local_archive_job', ['jobid' => $jobid], 'timecreated_ms', IGNORE_MISSING);
    $queued_ms = ($job && !empty($job->timecreated_ms))
        ? (int)$job->timecreated_ms
        : (int) round(microtime(true) * 1000);

    $cmd = "php8.2 {$dirroot}/admin/cli/backup.php --courseid={$courseid} --destination=\"{$dest}\" 2>&1";
    echo "[EXP] Running backup command:\n{$cmd}\n";

    $output = [];
    $return = 0;

    $serviceStart = microtime(true);
    exec($cmd, $output, $return);
    $serviceDuration = round(microtime(true) - $serviceStart, 3);

    echo "[EXP] Return code: {$return}\n";
    echo "[EXP] Output:\n" . implode("\n", $output) . "\n";

    if ($return === 0) {
        $mbzpath = null;
        foreach (glob($dest . '/*.mbz') as $f) {
            $mbzpath = $f;
            break;
        }

        $finished_ms   = (int) round(microtime(true) * 1000);
        $totalDuration = round(($finished_ms - $queued_ms) / 1000, 3);

        $fields = [
            'status'          => 'success',
            'duration_sec'    => $serviceDuration,
            'total_sec'       => $totalDuration,
            'timemodified_ms' => $finished_ms,
        ];

        if ($mbzpath && is_readable($mbzpath)) {
            $fields['mbz_size_mb'] = round(filesize($mbzpath) / (1024 * 1024), 2);
            $fields['filepath']    = $mbzpath;
        }

        archive_update_job($jobid, $fields);

        $DB->execute(
            "UPDATE {local_archive_job}
                SET status = :status,
                    duration_sec = :duration_sec,
                    total_sec = :total_sec,
                    timemodified_ms = :timemodified_ms
            WHERE jobid = :jobid",
            [
                'status'          => 'success',
                'duration_sec'    => $serviceDuration,
                'total_sec'       => $totalDuration,
                'timemodified_ms' => $finished_ms,
                'jobid'           => $jobid,
            ]
        );

        echo "[EXP] SUCCESS job={$jobid} duration={$serviceDuration}s total={$totalDuration}s dest={$dest}\n";
        $msg->ack();
        echo "----\n";
        return;
    }

    echo "[EXP] FAILED job={$jobid} course={$courseid}\n";

    if ($retries < 3) {
        $data['retries'] = $retries + 1;

        archive_update_job($jobid, [
            'status'  => 'retry',
            'retries' => $data['retries'],
        ]);

        $retryMsg = new AMQPMessage(json_encode($data), $msg->get_properties());
        $channel->basic_publish($retryMsg, $exchange, $routingkey);

        echo "[EXP] Requeued job={$jobid} retries={$data['retries']} rk={$routingkey}\n";
    } else {
        $data['failed_at'] = time();

        archive_update_job($jobid, [
            'status' => 'failed',
        ]);

        $deadMsg = new AMQPMessage(json_encode($data), $msg->get_properties());
        $channel->basic_publish($deadMsg, $exchange, 'backup.course.dead');

        echo "[EXP] Moved job={$jobid} to archive.dead after {$retries} retries\n";
    }

    $msg->ack();
    echo "----\n";
};

$channel->basic_consume($queue, '', false, false, false, false, $callback);

while ($channel->is_open()) {
    $channel->wait();
}
