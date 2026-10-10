<?php

namespace local_archive\experiment;

defined('MOODLE_INTERNAL') || die();

final class scheduler {

    public static function prepare(
        array $jobs,
        string $method
    ): array {
        scheduling_method::validate($method);

        foreach ($jobs as $index => &$job) {

            $job['input_sequence'] =
                isset($job['input_sequence'])
                    ? (int) $job['input_sequence']
                    : ($index + 1);

            $job['courseid'] =
                (int) $job['courseid'];

            $job['size_mb'] =
                (float) $job['size_mb'];

            $job['workloadclass'] =
                workload_classifier::classify(
                    $job['size_mb']
                );
        }

        unset($job);

        // FIFO, Native Priority and MCQ preserve
        // the original workload trace order.
        if ($method !== scheduling_method::SIZE_SJF) {
            return $jobs;
        }

        // Non-preemptive size-based SJF.
        usort(
            $jobs,
            static function(array $a, array $b): int {
                $sizecomparison =
                    $a['size_mb']
                    <=>
                    $b['size_mb'];

                if ($sizecomparison !== 0) {
                    return $sizecomparison;
                }

                // FIFO tie-break.
                return $a['input_sequence']
                    <=>
                    $b['input_sequence'];
            }
        );

        return $jobs;
    }

    public static function routing_key(
        array $job,
        string $method
    ): string {
        scheduling_method::validate($method);

        return match ($method) {
            scheduling_method::FIFO =>
                'experiment.fifo',

            scheduling_method::NATIVE_PRIORITY =>
                'experiment.priority',

            scheduling_method::SIZE_SJF =>
                'experiment.sjf',

            scheduling_method::MULTICLASS =>
                'experiment.class.'
                . $job['workloadclass'],
        };
    }

    public static function message_priority(
        array $job,
        string $method
    ): ?int {
        if ($method !== scheduling_method::NATIVE_PRIORITY) {
            return null;
        }

        return workload_classifier::priority(
            $job['workloadclass']
        );
    }
}
