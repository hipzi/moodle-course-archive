<?php
defined('MOODLE_INTERNAL') || die();

function local_archive_extend_navigation_course($navigation, $course, $context) {
    global $USER;

    if (has_capability('local/archive:triggerarchive', $context)) {
        $url = new moodle_url('/local/archive/archive.php', [
            'id'      => $course->id,
            'sesskey' => sesskey(),
        ]);

        $node = navigation_node::create(
            get_string('archivecourse', 'local_archive'),
            $url,
            navigation_node::TYPE_CUSTOM,
            null,
            'local_archive_archivecourse',
            new pix_icon('i/backup', '')
        );

        $navigation->add_node($node);
    }

    if (has_capability('local/archive:viewcoursejobs', $context)) {
        $historyurl = new moodle_url('/local/archive/history.php', ['id' => $course->id]);

        $node = navigation_node::create(
            get_string('archivecourse_history', 'local_archive'),
            $historyurl,
            navigation_node::TYPE_CUSTOM,
            null,
            'local_archive_history',
            new pix_icon('i/report', '')
        );

        $navigation->add_node($node);
    }
}
