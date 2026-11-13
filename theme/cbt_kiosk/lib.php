<?php
/**
 * CBT Kiosk Theme - lib.php
 * SCSS callbacks required by Boost child themes
 *
 * @package    theme_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

function theme_cbt_kiosk_get_main_scss_content($theme) {
    return theme_boost_get_main_scss_content($theme);
}

function theme_cbt_kiosk_get_extra_scss($theme): string {
    return theme_boost_get_extra_scss($theme);
}

function theme_cbt_kiosk_get_pre_scss($theme): string {
    return theme_boost_get_pre_scss($theme);
}
