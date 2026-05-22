<?php
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

require_login();

$systemcontext = context_system::instance();

$jobid = required_param('jobid', PARAM_ALPHANUMEXT);

$job = $DB->get_record('local_archive_job', ['jobid' => $jobid], '*', MUST_EXIST);

$coursecontext = context_course::instance($job->courseid);

$iscourseowner = ($job->userid == $USER->id);
$isadminviewer = has_capability('local/archive:viewjobs', $coursecontext)
    || has_capability('local/archive:viewjobs', $systemcontext);

if (!$iscourseowner && !$isadminviewer) {
    print_error('nopermissions', 'error', '', 'download this archive');
}

if ($job->status !== 'success' || empty($job->filepath)) {
    print_error('filenotready', 'local_archive');
}

$filepath = $job->filepath;

$archivebase   = realpath($CFG->dataroot . '/archive');
$realfilepath  = realpath($filepath);

if ($archivebase === false || $realfilepath === false || strpos($realfilepath, $archivebase) !== 0) {
    print_error('invalidfilearea', 'local_archive');
}

if (!file_exists($realfilepath) || !is_readable($realfilepath)) {
    print_error('filenotfound', 'local_archive');
}

$filename = basename($realfilepath);

send_file(
    $realfilepath,
    $filename,
    0,
    0,
    true,
    true
);
