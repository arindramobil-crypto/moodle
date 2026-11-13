<?php
/**
 * CBT Kiosk Theme - config.php
 *
 * A child theme of Boost that customizes the login page with a professional
 * Computer-Based Test (CBT) kiosk appearance, while fully inheriting Boost's
 * layouts, SCSS compilation, and renderers for Moodle 4.x compatibility.
 *
 * @package    theme_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/theme/boost/lib.php');
require_once(__DIR__ . '/lib.php');

$THEME->name        = 'cbt_kiosk';
$THEME->parents     = ['boost'];  // Inherits all Boost templates & layouts
$THEME->sheets      = [];
$THEME->editor_sheets = [];
$THEME->editor_scss = ['editor'];
$THEME->usefallback = true;
$THEME->scss = function($theme) {
    return theme_cbt_kiosk_get_main_scss_content($theme);
};

// Override login layout with CBT kiosk login page, keep all other layouts inherited from Boost
$THEME->layouts     = [
    'login' => [
        'file'    => 'login.php',
        'regions' => [],
    ],
];

$THEME->enable_dock = false;
$THEME->extrascsscallback = 'theme_cbt_kiosk_get_extra_scss';
$THEME->prescsscallback  = 'theme_cbt_kiosk_get_pre_scss';
$THEME->precompiledcsscallback = 'theme_boost_get_precompiled_css';
$THEME->yuicssmodules = array();
$THEME->rendererfactory = 'theme_overridden_renderer_factory';
$THEME->requiredblocks = '';
$THEME->addblockposition = BLOCK_ADDBLOCK_POSITION_FLATNAV;
$THEME->iconsystem = \core\output\icon_system::FONTAWESOME;
$THEME->haseditswitch = true;
$THEME->usescourseindex = true;
$THEME->activityheaderconfig = [
    'notitle' => true
];
