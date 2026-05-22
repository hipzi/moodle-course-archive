 <?php
require(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);

$course  = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/archive:viewcoursejobs', $context);

$PAGE->set_url(new moodle_url('/local/archive/history.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('archivecourse_history', 'local_archive'));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();

global $DB;

$jobs = $DB->get_records('local_archive_job', ['courseid' => $courseid], 'timecreated DESC', '*', 0, 50);

if (!$jobs) {
    echo html_writer::tag('p', get_string('no_jobs', 'local_archive'));
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('field_jobid', 'local_archive'),
    get_string('field_priority', 'local_archive'),
    get_string('field_size_mb', 'local_archive'),
    get_string('field_status', 'local_archive'),
    get_string('field_retries', 'local_archive'),
    get_string('field_timecreated', 'local_archive'),
];

foreach ($jobs as $job) {
    $statusstr = get_string('status_' . $job->status, 'local_archive');

    $table->data[] = [
        s($job->jobid),
        s($job->priority),
        $job->size_mb !== null ? format_float($job->size_mb, 2) : '-',
        $statusstr,
        (int)$job->retries,
        userdate($job->timecreated),
    ];
}

echo html_writer::tag('h3', get_string('archivecourse_history', 'local_archive'));
echo html_writer::table($table);

echo $OUTPUT->footer();
