<?php
/**
 * Settings for local_cbt_kiosk
 *
 * @package    local_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_cbt_kiosk', get_string('pluginname', 'local_cbt_kiosk'));

    $settings->add(new admin_setting_configtext(
        'local_cbt_kiosk/proctor_pin',
        get_string('proctor_pin', 'local_cbt_kiosk'),
        get_string('proctor_pin_desc', 'local_cbt_kiosk'),
        'PROKTOR123',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_cbt_kiosk/min_finish_minutes',
        get_string('min_finish_minutes', 'local_cbt_kiosk'),
        get_string('min_finish_minutes_desc', 'local_cbt_kiosk'),
        '0',
        PARAM_INT
    ));

    $ADMIN->add('localplugins', $settings);
}

