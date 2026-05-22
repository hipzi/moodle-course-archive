<?php
require(__DIR__ . '/../../config.php');
require(__DIR__ . '/autoload.php');

$courseid = required_param('id', PARAM_INT);

$course  = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_capability('local/archive:triggerarchive', $context);

$PAGE->set_url(new moodle_url('/local/archive/archive.php', ['id' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('archivecourse', 'local_archive'));
$PAGE->set_heading(format_string($course->fullname));

require_sesskey();

$producer = new \local_archive\producer();
$result   = $producer->send_task_auto_by_size($courseid, $USER->id, [
    'note' => 'Triggered from course UI',
]);

redirect(
    new moodle_url('/course/view.php', ['id' => $courseid]),
    get_string('archive_trigger_success', 'local_archive') . '<br>' . s($result),
    null,
    \core\output\notification::NOTIFY_SUCCESS
);
