<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade script for local_cbt_kiosk.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_cbt_kiosk_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026111301) {
        $tablename = 'local_cbt_kiosk_logs';
        if (!$dbman->table_exists($tablename)) {
            $table = new xmldb_table($tablename);
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('action', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, '');
            $table->add_field('violation_count', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('reason', XMLDB_TYPE_CHAR, '255', null, null, null, null);
            $table->add_field('device_type', XMLDB_TYPE_CHAR, '30', null, null, null, null);
            $table->add_field('ip_address', XMLDB_TYPE_CHAR, '45', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_idx', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('quizid_idx', XMLDB_INDEX_NOTUNIQUE, ['quizid']);

            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026111301, 'local', 'cbt_kiosk');
    }

    return true;
}
