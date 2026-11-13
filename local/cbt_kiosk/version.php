<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This plugin is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.

/**
 * Local plugin: CBT Kiosk Mode
 *
 * Automatically redirects Student-role users to the active quiz
 * after login, based on the token set by exam-admin CBT system.
 *
 * @package    local_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026111001;
$plugin->requires  = 2022041900; // Moodle 4.0 minimum
$plugin->component = 'local_cbt_kiosk';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '1.0.0';

