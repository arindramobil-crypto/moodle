<?php
/**
 * Event observers definition for local_cbt_kiosk
 *
 * Hooks into user_loggedin event to perform kiosk redirect.
 *
 * @package    local_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\core\event\user_loggedin',
        'callback'    => 'local_cbt_kiosk\observer::user_loggedin',
        'includefile' => null,
        'internal'    => true,
        'priority'    => 1000,
    ],
];

