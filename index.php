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
require_once($CFG->libdir . '/coursecatlib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

require_once($CFG->dirroot . '/report/multicourse/lib.php');

$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin('ui');
$PAGE->requires->jquery_plugin('ui-css');
//$PAGE->requires->jquery_plugin('checkboxtree', 'gradereport_multigrader');
//$PAGE->requires->css('/grade/report/multigrader/checkboxtree/css/checkboxtree.css');

$page = optional_param('page', 0, PARAM_INT);   // active page
$sortitemid = optional_param('sortitemid', 0, PARAM_ALPHANUM); // sort by which grade item

$PAGE->set_url(new moodle_url('/report/multicourse/index.php', []));


admin_externalpage_setup('reportmulticourse', '', null, '', array('pagelayout'=>'report'));
echo $OUTPUT->header();

//require_login($course);
$context = context_system::instance();

//require_capability('gradereport/multigrader:view', $context);
require_capability('moodle/grade:viewall', $context);

// Last selected report session tracking.
if (!isset($USER->grade_last_report)) {
    $USER->grade_last_report = array();
}

// Handle toggle change request.
//if (!is_null($toggle) && !empty($toggle_type)) {
//    set_user_preferences(array('grade_report_show' . $toggle_type => $toggle));
//}

// Perform actions.
/*if (!empty($target) && !empty($action) && confirm_sesskey()) {
    grade_report_grader::do_process_action($target, $action);
}*/

// Print header
$reportname = get_string('pluginname', 'report_multicourse');
//print_grade_page_head($COURSE->id, 'report', 'multigrader', $reportname, false);


// multi grader form test
$cohortid = optional_param('cohortid', 0, PARAM_INT);

// If Report one cohort
if ($cohortid) {
    $cohort = $DB->get_record('cohort', ['id'=>$cohortid], 'id, name', MUST_EXIST);
    $multireport = new report_multicourse($cohort);

    echo html_writer::tag('h2', $reportname . ': ' . $cohort->name);
    echo $multireport->get_report();

    echo '<br/><br/>';
    echo html_writer::link($PAGE->url, get_string('to_index', 'report_multicourse'));
}
// Else show cohorts list
else {
    echo html_writer::tag('h2', $reportname);

    $cohorts = cohort_get_all_cohorts($page, 25/*, $searchquery*/);
    $table = new html_table();
    $table->head  = [
        get_string('name', 'cohort') . ' (' . get_string('idnumber', 'cohort') . ')',
        get_string('description', 'cohort'),
        get_string('memberscount', 'cohort'),
    ];

    foreach($cohorts['cohorts'] as $cohort) {
        $line = [
            html_writer::link(new moodle_url('/report/multicourse/index.php', ['cohortid' => $cohort->id]), $cohort->name) . ' (' . $cohort->idnumber . ')',
            $cohort->description,
            $DB->count_records('cohort_members', array('cohortid'=>$cohort->id)),
        ];
        $data[] = new html_table_row($line);
    }
    $table->id = 'cohorts';
    $table->attributes['class'] = 'admintable generaltable';
    $table->data  = $data;
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
