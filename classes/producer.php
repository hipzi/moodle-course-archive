<?php
namespace local_archive;

defined('MOODLE_INTERNAL') || die();

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use context_course;

class producer {

    private $connection;
    private $channel;

    private string $host   = getenv('RABBITMQ_HOST') ?: '127.0.0.1';
    private int    $port   = (int)(getenv('RABBITMQ_PORT') ?: 5672);
    private string $user   = getenv('RABBITMQ_USER') ?: 'guest';
    private string $pass   = getenv('RABBITMQ_PASSWORD') ?: 'guest';
    private string $vhost  = getenv('RABBITMQ_VHOST') ?: '/';

    private string $exchange = 'archive.direct';

    public function __construct() {
        $this->connection = new AMQPStreamConnection(
            $this->host,
            $this->port,
            $this->user,
            $this->pass,
            $this->vhost
        );
        $this->channel = $this->connection->channel();

        $this->channel->exchange_declare(
            $this->exchange,
            'direct',
            false,
            true,
            false
        );

        $this->declare_queue('archive.small');
        $this->declare_queue('archive.medium');
        $this->declare_queue('archive.large');
        $this->declare_queue('archive.single');
        $this->declare_queue('archive.dead');

        $this->bind('archive.small',  'backup.course.small');
        $this->bind('archive.medium', 'backup.course.medium');
        $this->bind('archive.large',  'backup.course.large');
        $this->bind('archive.single', 'backup.course.single');
        $this->bind('archive.dead',   'backup.course.dead');
    }

    private function declare_queue(string $name): void {
        $this->channel->queue_declare(
            $name,
            false,
            true,
            false,
            false
        );
    }

    private function bind(string $queue, string $rk): void {
        $this->channel->queue_bind($queue, $this->exchange, $rk);
    }

    private function routing_for_priority(string $priority): string {
        return match ($priority) {
            'high'   => 'backup.course.small',
            'medium' => 'backup.course.medium',
            'low'    => 'backup.course.large',
            default  => 'backup.course.medium',
        };
    }

    private function routing_single(): string {
        return 'backup.course.single';
    }

    private function send_task_with_rk(int $courseid, int $userid, string $priority, float $coursesizemb, string $rk, array $extra = []): string {
        global $DB;

        $jobid = bin2hex(random_bytes(16));
        $now   = time();
        $nowms = (int) round(microtime(true) * 1000);
        $mode = $extra['mode'] ?? 'multi';

        $record = (object)[
            'jobid'          => $jobid,
            'courseid'       => $courseid,
            'size_mb'        => $coursesizemb,
            'userid'         => $userid,
            'priority'       => $priority,
            'status'         => 'queued',
            'retries'        => 0,
            'timecreated'    => $now,
            'timecreated_ms' => $nowms,
            'timemodified'   => $now,
            'timemodified_ms'=> $nowms,
        ];

        $DB->insert_record('local_archive_job', $record);

        $DB->execute(
            "UPDATE {local_archive_job}
                SET timecreated_ms = :timecreated_ms,
                    timemodified_ms = :timemodified_ms
            WHERE jobid = :jobid",
            [
                'timecreated_ms'  => $nowms,
                'timemodified_ms' => $nowms,
                'jobid'           => $jobid,
            ]
        );

        $payload = array_merge($extra, [
            'jobid'     => $jobid,
            'courseid'  => $courseid,
            'userid'    => $userid,
            'priority'  => $priority,
            'timestamp' => $now,
            'retries'   => 0,
        ]);

        $msgProps = [
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        if ($mode !== 'single') {
        $msgProps['priority'] = match ($priority) {
            'high' => 10, 'medium' => 5, 'low' => 1, default => 5,
        };
        }

        $msg = new AMQPMessage(json_encode($payload), $msgProps);

        $this->channel->basic_publish($msg, $this->exchange, $rk);

        return "Job {$jobid} sent with routing key {$rk}";
    }

    private function get_course_size_bytes(int $courseid): int {
        global $DB;

        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            return 0;
        }

        $sql = "SELECT ctx.id
                FROM {context} ctx
                JOIN {course_modules} cm
                    ON ctx.contextlevel = 70
                AND ctx.instanceid  = cm.id
                WHERE cm.course = :courseid

                UNION

                SELECT ctx2.id
                FROM {context} ctx2
                WHERE ctx2.contextlevel = 50
                AND ctx2.instanceid  = :courseid2";

        $ctxrecords = $DB->get_records_sql($sql, [
            'courseid'  => $courseid,
            'courseid2' => $courseid,
        ]);

        if (!$ctxrecords) {
            return 0;
        }

        $contextids = array_keys($ctxrecords);

        list($insql, $inparams) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);

        $sql2 = "SELECT SUM(filesize)
                FROM {files}
                WHERE contextid $insql
                    AND filename <> '.'";

        $size = $DB->get_field_sql($sql2, $inparams);

        return (int)($size ?? 0);
    }

    private function get_course_size_mb(int $courseid): float {
        $bytes = $this->get_course_size_bytes($courseid);
        return round($bytes / (1024 * 1024), 2);
    }

    private function priority_by_size(float $mb): string {
        if ($mb <= 200) {
            return 'high';
        } else if ($mb <= 600) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    public function send_task_auto_by_size(int $courseid, int $userid, array $extraPayload = [], string $mode = 'multi'): string {

        $coursesizemb = $this->get_course_size_mb($courseid);
        $priority     = $this->priority_by_size($coursesizemb);

        $rk = ($mode === 'single')
            ? $this->routing_single()
            : $this->routing_for_priority($priority);

        $payload = array_merge($extraPayload, [
            'mode' => $mode,
        ]);

        return $this->send_task_with_rk($courseid, $userid, $priority, $coursesizemb, $rk, $payload);
    }


    public function __destruct() {
        $this->channel->close();
        $this->connection->close();
    }
}
