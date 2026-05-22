<?php
require(__DIR__ . '/../../config.php');

require_login();
$systemcontext = context_system::instance();
require_capability('local/archive:viewjobs', $systemcontext);

$status   = optional_param('status', '', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
$userid   = optional_param('userid', 0, PARAM_INT);
$page     = optional_param('page', 0, PARAM_INT);
$perpage  = 50;

$PAGE->set_url(new moodle_url('/local/archive/index.php', [
    'status'   => $status,
    'courseid' => $courseid,
    'userid'   => $userid,
    'page'     => $page,
]));
$PAGE->set_context($systemcontext);
$PAGE->set_title(get_string('jobs_overview', 'local_archive'));
$PAGE->set_heading(get_string('jobs_overview', 'local_archive'));

echo $OUTPUT->header();

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $PAGE->url->out(false),
]);

echo html_writer::start_div('filters');

echo html_writer::label(get_string('filter_status', 'local_archive'), 'id_status') . ' ';
echo html_writer::select(
    [
        ''          => '-',
        'queued'    => get_string('status_queued', 'local_archive'),
        'running'   => get_string('status_running', 'local_archive'),
        'success'   => get_string('status_success', 'local_archive'),
        'retry'     => get_string('status_retry', 'local_archive'),
        'failed'    => get_string('status_failed', 'local_archive'),
    ],
    'status',
    $status,
    null,
    ['id' => 'id_status']
);

echo ' &nbsp; ';

echo html_writer::label(get_string('filter_course', 'local_archive'), 'id_courseid') . ' ';
echo html_writer::empty_tag('input', [
    'type'  => 'number',
    'name'  => 'courseid',
    'id'    => 'id_courseid',
    'value' => $courseid ?: '',
    'min'   => 0,
]);

echo ' &nbsp; ';

echo html_writer::label(get_string('filter_user', 'local_archive'), 'id_userid') . ' ';
echo html_writer::empty_tag('input', [
    'type'  => 'number',
    'name'  => 'userid',
    'id'    => 'id_userid',
    'value' => $userid ?: '',
    'min'   => 0,
]);

echo ' &nbsp; ';

echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => get_string('filter_apply', 'local_archive'),
    'class' => 'btn btn-primary'
]);

echo html_writer::end_div();
echo html_writer::end_tag('form');

global $DB;

$where = [];
$params = [];

if ($status !== '') {
    $where[]         = 'status = :status';
    $params['status'] = $status;
}
if ($courseid > 0) {
    $where[]           = 'courseid = :courseid';
    $params['courseid'] = $courseid;
}
if ($userid > 0) {
    $where[]         = 'userid = :userid';
    $params['userid'] = $userid;
}

$whereclause = $where ? implode(' AND ', $where) : '1=1';

$total = $DB->count_records_select('local_archive_job', $whereclause, $params);

$baseurl = new moodle_url('/local/archive/index.php', [
    'status'   => $status,
    'courseid' => $courseid,
    'userid'   => $userid,
]);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

$jobs = $DB->get_records_select(
    'local_archive_job',
    $whereclause,
    $params,
    'timecreated DESC',
    '*',
    $page * $perpage,
    $perpage
);

if (!$jobs) {
    echo html_writer::tag('p', get_string('no_jobs', 'local_archive'));
    echo $OUTPUT->footer();
    exit;
}

$table          = new html_table();
$table->head    = [
    get_string('field_jobid',      'local_archive'),
    get_string('field_course',     'local_archive'),
    get_string('field_user',       'local_archive'),
    get_string('field_priority',   'local_archive'),
    get_string('field_size_mb',    'local_archive'),
    get_string('field_status',     'local_archive'),
    get_string('field_retries',    'local_archive'),
    get_string('field_timecreated','local_archive'),
    get_string('field_timemodified','local_archive'),
    get_string('field_actions',    'local_archive'),
];

$table->attributes['class'] = 'generaltable archive-jobs';

foreach ($jobs as $job) {
    $course = $DB->get_record('course', ['id' => $job->courseid], 'id,shortname', IGNORE_MISSING);
    $user   = $DB->get_record('user', ['id' => $job->userid], 'id,username', IGNORE_MISSING);

    $courselabel = $course
        ? html_writer::link(
            new moodle_url('/course/view.php', ['id' => $course->id]),
            s($course->shortname) . " (id={$course->id})"
        )
        : 'N/A';

    $userlabel = $user
        ? s($user->username) . " (id={$user->id})"
        : 'N/A';

    $statusstr = get_string('status_' . $job->status, 'local_archive');
    $downloadlink = '-';

    if ($job->status === 'success' && !empty($job->filepath)) {
        $downloadurl  = new moodle_url('/local/archive/download.php', ['jobid' => $job->jobid]);
        $downloadlink = html_writer::link($downloadurl, get_string('download'), ['class' => 'btn btn-secondary btn-sm']);
    }

    $table->data[] = [
        s($job->jobid),
        $courselabel,
        $userlabel,
        s($job->priority),
        $job->size_mb !== null ? format_float($job->size_mb, 2) : '-',
        $statusstr,
        (int)$job->retries,
        userdate($job->timecreated),
        userdate($job->timemodified),
        $downloadlink,
    ];
}

echo html_writer::tag('h3', get_string('jobs_overview', 'local_archive'));
echo html_writer::table($table);

echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

echo $OUTPUT->footer();
