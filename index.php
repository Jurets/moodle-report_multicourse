<?php
// This file is part of the Multi Course Grader report for Moodle by Barry Oosthuizen http://elearningstudio.co.uk
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * The gradebook multi grader report
 *
 * @package   report_multicourse
 * @copyright 2017 Jurets
 * @author    Jurets
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

require_once($CFG->dirroot.'/lib/statslib.php');
require_once($CFG->libdir.'/adminlib.php');

require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/grade/lib.php');
require_once($CFG->dirroot . '/report/multicourse/lib.php');
require_once($CFG->libdir . '/coursecatlib.php');
//require_once($CFG->dirroot . '/grade/report/multigrader/categorylib.php');

//$PAGE->requires->jquery();
//$PAGE->requires->jquery_plugin('ui');
//$PAGE->requires->jquery_plugin('ui-css');
//$PAGE->requires->jquery_plugin('checkboxtree', 'gradereport_multigrader');
//$PAGE->requires->css('/grade/report/multigrader/checkboxtree/css/checkboxtree.css');

// end of insert

//$courseid = required_param('id', PARAM_INT);        // course id
$page = optional_param('page', 0, PARAM_INT);   // active page
//$edit = optional_param('edit', -1, PARAM_BOOL); // sticky editting mode
//
$sortitemid = optional_param('sortitemid', 0, PARAM_ALPHANUM); // sort by which grade item
//$action = optional_param('action', 0, PARAM_ALPHAEXT);
//$target = optional_param('target', 0, PARAM_ALPHANUM);
//$toggle = optional_param('toggle', NULL, PARAM_INT);
//$toggle_type = optional_param('toggle_type', 0, PARAM_ALPHANUM);

// Multi grader form.
$formsubmitted = optional_param('formsubmitted', 0, PARAM_TEXT);
// End of multi grader form.

//$PAGE->set_url(new moodle_url('/grade/report/multigrader/index.php', array('id' => $courseid)));
$PAGE->set_url(new moodle_url('/report/multicourse/index.php', []));


admin_externalpage_setup('reportmulticourse', '', null, '', array('pagelayout'=>'report'));
echo $OUTPUT->header();

// Basic access checks.
/*if (!$course = $DB->get_record('course', array('id' => $courseid))) {
    print_error('nocourseid');
}*/
//require_login($course);
//$context = context_course::instance($course->id);
$context = context_system::instance();

//require_capability('gradereport/multigrader:view', $context);
require_capability('moodle/grade:viewall', $context);

// Return tracking object.
//$gpr = new grade_plugin_return(array('type' => 'report', 'plugin' => 'multigrader', 'courseid' => $courseid, 'page' => $page));

// Last selected report session tracking.
if (!isset($USER->grade_last_report)) {
    $USER->grade_last_report = array();
}
//$USER->grade_last_report[$course->id] = 'multigrader';
//$USER->grade_last_report[$course->id] = 'multicourse';

// Handle toggle change request.
//if (!is_null($toggle) && !empty($toggle_type)) {
//    set_user_preferences(array('grade_report_show' . $toggle_type => $toggle));
//}

// First make sure we have proper final grades - this must be done before constructing of the grade tree.
///////grade_regrade_final_grades($courseid);

// Perform actions.
/*if (!empty($target) && !empty($action) && confirm_sesskey()) {
    grade_report_grader::do_process_action($target, $action);
}*/
$reportname = get_string('pluginname', 'report_multicourse');

// Print header
//print_grade_page_head($COURSE->id, 'report', 'multigrader', $reportname, false);
?>
<!--<script type="text/javascript">

    jQuery(document).ready(function(){
        jQuery("#docheckchildren").checkboxTree({
            collapsedarrow: "checkboxtree/images/checkboxtree/img-arrow-collapsed.gif",
            expandedarrow: "checkboxtree/images/checkboxtree/img-arrow-expanded.gif",
            blankarrow: "checkboxtree/images/checkboxtree/img-arrow-blank.gif",
            checkchildren: true,
            checkparents: false
        });

    });

</script>-->

<?php

echo '<br/><br/>';

/*echo '<form method="post" action="index.php">';
echo '<div id="categorylist">';
echo '<ul class="unorderedlisttree" id="docheckchildren">';
//gradereport_multigrader_print_category();

echo '</ul>';
//echo '<div><input type="hidden" name="id" value="' . $courseid . '"/></div>';
echo '<div><input type="hidden" name="userid" value="' . $USER->id . '"/></div>';
echo '<div><input type="hidden" name="formsubmitted" value="Yes"/></div>';
echo '<div><input type="hidden" name="sesskey" value="' . sesskey() . '"/></div>';

echo '<div><input type="submit" name="submitquery" value="' . get_string("submit") . '"/></div>';
echo '</div>';
echo '</form>';
echo '<br/><br/>';*/
// multi grader form test
$reporthtml = '';

$cohortid = optional_param('cohortid', 0, PARAM_INT);

if ($cohortid) {

    $multireport = new report_multicourse($cohortid);

/*if ($formsubmitted === "Yes") { */

    //$coursebox = optional_param_array('coursebox', 0, PARAM_RAW);

    $reporthtml .= $multireport->get_report();
    echo $reporthtml;
}
echo $OUTPUT->footer();
