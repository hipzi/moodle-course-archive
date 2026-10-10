<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_archive_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026101000) {

        $table = new xmldb_table('local_archive_job');

        $fields = [
            new xmldb_field(
                'experimentid',
                XMLDB_TYPE_CHAR,
                '80',
                null,
                null,
                null,
                null,
                'filepath'
            ),

            new xmldb_field(
                'experimenttype',
                XMLDB_TYPE_CHAR,
                '20',
                null,
                null,
                null,
                null,
                'experimentid'
            ),

            new xmldb_field(
                'method',
                XMLDB_TYPE_CHAR,
                '20',
                null,
                null,
                null,
                null,
                'experimenttype'
            ),

            new xmldb_field(
                'runnumber',
                XMLDB_TYPE_INTEGER,
                '3',
                null,
                null,
                null,
                null,
                'method'
            ),

            new xmldb_field(
                'traceid',
                XMLDB_TYPE_CHAR,
                '64',
                null,
                null,
                null,
                null,
                'runnumber'
            ),

            new xmldb_field(
                'input_sequence',
                XMLDB_TYPE_INTEGER,
                '5',
                null,
                null,
                null,
                null,
                'traceid'
            ),

            new xmldb_field(
                'workloadclass',
                XMLDB_TYPE_CHAR,
                '10',
                null,
                null,
                null,
                null,
                'input_sequence'
            ),

            new xmldb_field(
                'releasedat_ms',
                XMLDB_TYPE_INTEGER,
                '20',
                null,
                null,
                null,
                null,
                'workloadclass'
            ),

            new xmldb_field(
                'startedat_ms',
                XMLDB_TYPE_INTEGER,
                '20',
                null,
                null,
                null,
                null,
                'releasedat_ms'
            ),

            new xmldb_field(
                'completedat_ms',
                XMLDB_TYPE_INTEGER,
                '20',
                null,
                null,
                null,
                null,
                'startedat_ms'
            ),

            new xmldb_field(
                'workerid',
                XMLDB_TYPE_CHAR,
                '64',
                null,
                null,
                null,
                null,
                'completedat_ms'
            ),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        $experimentindex = new xmldb_index(
            'experimentid_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['experimentid']
        );

        if (!$dbman->index_exists($table, $experimentindex)) {
            $dbman->add_index($table, $experimentindex);
        }

        $runindex = new xmldb_index(
            'experiment_run_idx',
            XMLDB_INDEX_NOTUNIQUE,
            [
                'experimenttype',
                'method',
                'runnumber',
            ]
        );

        if (!$dbman->index_exists($table, $runindex)) {
            $dbman->add_index($table, $runindex);
        }

        upgrade_plugin_savepoint(
            true,
            2026101000,
            'local',
            'archive'
        );
    }

    return true;
}
