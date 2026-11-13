<?php
/**
 * CBT Kiosk Theme - Custom core_renderer
 *
 * Inherits all renderer methods from theme_boost\output\core_renderer
 * including firstview_fakeblocks() to ensure full compatibility with
 * Moodle 4.x drawer layouts.
 *
 * Additionally guarantees anti-cheat script injection on quiz attempt pages
 * even if plugin hook caches haven't been purged.
 *
 * @package    theme_cbt_kiosk
 * @copyright  2026 ExamAdmin CBT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace theme_cbt_kiosk\output;

defined('MOODLE_INTERNAL') || die();

class core_renderer extends \theme_boost\output\core_renderer {

    /**
     * Standard end-of-body HTML.
     * Guarantees CBT kiosk anti-cheat is injected on quiz attempt pages.
     */
    public function standard_end_of_body_html() {
        global $CFG;

        $output = parent::standard_end_of_body_html();

        // Double check: if anti-cheat was not already injected by the before_footer hook
        if (strpos($output, 'CBT KIOSK ANTI-CHEAT') === false) {
            $libfile = $CFG->dirroot . '/local/cbt_kiosk/lib.php';
            if (file_exists($libfile)) {
                require_once($libfile);
                if (function_exists('local_cbt_kiosk_before_footer')) {
                    $anticheat = local_cbt_kiosk_before_footer();
                    if (!empty($anticheat)) {
                        $output .= $anticheat;
                    }
                }
            }
        }

        return $output;
    }
}
