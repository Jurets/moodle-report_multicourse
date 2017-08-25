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
 * Definition of the grader report class
 *
 * @package   gradereport_multigrader
 * @copyright 2012 onwards Barry Oosthuizen http://elearningstudio.co.uk
 * @author    Barry Oosthuizen
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/grade/report/lib.php');
require_once($CFG->libdir . '/tablelib.php');

/**
 * Class providing an API for the multi grader report building and displaying.
 * @uses grade_report
 * @package gradereport_multigrader
 */
class grade_report_multigrader extends grade_report {
    /**
     * The final grades.
     * @var array $grades
     */
    public $grades;

    /**
     * Array of errors for bulk grades updating.
     * @var array $gradeserror
     */
    public $gradeserror = array();

//// SQL-RELATED

    /**
     * The id of the grade_item by which this report will be sorted.
     * @var int $sortitemid
     */
    public $sortitemid;

    /**
     * Sortorder used in the SQL selections.
     * @var int $sortorder
     */
    public $sortorder;

    /**
     * An SQL fragment affecting the search for users.
     * @var string $userselect
     */
    public $userselect;

    /**
     * The bound params for $userselect
     * @var array $userselectparams
     */
    public $userselectparams = array();

    /**
     * List of collapsed categories from user preference
     * @var array $collapsed
     */
    public $collapsed;

    /**
     * A count of the rows, used for css classes.
     * @var int $rowcount
     */
    public $rowcount = 0;

    /**
     * Capability check caching
     * @var boolean $canviewhidden
     */
    public $canviewhidden;

    var $preferencespage=false;

    /**
     * Length at which feedback will be truncated (to the nearest word) and an ellipsis be added.
     * TODO replace this by a report preference
     * @var int $feedback_trunc_length
     */
    protected $feedback_trunc_length = 50;

    /**
     * Constructor. Sets local copies of user preferences and initialises grade_tree.
     * @param int $courseid
     * @param object $gpr grade plugin return tracking object
     * @param string $context
     * @param int $page The current page being viewed (when report is paged)
     * @param int $sortitemid The id of the grade_item by which to sort the table
     */
    public function __construct($courseid, $gpr, $context, $page = null, $sortitemid = null) {
        global $CFG;
        parent::__construct($courseid, $gpr, $context, $page);

        $this->canviewhidden = has_capability('moodle/grade:viewhidden', context_course::instance($this->course->id));

        // load collapsed settings for this report
        if ($collapsed = get_user_preferences('grade_report_multigrader_collapsed_categories')) {
            $this->collapsed = unserialize($collapsed);
        } else {
            $this->collapsed = array('aggregatesonly' => array(), 'gradesonly' => array());
        }

        if (empty($CFG->enableoutcomes)) {
            $nooutcomes = false;
        } else {
            $nooutcomes = get_user_preferences('grade_report_shownooutcomes');
        }

        // if user report preference set or site report setting set use it, otherwise use course or site setting
        $switch = $this->get_pref('aggregationposition');
        if ($switch == '') {
            $switch = grade_get_setting($this->courseid, 'aggregationposition', $CFG->grade_aggregationposition);
        }

        // Grab the grade_tree for this course
        $this->gtree = new grade_tree($this->courseid, true, $switch, $this->collapsed, $nooutcomes);

        $this->sortitemid = $sortitemid;

        // base url for sorting by first/last name

        $this->baseurl = new moodle_url('index.php', array('id' => $this->courseid));

        $studentsperpage = $this->get_students_per_page();
        if (!empty($this->page) && !empty($studentsperpage)) {
            $this->baseurl->params(array('perpage' => $studentsperpage, 'page' => $this->page));
        }

        $this->pbarurl = new moodle_url('/grade/report/multigrader/index.php', array('id' => $this->courseid));

        $this->setup_groups();

        $this->setup_sortitemid();
    }

    /**
     * Processes the data sent by the form (grades and feedbacks).
     * Caller is responsible for all access control checks
     * @param array $data form submission (with magic quotes)
     * @return array empty array if success, array of warnings if something fails.
     */
    public function process_data($data) {
        global $DB;
        $warnings = array();

        $separategroups = false;
        $mygroups = array();
        if ($this->groupmode == SEPARATEGROUPS and !has_capability('moodle/site:accessallgroups', $this->context)) {
            $separategroups = true;
            $mygroups = groups_get_user_groups($this->course->id);
            $mygroups = $mygroups[0]; // ignore groupings
            // reorder the groups fro better perf below
            $current = array_search($this->currentgroup, $mygroups);
            if ($current !== false) {
                unset($mygroups[$current]);
                array_unshift($mygroups, $this->currentgroup);
            }
        }

        // always initialize all arrays
        $queue = array();
        $this->load_users();
        $this->load_final_grades();

        // Were any changes made?
        $changedgrades = false;

        foreach ($data as $varname => $students) {

            $needsupdate = false;

            // skip, not a grade nor feedback
            if (strpos($varname, 'grade') === 0) {
                $datatype = 'grade';
            } else if (strpos($varname, 'feedback') === 0) {
                $datatype = 'feedback';
            } else {
                continue;
            }

            foreach ($students as $userid => $items) {
                $userid = clean_param($userid, PARAM_INT);
                foreach ($items as $itemid => $postedvalue) {
                    $itemid = clean_param($itemid, PARAM_INT);

                    // Was change requested?
                    $oldvalue = $this->grades[$userid][$itemid];
                    if ($datatype === 'grade') {
                        // If there was no grade and there still isn't
                        if (is_null($oldvalue->finalgrade) && $postedvalue == -1) {
                            // -1 means no grade
                            continue;
                        }

                        // If the grade item uses a custom scale
                        if (!empty($oldvalue->grade_item->scaleid)) {

                            if ((int) $oldvalue->finalgrade === (int) $postedvalue) {
                                continue;
                            }
                        } else {
                            // The grade item uses a numeric scale

                            // Format the finalgrade from the DB so that it matches the grade from the client
                            if ($postedvalue === format_float($oldvalue->finalgrade, $oldvalue->grade_item->get_decimals())) {
                                continue;
                            }
                        }

                        $changedgrades = true;

                    } else if ($datatype === 'feedback') {
                        if ($oldvalue->feedback === $postedvalue) {
                            continue;
                        }
                    }

                    if (!$gradeitem = grade_item::fetch(array('id' => $itemid, 'courseid' => $this->courseid))) { // we must verify course id here!
                        print_error('invalidgradeitemid');
                    }

                    // Pre-process grade
                    if ($datatype == 'grade') {
                        $feedback = false;
                        $feedbackformat = false;
                        if ($gradeitem->gradetype == GRADE_TYPE_SCALE) {
                            if ($postedvalue == -1) { // -1 means no grade
                                $finalgrade = null;
                            } else {
                                $finalgrade = $postedvalue;
                            }
                        } else {
                            $finalgrade = unformat_float($postedvalue);
                        }

                        $errorstr = '';
                        // Warn if the grade is out of bounds.
                        if (is_null($finalgrade)) {
                            // ok
                        } else {
                            $bounded = $gradeitem->bounded_grade($finalgrade);
                            if ($bounded > $finalgrade) {
                                $errorstr = 'lessthanmin';
                            } else if ($bounded < $finalgrade) {
                                $errorstr = 'morethanmax';
                            }
                        }
                        if ($errorstr) {
                            $user = $DB->get_record('user', array('id' => $userid), 'id, firstname, lastname');
                            $gradestr = new stdClass();
                            $gradestr->username = fullname($user);
                            $gradestr->itemname = $gradeitem->get_name();
                            $warnings[] = get_string($errorstr, 'grades', $gradestr);
                        }

                    } else if ($datatype == 'feedback') {
                        $finalgrade = false;
                        $trimmed = trim($postedvalue);
                        if (empty($trimmed)) {
                            $feedback = null;
                        } else {
                            $feedback = $postedvalue;
                        }
                    }

                    // group access control
                    if ($separategroups) {
                        // note: we can not use $this->currentgroup because it would fail badly
                        //       when having two browser windows each with different group
                        $sharinggroup = false;
                        foreach ($mygroups as $groupid) {
                            if (groups_is_member($groupid, $userid)) {
                                $sharinggroup = true;
                                break;
                            }
                        }
                        if (!$sharinggroup) {
                            // either group membership changed or somebody is hacking grades of other group
                            $warnings[] = get_string('errorsavegrade', 'grades');
                            continue;
                        }
                    }

                    $gradeitem->update_final_grade($userid, $finalgrade, 'gradebook', $feedback, FORMAT_MOODLE);

                    // We can update feedback without reloading the grade item as it doesn't affect grade calculations
                    if ($datatype === 'feedback') {
                        $this->grades[$userid][$itemid]->feedback = $feedback;
                    }
                }
            }
        }

        if ($changedgrades) {
            // If a final grade was overriden reload grades so dependent grades like course total will be correct
            $this->grades = null;
        }

        return $warnings;
    }


    /**
     * Setting the sort order, this depends on last state
     * all this should be in the new table class that we might need to use
     * for displaying grades.
     */
    private function setup_sortitemid() {

        global $SESSION;

        if (!isset($SESSION->gradeuserreport)) {
            $SESSION->gradeuserreport = new stdClass();
        }

        if ($this->sortitemid) {
            if (!isset($SESSION->gradeuserreport->sort)) {
                if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                    $this->sortorder = $SESSION->gradeuserreport->sort = 'ASC';
                } else {
                    $this->sortorder = $SESSION->gradeuserreport->sort = 'DESC';
                }
            } else {
                // this is the first sort, i.e. by last name
                if (!isset($SESSION->gradeuserreport->sortitemid)) {
                    if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'ASC';
                    } else {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'DESC';
                    }
                } else if ($SESSION->gradeuserreport->sortitemid == $this->sortitemid) {
                    // same as last sort
                    if ($SESSION->gradeuserreport->sort == 'ASC') {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'DESC';
                    } else {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'ASC';
                    }
                } else {
                    if ($this->sortitemid == 'firstname' || $this->sortitemid == 'lastname') {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'ASC';
                    } else {
                        $this->sortorder = $SESSION->gradeuserreport->sort = 'DESC';
                    }
                }
            }
            $SESSION->gradeuserreport->sortitemid = $this->sortitemid;
        } else {
            // not requesting sort, use last setting (for paging)

            if (isset($SESSION->gradeuserreport->sortitemid)) {
                $this->sortitemid = $SESSION->gradeuserreport->sortitemid;
            } else {
                $this->sortitemid = 'lastname';
            }

            if (isset($SESSION->gradeuserreport->sort)) {
                $this->sortorder = $SESSION->gradeuserreport->sort;
            } else {
                $this->sortorder = 'ASC';
            }
        }
    }

    /**
     * pulls out the userids of the users to be display, and sorts them
     */
    public function load_users() {
        global $CFG, $DB;

        if (!empty($this->users)) {
            return;
        }

        // Limit to users with a gradeable role.
        list($gradebookrolessql, $gradebookrolesparams) = $DB->get_in_or_equal(explode(',', $this->gradebookroles), SQL_PARAMS_NAMED, 'grbr0');

        // Limit to users with an active enrollment.
        list($enrolledsql, $enrolledparams) = get_enrolled_sql($this->context);

        // Fields we need from the user table.
        $userfields = user_picture::fields('u', get_extra_user_fields($this->context));

        // We want to query both the current context and parent contexts.
        list($relatedctxsql, $relatedctxparams) = $DB->get_in_or_equal($this->context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'relatedctx');

        // If the user has clicked one of the sort asc/desc arrows.
        if (is_numeric($this->sortitemid)) {
            $params = array_merge(array('gitemid' => $this->sortitemid), $gradebookrolesparams, $this->userwheresql_params,
                $this->groupwheresql_params, $enrolledparams, $relatedctxparams);

            $sortjoin = "LEFT JOIN {grade_grades} g ON g.userid = u.id AND g.itemid = $this->sortitemid";
            $sort = "g.finalgrade $this->sortorder";
        } else {
            $sortjoin = '';
            switch($this->sortitemid) {
                case 'lastname':
                    $sort = "u.lastname $this->sortorder, u.firstname $this->sortorder";
                    break;
                case 'firstname':
                    $sort = "u.firstname $this->sortorder, u.lastname $this->sortorder";
                    break;
                case 'email':
                    $sort = "u.email $this->sortorder";
                    break;
                case 'idnumber':
                default:
                    $sort = "u.idnumber $this->sortorder";
                    break;
            }

            $params = array_merge($gradebookrolesparams, $this->userwheresql_params, $this->groupwheresql_params, $enrolledparams, $relatedctxparams);
        }

        $sql = "SELECT $userfields
                  FROM {user} u
                  JOIN ($enrolledsql) je ON je.id = u.id
                       $this->groupsql
                       $sortjoin
                  JOIN (
                           SELECT DISTINCT ra.userid
                             FROM {role_assignments} ra
                            WHERE ra.roleid IN ($this->gradebookroles)
                              AND ra.contextid $relatedctxsql
                       ) rainner ON rainner.userid = u.id
                   AND u.deleted = 0
                   $this->userwheresql
                   $this->groupwheresql
              ORDER BY $sort";
        $studentsperpage = $this->get_students_per_page();
        $this->users = $DB->get_records_sql($sql, $params, $studentsperpage * $this->page, $studentsperpage);

        if (empty($this->users)) {
            $this->userselect = '';
            $this->users = array();
            $this->userselect_params = array();
        } else {
            list($usql, $uparams) = $DB->get_in_or_equal(array_keys($this->users), SQL_PARAMS_NAMED, 'usid0');
            $this->userselect = "AND g.userid $usql";
            $this->userselect_params = $uparams;

            // Add a flag to each user indicating whether their enrolment is active.
            $sql = "SELECT ue.userid
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON e.id = ue.enrolid
                     WHERE ue.userid $usql
                           AND ue.status = :uestatus
                           AND e.status = :estatus
                           AND e.courseid = :courseid
                           AND ue.timestart < :now1 AND (ue.timeend = 0 OR ue.timeend > :now2)
                  GROUP BY ue.userid";
            $coursecontext = $this->context->get_course_context(true);
            $time = time();
            $params = array_merge($uparams, array('estatus' => ENROL_INSTANCE_ENABLED, 'uestatus' => ENROL_USER_ACTIVE,
                'courseid' => $coursecontext->instanceid, 'now1' => $time, 'now2' => $time));
            $useractiveenrolments = $DB->get_records_sql($sql, $params);

            $defaultgradeshowactiveenrol = !empty($CFG->grade_report_showonlyactiveenrol);
            $showonlyactiveenrol = get_user_preferences('grade_report_showonlyactiveenrol', $defaultgradeshowactiveenrol);
            $showonlyactiveenrol = $showonlyactiveenrol || !has_capability('moodle/course:viewsuspendedusers', $coursecontext);
            foreach ($this->users as $user) {
                // If we are showing only active enrolments, then remove suspended users from list.
                if ($showonlyactiveenrol && !array_key_exists($user->id, $useractiveenrolments)) {
                    unset($this->users[$user->id]);
                } else {
                    $this->users[$user->id]->suspendedenrolment = !array_key_exists($user->id, $useractiveenrolments);
                }
            }
        }
        return $this->users;
    }

    /**
     * we supply the userids in this query, and get all the grades
     * pulls out all the grades, this does not need to worry about paging
     */
    public function load_final_grades() {
        global $CFG, $DB;

        if (!empty($this->grades)) {
            return;
        }

        if (empty($this->users)) {
            return;
        }

        // please note that we must fetch all grade_grades fields if we want to construct grade_grade object from it!
        $params = array_merge(array('courseid' => $this->courseid), $this->userselect_params);
        $sql = "SELECT g.*
                  FROM {grade_items} gi,
                       {grade_grades} g
                 WHERE g.itemid = gi.id AND gi.courseid = :courseid {$this->userselect}";

        $userids = array_keys($this->users);

        if ($grades = $DB->get_records_sql($sql, $params)) {
            foreach ($grades as $graderec) {
                if (in_array($graderec->userid, $userids) and array_key_exists($graderec->itemid, $this->gtree->get_items())) { // some items may not be present!!
                    $this->grades[$graderec->userid][$graderec->itemid] = new grade_grade($graderec, false);
                    $this->grades[$graderec->userid][$graderec->itemid]->grade_item = $this->gtree->get_item($graderec->itemid); // db caching
                }
            }
        }

        // prefil grades that do not exist yet
        foreach ($userids as $userid) {
            foreach ($this->gtree->get_items() as $itemid => $unused) {
                if (!isset($this->grades[$userid][$itemid])) {
                    $this->grades[$userid][$itemid] = new grade_grade();
                    $this->grades[$userid][$itemid]->itemid = $itemid;
                    $this->grades[$userid][$itemid]->userid = $userid;
                    $this->grades[$userid][$itemid]->grade_item = $this->gtree->get_item($itemid); // db caching
                }
            }
        }
    }

    /**
     * Gets html toggle
     * @deprecated since Moodle 2.4 as it appears not to be used any more.
     */
    public function get_toggles_html() {
        throw new coding_exception('get_toggles_html() can not be used any more');
    }

    /**
     * Prints html toggle
     * @deprecated since 2.4 as it appears not to be used any more.
     * @param unknown $type
     */
    public function print_toggle($type) {
        throw new coding_exception('print_toggle() can not be used any more');
    }

    /**
     * Builds and returns the rows that will make up the left part of the grader report
     * This consists of student names and icons, links to user reports and id numbers, as well
     * as header cells for these columns. It also includes the fillers required for the
     * categories displayed on the right side of the report.
     * @param boolean $displayaverages whether to display average rows in the table
     * @return array Array of html_table_row objects
     */
    public function get_left_rows() {
        global $CFG, $USER, $OUTPUT;

        $rows = array();

        $showuserimage = $this->get_pref('showuserimage');

        $strfeedback = $this->get_lang_string("feedback");
        $strgrade = $this->get_lang_string('grade');

        $extrafields = get_extra_user_fields($this->context);

        $arrows = $this->get_sort_arrows($extrafields);

        $colspan = 1;
        if (has_capability('gradereport/' . $CFG->grade_profilereport . ':view', $this->context)) {
            $colspan++;
        }
        $colspan += count($extrafields);

        $levels = count($this->gtree->levels) - 1;

        for ($i = 0; $i < $levels; $i++) {
            $fillercell = new html_table_cell();
            $fillercell->attributes['class'] = 'fixedcolumn cell topleft';
            $fillercell->text = ' ';
            $fillercell->colspan = $colspan;
            $row = new html_table_row(array($fillercell));
            $rows[] = $row;
        }

        $headerrow = new html_table_row();
        $headerrow->attributes['class'] = 'heading';

        $studentheader = new html_table_cell();
        $studentheader->attributes['class'] = 'header';
        $studentheader->scope = 'col';
        $studentheader->header = true;
        $studentheader->id = 'studentheader';
        if (has_capability('gradereport/' . $CFG->grade_profilereport . ':view', $this->context)) {
            $studentheader->colspan = 2;
        }
        $studentheader->text = $arrows['studentname'];

        $headerrow->cells[] = $studentheader;

        foreach ($extrafields as $field) {
            $fieldheader = new html_table_cell();
            $fieldheader->attributes['class'] = 'header userfield user' . $field;
            $fieldheader->scope = 'col';
            $fieldheader->header = true;
            $fieldheader->text = $arrows[$field];

            $headerrow->cells[] = $fieldheader;
        }

        $rows[] = $headerrow;

        $rows = $this->get_left_icons_row($rows, $colspan);

        $rowclasses = array('even', 'odd');

        $suspendedstring = null;
        foreach ($this->users as $userid => $user) {
            $userrow = new html_table_row();
            $userrow->id = 'fixed_user_' . $userid;
            $userrow->attributes['class'] = 'r' . $this->rowcount++ . ' ' . $rowclasses[$this->rowcount % 2];

            $usercell = new html_table_cell();
            $usercell->attributes['class'] = 'user';

            $usercell->header = true;
            $usercell->scope = 'row';

            if ($showuserimage) {
                $usercell->text = $OUTPUT->user_picture($user);
            }

            $usercell->text .= html_writer::link(new moodle_url('/user/view.php', array('id' => $user->id, 'course' => $this->course->id)), fullname($user));

            if (!empty($user->suspendedenrolment)) {
                $usercell->attributes['class'] .= ' usersuspended';

                //may be lots of suspended users so only get the string once
                if (empty($suspendedstring)) {
                    $suspendedstring = get_string('userenrolmentsuspended', 'grades');
                }
                $usercell->text .= html_writer::empty_tag('img', array('src' => $OUTPUT->pix_url('i/enrolmentsuspended'), 'title' => $suspendedstring, 'alt' => $suspendedstring, 'class' => 'usersuspendedicon'));
            }

            $userrow->cells[] = $usercell;

            if (has_capability('gradereport/' . $CFG->grade_profilereport . ':view', $this->context)) {
                $userreportcell = new html_table_cell();
                $userreportcell->attributes['class'] = 'userreport';
                $userreportcell->header = true;
                $a = new stdClass();
                $a->user = fullname($user);
                $strgradesforuser = get_string('gradesforuser', 'grades', $a);
                $url = new moodle_url('/grade/report/' . $CFG->grade_profilereport . '/index.php', array('userid' => $user->id, 'id' => $this->course->id));
                $userreportcell->text = $OUTPUT->action_icon($url, new pix_icon('t/grades', $strgradesforuser));
                $userrow->cells[] = $userreportcell;
            }

            foreach ($extrafields as $field) {
                $fieldcell = new html_table_cell();
                $fieldcell->attributes['class'] = 'header userfield user' . $field;
                $fieldcell->header = true;
                $fieldcell->scope = 'row';
                $fieldcell->text = $user->{$field};
                $userrow->cells[] = $fieldcell;
            }

            $rows[] = $userrow;
        }

        /*$rows = $this->get_left_range_row($rows, $colspan);
        $rows = $this->get_left_avg_row($rows, $colspan, true);
        $rows = $this->get_left_avg_row($rows, $colspan);*/

        return $rows;
    }

    /**
     * Builds and returns the rows that will make up the right part of the multi grader report
     * @return array Array of html_table_row objects
     */
    public function get_right_rows() {
        global $CFG, $USER, $OUTPUT, $DB, $PAGE;

        $rows = array();
        $this->rowcount = 0;
        $numrows = count($this->gtree->get_levels());
        $numusers = count($this->users);
        $gradetabindex = 1;
        $columnstounset = array();
        $strgrade = $this->get_lang_string('grade');
        $strfeedback = $this->get_lang_string("feedback");
        $arrows = $this->get_sort_arrows();

        foreach ($this->gtree->get_levels() as $key => $row) {
            if ($key == 0) {
                // do not display course grade category
                // continue;
            }

            $headingrow = new html_table_row();
            $headingrow->attributes['class'] = 'heading_name_row';

            foreach ($row as $columnkey => $element) {
                $sortlink = clone($this->baseurl);
                if (isset($element['object']->id)) {
                    $sortlink->param('sortitemid', $element['object']->id);
                }

                $eid = $element['eid'];
                $object = $element['object'];
                $type = $element['type'];
                $categorystate = @$element['categorystate'];

                if (!empty($element['colspan'])) {
                    $colspan = $element['colspan'];
                } else {
                    $colspan = 1;
                }

                if (!empty($element['depth'])) {
                    $catlevel = 'catlevel' . $element['depth'];
                } else {
                    $catlevel = '';
                }

// Element is a filler
                if ($type == 'filler' or $type == 'fillerfirst' or $type == 'fillerlast') {
                    $fillercell = new html_table_cell();
                    $fillercell->attributes['class'] = $type . ' ' . $catlevel;
                    $fillercell->colspan = $colspan;
                    $fillercell->text = '&nbsp;';
                    $fillercell->header = true;
                    $fillercell->scope = 'col';
                    $headingrow->cells[] = $fillercell;
                }
// Element is a category
                else if ($type == 'category') {
                    $categorycell = new html_table_cell();
                    $categorycell->attributes['class'] = 'category ' . $catlevel;
                    $categorycell->colspan = $colspan;
                    $categorycell->text = shorten_text($element['object']->get_name());
                    $categorycell->text .= $this->get_collapsing_icon($element);
                    $categorycell->header = true;
                    $categorycell->scope = 'col';


                    $headingrow->cells[] = $categorycell;
                }
// Element is a grade_item
                else {
                    //$itemmodule = $element['object']->itemmodule;
                    //$iteminstance = $element['object']->iteminstance;

                    if ($element['object']->id == $this->sortitemid) {
                        if ($this->sortorder == 'ASC') {
                            $arrow = $this->get_sort_arrow('up', $sortlink);
                        } else {
                            $arrow = $this->get_sort_arrow('down', $sortlink);
                        }
                    } else {
                        $arrow = $this->get_sort_arrow('move', $sortlink);
                    }

                    $headerlink = $this->gtree->get_element_header($element, true, $this->get_pref('showactivityicons'), false);

                    $itemcell = new html_table_cell();
                    $itemcell->attributes['class'] = $type . ' ' . $catlevel . ' highlightable';

                    if ($element['object']->is_hidden()) {
                        $itemcell->attributes['class'] .= ' dimmed_text';
                    }

                    $itemcell->colspan = $colspan;
                    $itemcell->text = shorten_text($headerlink);
                    $itemcell->header = true;
                    $itemcell->scope = 'col';

                    $headingrow->cells[] = $itemcell;
                }
            }
            $rows[] = $headingrow;
        }

        $rows = $this->get_right_icons_row($rows);

        // Preload scale objects for items with a scaleid and initialize tab indices
        $scaleslist = array();
        $tabindices = array();

        foreach ($this->gtree->get_items() as $itemid => $item) {
            $scale = null;
            if (!empty($item->scaleid)) {
                $scaleslist[] = $item->scaleid;
            } else {
            }
            $tabindices[$item->id]['grade'] = $gradetabindex;
            $tabindices[$item->id]['feedback'] = $gradetabindex + $numusers;
            $gradetabindex += $numusers * 2;
        }
        $scalesarray = array();

        if (!empty($scaleslist)) {
            $scalesarray = $DB->get_records_list('scale', 'id', $scaleslist);
        }

        $rowclasses = array('even', 'odd');

        foreach ($this->users as $userid => $user) {

            if ($this->canviewhidden) {
                $altered = array();
                $unknown = array();
            } else {
                $hidingaffected = grade_grade::get_hiding_affected($this->grades[$userid], $this->gtree->get_items());
                $altered = $hidingaffected['altered'];
                $unknown = $hidingaffected['unknown'];
                unset($hidingaffected);
            }


            $itemrow = new html_table_row();
            $itemrow->id = 'user_' . $userid;
            $itemrow->attributes['class'] = $rowclasses[$this->rowcount % 2];

            foreach ($this->gtree->items as $itemid => $unused) {
                $item = & $this->gtree->items[$itemid];
                $grade = $this->grades[$userid][$item->id];

                $itemcell = new html_table_cell();

                $itemcell->id = 'u' . $userid . 'i' . $itemid;

                // Get the decimal points preference for this item
                $decimalpoints = $item->get_decimals();

                if (in_array($itemid, $unknown)) {
                    $gradeval = null;
                } else if (array_key_exists($itemid, $altered)) {
                    $gradeval = $altered[$itemid];
                } else {
                    $gradeval = $grade->finalgrade;
                }

                // MDL-11274
                // Hide grades in the multi grader report if the current grader doesn't have 'moodle/grade:viewhidden'
                if (!$this->canviewhidden and $grade->is_hidden()) {
                    if (!empty($CFG->grade_hiddenasdate) and $grade->get_datesubmitted() and !$item->is_category_item() and !$item->is_course_item()) {
                        // the problem here is that we do not have the time when grade value was modified, 'timemodified' is general modification date for grade_grades records
                        $itemcell->text = html_writer::tag('span', userdate($grade->get_datesubmitted(), get_string('strftimedatetimeshort')), array('class' => 'datesubmitted'));
                    } else {
                        $itemcell->text = '-';
                    }
                    $itemrow->cells[] = $itemcell;
                    continue;
                }

                // emulate grade element
                $eid = $this->gtree->get_grade_eid($grade);
                $element = array('eid' => $eid, 'object' => $grade, 'type' => 'grade');

                $itemcell->attributes['class'] .= ' grade';
                if ($item->is_category_item()) {
                    $itemcell->attributes['class'] .= ' cat';
                }
                if ($item->is_course_item()) {
                    $itemcell->attributes['class'] .= ' course';
                }
                if ($grade->is_overridden()) {
                    $itemcell->attributes['class'] .= ' overridden';
                }

                if ($grade->is_excluded()) {
                    // $itemcell->attributes['class'] .= ' excluded';
                }

                if ($grade->is_excluded()) {
                    $itemcell->text .= html_writer::tag('span', get_string('excluded', 'grades'), array('class' => 'excludedfloater'));
                }


                $hidden = '';
                if ($grade->is_hidden()) {
                    $hidden = ' hidden ';
                }

                $gradepass = ' gradefail ';
                if ($grade->is_passed($item)) {
                    $gradepass = ' gradepass ';
                } elseif (is_null($grade->is_passed($item))) {
                    $gradepass = '';
                }

                // if in editing mode, we need to print either a text box
                // or a drop down (for scales)
                // grades in item of type grade category or course are not directly editable
                if ($item->needsupdate) {
                    $itemcell->text .= html_writer::tag('span', get_string('error'), array('class' => "gradingerror$hidden"));
                } else { // Not editing
                    $gradedisplaytype = $item->get_displaytype();

                    if ($item->scaleid && !empty($scalesarray[$item->scaleid])) {
                        $itemcell->attributes['class'] .= ' grade_type_scale';
                    } else if ($item->gradetype != GRADE_TYPE_TEXT) {
                        $itemcell->attributes['class'] .= ' grade_type_text';
                    }

                    if ($this->get_pref('enableajax')) {
                        $itemcell->attributes['class'] .= ' clickable';
                    }

                    if ($item->needsupdate) {
                        $itemcell->text .= html_writer::tag('span', get_string('error'), array('class' => "gradingerror$hidden$gradepass"));
                    } else {
                        $itemcell->text .= html_writer::tag('span', grade_format_gradevalue($gradeval, $item, true, $gradedisplaytype, null), array('class' => "gradevalue$hidden$gradepass"));
                        if ($this->get_pref('showanalysisicon')) {
                            $itemcell->text .= $this->gtree->get_grade_analysis_icon($grade);
                        }
                    }
                }

                if (!empty($this->gradeserror[$item->id][$userid])) {
                    $itemcell->text .= $this->gradeserror[$item->id][$userid];
                }

                $itemrow->cells[] = $itemcell;
            }
            $rows[] = $itemrow;
        }

        //$rows = $this->get_right_range_row($rows);
        //$rows = $this->get_right_avg_row($rows, true);
        //$rows = $this->get_right_avg_row($rows);

        return $rows;
    }

    /**
     * Depending on the style of report (fixedstudents vs traditional one-table),
     * arranges the rows of data in one or two tables, and returns the output of
     * these tables in HTML
     * @return string HTML
     */
    public function get_grade_table() {
        global $OUTPUT;

        $leftrows = $this->get_left_rows();
        $rightrows = $this->get_right_rows();

        $html = '';

        $fulltable = new html_table();
        $fulltable->attributes['class'] = 'gradestable flexible boxaligncenter generaltable';
        $fulltable->id = 'user-grades';

        // Extract rows from each side (left and right) and collate them into one row each
        foreach ($leftrows as $key => $row) {
            $row->cells = array_merge($row->cells, $rightrows[$key]->cells);
            $fulltable->data[] = $row;
        }
        $html .= html_writer::table($fulltable);
        return $OUTPUT->container($html, 'gradeparent');
    }

    /**
     * Builds and return the row of icons for the left side of the report.
     * It only has one cell that says "Controls"
     * @param array $rows The Array of rows for the left part of the report
     * @param int $colspan The number of columns this cell has to span
     * @return array Array of rows for the left part of the report
     */
    public function get_left_icons_row($rows = array(), $colspan = 1) {
        global $USER;

        return $rows;
    }

    /**
     * Builds and return the header for the row of ranges, for the left part of the multi grader report.
     * @param array $rows The Array of rows for the left part of the report
     * @param int $colspan The number of columns this cell has to span
     * @return array Array of rows for the left part of the report
     */
    public function get_left_range_row($rows = array(), $colspan = 1) {
        global $CFG, $USER;

        if ($this->get_pref('showranges')) {
            $rangerow = new html_table_row();
            $rangerow->attributes['class'] = 'range r' . $this->rowcount++;
            $rangecell = new html_table_cell();
            $rangecell->attributes['class'] = 'header range';
            $rangecell->colspan = $colspan;
            $rangecell->header = true;
            $rangecell->scope = 'row';
            $rangecell->text = $this->get_lang_string('range', 'grades');
            $rangerow->cells[] = $rangecell;
            $rows[] = $rangerow;
        }

        return $rows;
    }

    /**
     * Builds and return the headers for the rows of averages, for the left part of the multi grader report.
     * @param array $rows The Array of rows for the left part of the report
     * @param int $colspan The number of columns this cell has to span
     * @param bool $groupavg If true, returns the row for group averages, otherwise for overall averages
     * @return array Array of rows for the left part of the report
     */
    public function get_left_avg_row($rows = array(), $colspan = 1, $groupavg = false) {
        if (!$this->canviewhidden) {
            // totals might be affected by hiding, if user can not see hidden grades the aggregations might be altered
            // better not show them at all if user can not see all hideen grades
            return $rows;
        }

        $showaverages = $this->get_pref('showaverages');
        $showaveragesgroup = $this->currentgroup && $showaverages;
        $straveragegroup = get_string('groupavg', 'grades');

        if ($groupavg) {
            if ($showaveragesgroup) {
                $groupavgrow = new html_table_row();
                $groupavgrow->attributes['class'] = 'groupavg r' . $this->rowcount++;
                $groupavgcell = new html_table_cell();
                $groupavgcell->attributes['class'] = 'header range';
                $groupavgcell->colspan = $colspan;
                $groupavgcell->header = true;
                $groupavgcell->scope = 'row';
                $groupavgcell->text = $straveragegroup;
                $groupavgrow->cells[] = $groupavgcell;
                $rows[] = $groupavgrow;
            }
        } else {
            $straverage = get_string('overallaverage', 'grades');

            if ($showaverages) {
                $avgrow = new html_table_row();
                $avgrow->attributes['class'] = 'avg r' . $this->rowcount++;
                $avgcell = new html_table_cell();
                $avgcell->attributes['class'] = 'header range';
                $avgcell->colspan = $colspan;
                $avgcell->header = true;
                $avgcell->scope = 'row';
                $avgcell->text = $straverage;
                $avgrow->cells[] = $avgcell;
                $rows[] = $avgrow;
            }
        }

        return $rows;
    }

    /**
     * Builds and return the row of icons when editing is on, for the right part of the multi grader report.
     * @param array $rows The Array of rows for the right part of the report
     * @return array Array of rows for the right part of the report
     */
    public function get_right_icons_row($rows = array()) {
        global $USER;

        return $rows;
    }

    /**
     * Builds and return the row of ranges for the right part of the multi grader report.
     * @param array $rows The Array of rows for the right part of the report
     * @return array Array of rows for the right part of the report
     */
    public function get_right_range_row($rows = array()) {
        global $OUTPUT;

        if ($this->get_pref('showranges')) {
            $rangesdisplaytype = $this->get_pref('rangesdisplaytype');
            $rangesdecimalpoints = $this->get_pref('rangesdecimalpoints');
            $rangerow = new html_table_row();
            $rangerow->attributes['class'] = 'heading range';

            foreach ($this->gtree->items as $itemid => $unused) {
                $item = & $this->gtree->items[$itemid];
                $itemcell = new html_table_cell();
                $itemcell->header = true;
                $itemcell->attributes['class'] .= ' header range';

                $hidden = '';
                if ($item->is_hidden()) {
                    $hidden = ' hidden ';
                }

                $formattedrange = $item->get_formatted_range($rangesdisplaytype, $rangesdecimalpoints);

                $itemcell->text = $OUTPUT->container($formattedrange, 'rangevalues' . $hidden);
                $rangerow->cells[] = $itemcell;
            }
            $rows[] = $rangerow;
        }
        return $rows;
    }

    /**
     * Builds and return the row of averages for the right part of the multi grader report.
     * @param array $rows Whether to return only group averages or all averages.
     * @param bool $grouponly Whether to return only group averages or all averages.
     * @return array Array of rows for the right part of the report
     */
    public function get_right_avg_row($rows = array(), $grouponly = false) {
        global $CFG, $USER, $DB, $OUTPUT;

        if (!$this->canviewhidden) {
            // totals might be affected by hiding, if user can not see hidden grades the aggregations might be altered
            // better not show them at all if user can not see all hidden grades
            return $rows;
        }

        $showaverages = $this->get_pref('showaverages');
        $showaveragesgroup = $this->currentgroup && $showaverages;

        $averagesdisplaytype = $this->get_pref('averagesdisplaytype');
        $averagesdecimalpoints = $this->get_pref('averagesdecimalpoints');
        $meanselection = $this->get_pref('meanselection');
        $shownumberofgrades = $this->get_pref('shownumberofgrades');

        $avghtml = '';
        $avgcssclass = 'avg';

        if ($grouponly) {
            $straverage = get_string('groupavg', 'grades');
            $showaverages = $this->currentgroup && $this->get_pref('showaverages');
            $groupsql = $this->groupsql;
            $groupwheresql = $this->groupwheresql;
            $groupwheresqlparams = $this->groupwheresql_params;
            $avgcssclass = 'groupavg';
        } else {
            $straverage = get_string('overallaverage', 'grades');
            $showaverages = $this->get_pref('showaverages');
            $groupsql = "";
            $groupwheresql = "";
            $groupwheresqlparams = array();
        }

        if ($shownumberofgrades) {
            $straverage .= ' (' . get_string('submissions', 'grades') . ') ';
        }

        $totalcount = $this->get_numusers($grouponly);

        // Limit to users with a gradeable role.
        list($gradebookrolessql, $gradebookrolesparams) = $DB->get_in_or_equal(explode(',', $this->gradebookroles), SQL_PARAMS_NAMED, 'grbr0');

        // Limit to users with an active enrollment.
        list($enrolledsql, $enrolledparams) = get_enrolled_sql($this->context);

        // We want to query both the current context and parent contexts.
        list($relatedctxsql, $relatedctxparams) = $DB->get_in_or_equal($this->context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'relatedctx');

        $params = array_merge(array('courseid' => $this->courseid), $gradebookrolesparams, $enrolledparams, $groupwheresqlparams, $relatedctxparams);

        // Find sums of all grade items in course.
        $sql = "SELECT g.itemid, SUM(g.finalgrade) AS sum
                      FROM {grade_items} gi
                      JOIN {grade_grades} g ON g.itemid = gi.id
                      JOIN {user} u ON u.id = g.userid
                      JOIN ($enrolledsql) je ON je.id = u.id
                      JOIN (
                               SELECT DISTINCT ra.userid
                                 FROM {role_assignments} ra
                                WHERE ra.roleid $gradebookrolessql
                                  AND ra.contextid $relatedctxsql
                           ) rainner ON rainner.userid = u.id
                      $groupsql
                     WHERE gi.courseid = :courseid
                       AND u.deleted = 0
                       AND g.finalgrade IS NOT NULL
                       $groupwheresql
                     GROUP BY g.itemid";
        $sumarray = array();
        if ($sums = $DB->get_records_sql($sql, $params)) {
            foreach ($sums as $itemid => $csum) {
                $sumarray[$itemid] = $csum->sum;
            }
        }

        // MDL-10875 Empty grades must be evaluated as grademin, NOT always 0
        // This query returns a count of ungraded grades (NULL finalgrade OR no matching record in grade_grades table)
        $sql = "SELECT gi.id, COUNT(DISTINCT u.id) AS count
                      FROM {grade_items} gi
                      CROSS JOIN {user} u
                      JOIN ($enrolledsql) je
                           ON je.id = u.id
                      JOIN {role_assignments} ra
                           ON ra.userid = u.id
                      LEFT OUTER JOIN {grade_grades} g
                           ON (g.itemid = gi.id AND g.userid = u.id AND g.finalgrade IS NOT NULL)
                      $groupsql
                     WHERE gi.courseid = :courseid
                           AND ra.roleid $gradebookrolessql
                           AND ra.contextid $relatedctxsql
                           AND u.deleted = 0
                           AND g.id IS NULL
                           $groupwheresql
                  GROUP BY gi.id";

        $ungradedcounts = $DB->get_records_sql($sql, $params);

        $avgrow = new html_table_row();
        $avgrow->attributes['class'] = 'avg';

        foreach ($this->gtree->items as $itemid => $unused) {
            $item = & $this->gtree->items[$itemid];

            if ($item->needsupdate) {
                $avgcell = new html_table_cell();
                $avgcell->text = $OUTPUT->container(get_string('error'), 'gradingerror');
                $avgrow->cells[] = $avgcell;
                continue;
            }

            if (!isset($sumarray[$item->id])) {
                $sumarray[$item->id] = 0;
            }

            if (empty($ungradedcounts[$itemid])) {
                $ungradedcount = 0;
            } else {
                $ungradedcount = $ungradedcounts[$itemid]->count;
            }

            if ($meanselection == GRADE_REPORT_MEAN_GRADED) {
                $meancount = $totalcount - $ungradedcount;
            } else { // Bump up the sum by the number of ungraded items * grademin
                $sumarray[$item->id] += $ungradedcount * $item->grademin;
                $meancount = $totalcount;
            }

            $decimalpoints = $item->get_decimals();

            // Determine which display type to use for this average
            if ($averagesdisplaytype == GRADE_REPORT_PREFERENCE_INHERIT) { // no ==0 here, please resave the report and user preferences
                $displaytype = $item->get_displaytype();
            } else {
                $displaytype = $averagesdisplaytype;
            }

            // Override grade_item setting if a display preference (not inherit) was set for the averages
            if ($averagesdecimalpoints == GRADE_REPORT_PREFERENCE_INHERIT) {
                $decimalpoints = $item->get_decimals();
            } else {
                $decimalpoints = $averagesdecimalpoints;
            }

            if (!isset($sumarray[$item->id]) || $meancount == 0) {
                $avgcell = new html_table_cell();
                $avgcell->text = '-';
                $avgrow->cells[] = $avgcell;
            } else {
                $sum = $sumarray[$item->id];
                $avgradeval = $sum / $meancount;
                $gradehtml = grade_format_gradevalue($avgradeval, $item, true, $displaytype, $decimalpoints);

                $numberofgrades = '';
                if ($shownumberofgrades) {
                    $numberofgrades = " ($meancount)";
                }

                $avgcell = new html_table_cell();
                $avgcell->text = $gradehtml . $numberofgrades;
                $avgrow->cells[] = $avgcell;
            }
        }
        $rows[] = $avgrow;
        return $rows;
    }

    /**
     * Given a grade_category, grade_item or grade_grade, this function
     * figures out the state of the object and builds then returns a div
     * with the icons needed for the grader report.
     *
     * @param array $object
     * @return string HTML
     */
    protected function get_icons($element) {
        global $CFG, $USER, $OUTPUT;

        // Init all icons
        $editicon = '';

        if ($element['type'] != 'categoryitem' && $element['type'] != 'courseitem') {
            $editicon = $this->gtree->get_edit_icon($element, $this->gpr);
        }

        $editcalculationicon = '';
        $showhideicon = '';
        $lockunlockicon = '';

        if (has_capability('moodle/grade:manage', $this->context)) {
            if ($this->get_pref('showcalculations')) {
                $editcalculationicon = $this->gtree->get_calculation_icon($element, $this->gpr);
            }

            if ($this->get_pref('showeyecons')) {
                $showhideicon = $this->gtree->get_hiding_icon($element, $this->gpr);
            }

            if ($this->get_pref('showlocks')) {
                $lockunlockicon = $this->gtree->get_locking_icon($element, $this->gpr);
            }

        }

        $gradeanalysisicon = '';
        if ($this->get_pref('showanalysisicon') && $element['type'] == 'grade') {
            $gradeanalysisicon .= $this->gtree->get_grade_analysis_icon($element['object']);
        }
        return;
        //return $OUTPUT->container($editicon.$editcalculationicon.$showhideicon.$lockunlockicon.$gradeanalysisicon, 'grade_icons');
    }

    /**
     * Given a category element returns collapsing +/- icon if available
     * @param object $object
     * @return string HTML
     */
    protected function get_collapsing_icon($element) {
        global $OUTPUT;

        $icon = '';
        // If object is a category, display expand/contract icon
        if ($element['type'] == 'category') {
            // Load language strings
            $strswitchminus = $this->get_lang_string('aggregatesonly', 'grades');
            $strswitchplus = $this->get_lang_string('gradesonly', 'grades');
            $strswitchwhole = $this->get_lang_string('fullmode', 'grades');

            $url = new moodle_url($this->gpr->get_return_url(null, array('target' => $element['eid'], 'sesskey' => sesskey())));

            if (in_array($element['object']->id, $this->collapsed['aggregatesonly'])) {
                $url->param('action', 'switch_plus');
                $icon = $OUTPUT->action_icon($url, new pix_icon('t/switch_plus', $strswitchplus));

            } else if (in_array($element['object']->id, $this->collapsed['gradesonly'])) {
                $url->param('action', 'switch_whole');
                $icon = $OUTPUT->action_icon($url, new pix_icon('t/switch_whole', $strswitchwhole));

            } else {
                $url->param('action', 'switch_minus');
                $icon = $OUTPUT->action_icon($url, new pix_icon('t/switch_minus', $strswitchminus));
            }
        }
        $icon = '';
        return $icon;
    }

    public function process_action($target, $action) {
        return self::do_process_action($target, $action);
    }

    /**
     * Processes a single action against a category, grade_item or grade.
     * @param string $target eid ({type}{id}, e.g. c4 for category4)
     * @param string $action Which action to take (edit, delete etc...)
     * @return
     */
    public static function do_process_action($target, $action) {
        // TODO: this code should be in some grade_tree static method
        $targettype = substr($target, 0, 1);
        $targetid = substr($target, 1);
        // TODO: end

        if ($collapsed = get_user_preferences('grade_report_grader_collapsed_categories')) {
            $collapsed = unserialize($collapsed);
        } else {
            $collapsed = array('aggregatesonly' => array(), 'gradesonly' => array());
        }

        switch ($action) {
            case 'switch_minus': // Add category to array of aggregatesonly
                if (!in_array($targetid, $collapsed['aggregatesonly'])) {
                    $collapsed['aggregatesonly'][] = $targetid;
                    set_user_preference('grade_report_grader_collapsed_categories', serialize($collapsed));
                }
                break;

            case 'switch_plus': // Remove category from array of aggregatesonly, and add it to array of gradesonly
                $key = array_search($targetid, $collapsed['aggregatesonly']);
                if ($key !== false) {
                    unset($collapsed['aggregatesonly'][$key]);
                }
                if (!in_array($targetid, $collapsed['gradesonly'])) {
                    $collapsed['gradesonly'][] = $targetid;
                }
                set_user_preference('grade_report_multigrader_collapsed_categories', serialize($collapsed));
                break;
            case 'switch_whole': // Remove the category from the array of collapsed cats
                $key = array_search($targetid, $collapsed['gradesonly']);
                if ($key !== false) {
                    unset($collapsed['gradesonly'][$key]);
                    set_user_preference('grade_report_multigrader_collapsed_categories', serialize($collapsed));
                }

                break;
            default:
                break;
        }

        return true;
    }

    /**
     * Refactored function for generating HTML of sorting links with matching arrows.
     * Returns an array with 'studentname' and 'idnumber' as keys, with HTML ready
     * to inject into a table header cell.
     * @param array $extrafields Array of extra fields being displayed, such as
     *   user idnumber
     * @return array An associative array of HTML sorting links+arrows
     */
    public function get_sort_arrows(array $extrafields = array()) {
        global $OUTPUT;
        $arrows = array();

        $strsortasc = $this->get_lang_string('sortasc', 'grades');
        $strsortdesc = $this->get_lang_string('sortdesc', 'grades');
        $strfirstname = $this->get_lang_string('firstname');
        $strlastname = $this->get_lang_string('lastname');

        $firstlink = $strfirstname;
        $lastlink = $strlastname;

        $arrows['studentname'] = $lastlink;

        if ($this->sortitemid === 'lastname') {
            if ($this->sortorder == 'ASC') {
                $arrows['studentname'] ;
            } else {
                $arrows['studentname'] ;
            }
        }

        $arrows['studentname'] .= ' ' . $firstlink;

        if ($this->sortitemid === 'firstname') {
            if ($this->sortorder == 'ASC') {
                $arrows['studentname'] ;
            } else {
                $arrows['studentname'] ;
            }
        }

        foreach ($extrafields as $field) {
            $fieldlink =  get_user_field_name($field);
            $arrows[$field] = $fieldlink;

            if ($field == $this->sortitemid) {
                if ($this->sortorder == 'ASC') {
                    $arrows[$field] ;
                } else {
                    $arrows[$field] ;
                }
            }
        }

        return $arrows;
    }

    /**
     * Returns the maximum number of students to be displayed on each page
     *
     * Takes into account the 'studentsperpage' user preference and the 'max_input_vars'
     * PHP setting. Too many fields is only a problem when submitting grades but
     * we respect 'max_input_vars' even when viewing grades to prevent students disappearing
     * when toggling editing on and off.
     *
     * @return int The maximum number of students to display per page
     */
    public function get_students_per_page() {
        global $USER;
        static $studentsperpage = null;

        if ($studentsperpage === null) {
            $originalstudentsperpage = $studentsperpage = $this->get_pref('studentsperpage');

            // Will this number of students result in more fields that we are allowed?
            $maxinputvars = ini_get('max_input_vars');
            if ($maxinputvars !== false) {
                $fieldspergradeitem = 0; // The number of fields output per grade item for each student

                if ($this->get_pref('quickgrading')) {
                    // One grade field
                    $fieldspergradeitem++;
                }
                if ($this->get_pref('showquickfeedback')) {
                    // One feedback field
                    $fieldspergradeitem++;
                }

                $fieldsperstudent = $fieldspergradeitem * count($this->gtree->get_items());
                $fieldsrequired = $studentsperpage * $fieldsperstudent;
                if ($fieldsrequired > $maxinputvars) {
                    $studentsperpage = floor($maxinputvars / $fieldsperstudent);
                    if ($studentsperpage < 1) {
                        // Make sure students per page doesn't fall below 1
                        // PHP max_input_vars could potentially be reached with 1 student
                        // if there are >500 grade items and quickgrading and showquickfeedback are on
                        $studentsperpage = 1;
                    }

                    $a = new stdClass();
                    $a->originalstudentsperpage = $originalstudentsperpage;
                    $a->studentsperpage = $studentsperpage;
                    $a->maxinputvars = $maxinputvars;
                    debugging(get_string('studentsperpagereduced', 'grades', $a));
                }
            }
        }

        return $studentsperpage;
    }

}


/**
 * Class providing an API for the multi grader report building and displaying.
 * @uses grade_report
 * @package gradereport_multigrader
 */
class report_multicourse extends grade_report_multigrader {

    public function __construct($courseid, $gpr, $context, $users = [], $page = null, $sortitemid = null)
    {
        global $CFG, $DB;
        parent::__construct($courseid, $gpr, $context, $page, $sortitemid);
        //if (!$this->users)
        {
            if (!empty($users)) {
                $this->users = $users;
                list($usql, $uparams) = $DB->get_in_or_equal(array_keys($this->users), SQL_PARAMS_NAMED, 'usid0');
                $this->userselect = "AND g.userid $usql";
                $this->userselect_params = $uparams;
            } else {
                $this->load_users();
            }
        }
    }

    /**
     * Builds and returns the rows that will make up the left part of the grader report
     * This consists of student names and icons, links to user reports and id numbers, as well
     * as header cells for these columns. It also includes the fillers required for the
     * categories displayed on the right side of the report.
     * @param boolean $displayaverages whether to display average rows in the table
     * @return array Array of html_table_row objects
     */
    public function get_left_rows() {
        global $CFG, $USER, $OUTPUT;

        $rows = array();

        $showuserimage = $this->get_pref('showuserimage');

        $strfeedback = $this->get_lang_string("feedback");
        $strgrade = $this->get_lang_string('grade');

        $extrafields = []; ////////////////get_extra_user_fields($this->context);

        $arrows = $this->get_sort_arrows($extrafields);

        $colspan = 1;
        if (has_capability('gradereport/' . $CFG->grade_profilereport . ':view', $this->context)) {
            $colspan++;
        }
        $colspan += count($extrafields);

        $levels = count($this->gtree->levels) - 1;

        for ($i = 0; $i < $levels; $i++) {
            $fillercell = new html_table_cell();
            $fillercell->attributes['class'] = 'fixedcolumn cell topleft';
            $fillercell->text = ' ';
            $fillercell->colspan = $colspan;
            $row = new html_table_row(array($fillercell));
            $rows[] = $row;
        }

        $headerrow = new html_table_row();
        $headerrow->attributes['class'] = 'heading';

        $studentheader = new html_table_cell();
        $studentheader->attributes['class'] = 'header';
        $studentheader->scope = 'col';
        $studentheader->header = true;
        $studentheader->id = 'studentheader';
        if (has_capability('gradereport/' . $CFG->grade_profilereport . ':view', $this->context)) {
            $studentheader->colspan = 2;
        }
        $studentheader->text = $arrows['studentname'];

        $headerrow->cells[] = $studentheader;

        foreach ($extrafields as $field) {
            $fieldheader = new html_table_cell();
            $fieldheader->attributes['class'] = 'header userfield user' . $field;
            $fieldheader->scope = 'col';
            $fieldheader->header = true;
            $fieldheader->text = $arrows[$field];

            $headerrow->cells[] = $fieldheader;
        }

        $rows[] = $headerrow;

        $rows = $this->get_left_icons_row($rows, $colspan);

        $rowclasses = array('even', 'odd');

        $suspendedstring = null;
        foreach ($this->users as $userid => $user) {
            $userrow = new html_table_row();
            $userrow->id = 'fixed_user_' . $userid;
            $userrow->attributes['class'] = 'r' . $this->rowcount++ . ' ' . $rowclasses[$this->rowcount % 2];

            $usercell = new html_table_cell();
            $usercell->attributes['class'] = 'user';

            $usercell->header = true;
            $usercell->scope = 'row';

            if ($showuserimage) {
                $usercell->text = $OUTPUT->user_picture($user);
            }

            $usercell->text .= html_writer::link(new moodle_url('/user/view.php', array('id' => $user->id, 'course' => $this->course->id)), fullname($user));

            if (!empty($user->suspendedenrolment)) {
                $usercell->attributes['class'] .= ' usersuspended';

                //may be lots of suspended users so only get the string once
                if (empty($suspendedstring)) {
                    $suspendedstring = get_string('userenrolmentsuspended', 'grades');
                }
                $usercell->text .= html_writer::empty_tag('img', array('src' => $OUTPUT->pix_url('i/enrolmentsuspended'), 'title' => $suspendedstring, 'alt' => $suspendedstring, 'class' => 'usersuspendedicon'));
            }

            $userrow->cells[] = $usercell;

            if (has_capability('gradereport/' . $CFG->grade_profilereport . ':view', $this->context)) {
                $userreportcell = new html_table_cell();
                $userreportcell->attributes['class'] = 'userreport';
                $userreportcell->header = true;
                $a = new stdClass();
                $a->user = fullname($user);
                $strgradesforuser = get_string('gradesforuser', 'grades', $a);
                $url = new moodle_url('/grade/report/' . $CFG->grade_profilereport . '/index.php', array('userid' => $user->id, 'id' => $this->course->id));
                $userreportcell->text = $OUTPUT->action_icon($url, new pix_icon('t/grades', $strgradesforuser));
                $userrow->cells[] = $userreportcell;
            }

            foreach ($extrafields as $field) {
                $fieldcell = new html_table_cell();
                $fieldcell->attributes['class'] = 'header userfield user' . $field;
                $fieldcell->header = true;
                $fieldcell->scope = 'row';
                $fieldcell->text = $user->{$field};
                $userrow->cells[] = $fieldcell;
            }

            $rows[] = $userrow;
        }

        /*$rows = $this->get_left_range_row($rows, $colspan);
        $rows = $this->get_left_avg_row($rows, $colspan, true);
        $rows = $this->get_left_avg_row($rows, $colspan);*/

        return $rows;
    }

    /**
     * Depending on the style of report (fixedstudents vs traditional one-table),
     * arranges the rows of data in one or two tables, and returns the output of
     * these tables in HTML
     * @return string HTML
     */
    public function get_grade_table() {
        global $OUTPUT;

        $leftrows = $this->get_left_rows();
        $rightrows = $this->get_right_rows();

        $html = '';

        $fulltable = new html_table();
        $fulltable->attributes['class'] = 'gradestable flexible boxaligncenter generaltable';
        $fulltable->id = 'user-grades';

        // Extract rows from each side (left and right) and collate them into one row each
        foreach ($leftrows as $key => $row) {
            $row->cells = array_merge($row->cells, $rightrows[$key]->cells);
            $fulltable->data[] = $row;
        }
        //$html .= html_writer::table($fulltable);

        /////////////////////////
        $transtable = $this->grade_transtable();
        $html .= $transtable;

        return $OUTPUT->container($html, 'gradeparent');
    }

    // new table - transponired
    public function grade_transtable() {
        global $CFG, $USER, $OUTPUT, $DB, $PAGE;

        $showuserimage = $this->get_pref('showuserimage');

        $transtable = new html_table();
        $transtable->attributes['class'] = 'gradestable flexible boxaligncenter generaltable';
        $transtable->id = 'user-grades';

        // build header
        $headerrow = new html_table_row();
        $headerrow->attributes['class'] = 'heading';

        $courseheader = new html_table_cell();
        $courseheader->attributes['class'] = 'header';
        $courseheader->scope = 'col';
        $courseheader->header = true;
        $courseheader->id = 'courseheader';
        /*if (has_capability('gradereport/' . $CFG->grade_profilereport . ':view', $this->context)) {
            $courseheader->colspan = 2;
        }*/
        $courseheader->text = 'Course name'; //$arrows['studentname'];

        $headerrow->cells[] = $courseheader;

        $rowclasses = array('even', 'odd');

        $suspendedstring = null;
        foreach ($this->users as $userid => $user) {
            /*$userrow = new html_table_row();
            $userrow->id = 'fixed_user_' . $userid;
            $userrow->attributes['class'] = 'r' . $this->rowcount++ . ' ' . $rowclasses[$this->rowcount % 2];*/

            $usercell = new html_table_cell();
            $usercell->attributes['class'] = 'user';
            $usercell->header = true;
            $usercell->scope = 'row';
            $link = html_writer::link(new moodle_url('/user/view.php', array('id' => $user->id, 'course' => $this->course->id)), fullname($user));
            $usercell->text = ($showuserimage ? $OUTPUT->user_picture($user) : '') . $link;

            if (!empty($user->suspendedenrolment)) {
                $usercell->attributes['class'] .= ' usersuspended';
                //may be lots of suspended users so only get the string once
                if (empty($suspendedstring)) {
                    $suspendedstring = get_string('userenrolmentsuspended', 'grades');
                }
                $usercell->text .= html_writer::empty_tag('img', array('src' => $OUTPUT->pix_url('i/enrolmentsuspended'), 'title' => $suspendedstring, 'alt' => $suspendedstring, 'class' => 'usersuspendedicon'));
            }

            if (has_capability('gradereport/' . $CFG->grade_profilereport . ':view', $this->context)) {
                /*$userreportcell = new html_table_cell();
                $userreportcell->attributes['class'] = 'userreport';
                $userreportcell->header = true;*/
                $a = new stdClass();
                $a->user = fullname($user);
                $strgradesforuser = get_string('gradesforuser', 'grades', $a);
                $url = new moodle_url('/grade/report/' . $CFG->grade_profilereport . '/index.php', array('userid' => $user->id, 'id' => $this->course->id));
                //$userreportcell->text = $OUTPUT->action_icon($url, new pix_icon('t/grades', $strgradesforuser));
                //$userreportcell->text = $OUTPUT->action_icon($url, new pix_icon('t/grades', $strgradesforuser));
                //$userrow->cells[] = $userreportcell;
                $usercell->text .= $OUTPUT->action_icon($url, new pix_icon('t/grades', $strgradesforuser));
            }
            $headerrow->cells[] = $usercell;
        }
        $transtable->data[] = $headerrow;

        // --------------- build body: courses + mods + grades
        $rows = array();
        $this->rowcount = 0;
        $numrows = count($this->gtree->get_levels());
        $numusers = count($this->users);
        $gradetabindex = 1;
        $columnstounset = array();
        $strgrade = $this->get_lang_string('grade');
        $strfeedback = $this->get_lang_string("feedback");
        $arrows = $this->get_sort_arrows();

        foreach ($this->gtree->get_levels() as $key => $row) {
            if ($key == 0) {
                // do not display course grade category
                // continue;
            }

            //$graderow = new html_table_row();
            //$graderow->attributes['class'] = 'heading_name_row';

            foreach ($row as $columnkey => $element) {

                $graderow = new html_table_row();
                $graderow->attributes['class'] = 'heading_name_row';

                $sortlink = clone($this->baseurl);
                if (isset($element['object']->id)) {
                    $sortlink->param('sortitemid', $element['object']->id);
                }

                $eid = $element['eid'];
                $object = $element['object'];
                $type = $element['type'];
                $categorystate = @$element['categorystate'];
                //$colspan = !empty($element['colspan'])? $element['colspan'] : 1;
                $colspan = count($this->users) + 1;
                $catlevel = !empty($element['depth']) ? 'catlevel' . $element['depth'] : '';

                $itemcell = new html_table_cell();
                $itemcell->header = true;
                $itemcell->scope = 'col';
                $itemcell->colspan = $colspan;
                if ($type == 'filler' or $type == 'fillerfirst' or $type == 'fillerlast') { // Element is a filler
                    $itemcell->attributes['class'] = $type . ' ' . $catlevel;
                    $itemcell->text = '&nbsp;';
                    $graderow->cells[] = $itemcell;
                } else if ($type == 'category') { // Element is a category
                    $itemcell->attributes['class'] = 'category ' . $catlevel;
                    //$link = html_writer::link(new moodle_url('/user/view.php', array('id' => $user->id, 'course' => $this->course->id)), fullname($user));
                    $thiscourse = $this->gtree->modinfo->get_course();
                    $itemcell->text = html_writer::link(new moodle_url('/grade/report/grader/index.php', ['id' => $thiscourse->id]), $thiscourse->shortname); // . ', ' . shorten_text($element['object']->get_name());
                    $itemcell->text .= $this->get_collapsing_icon($element);
                    $graderow->cells[] = $itemcell;
                } else { // Element is a grade_item
                    //$itemmodule = $element['object']->itemmodule;
                    //$iteminstance = $element['object']->iteminstance;
                    //$colspan = 1;
                    if ($element['object']->id == $this->sortitemid) {
                        if ($this->sortorder == 'ASC') {
                            $arrow = $this->get_sort_arrow('up', $sortlink);
                        } else {
                            $arrow = $this->get_sort_arrow('down', $sortlink);
                        }
                    } else {
                        $arrow = $this->get_sort_arrow('move', $sortlink);
                    }

                    $headerlink = $this->gtree->get_element_header($element, true, $this->get_pref('showactivityicons'), false);
                    $itemcell->text = shorten_text($headerlink);

                    $itemcell->attributes['class'] = $type . ' ' . $catlevel . ' highlightable';

                    if ($element['object']->is_hidden()) {
                        $itemcell->attributes['class'] .= ' dimmed_text';
                    }
                    $itemcell->colspan = 1; //$colspan
                    $itemcell->header = true;
                    $itemcell->scope = 'col';
                    $graderow->cells[] = $itemcell;

                    // --- users cycle
                    $itemid = $object->id;
                    $item = & $this->gtree->items[$itemid];
                    foreach ($this->users as $userid => $user) {

                        if ($this->canviewhidden) {
                            $altered = array();
                            $unknown = array();
                        } else {
                            $hidingaffected = grade_grade::get_hiding_affected($this->grades[$userid], $this->gtree->get_items());
                            $altered = $hidingaffected['altered'];
                            $unknown = $hidingaffected['unknown'];
                            unset($hidingaffected);
                        }

                        $grade = $this->grades[$userid][$item->id];

                        $itemcell = new html_table_cell();
                        $itemcell->id = 'u' . $userid . 'i' . $itemid;

                        // Get the decimal points preference for this item
                        $decimalpoints = $item->get_decimals();

                        if (in_array($itemid, $unknown)) {
                            $gradeval = null;
                        } else if (array_key_exists($itemid, $altered)) {
                            $gradeval = $altered[$itemid];
                        } else {
                            $gradeval = $grade->finalgrade;
                        }

                        // MDL-11274
                        // Hide grades in the multi grader report if the current grader doesn't have 'moodle/grade:viewhidden'
                        /*if (!$this->canviewhidden and $grade->is_hidden()) {
                            if (!empty($CFG->grade_hiddenasdate) and $grade->get_datesubmitted() and !$item->is_category_item() and !$item->is_course_item()) {
                                // the problem here is that we do not have the time when grade value was modified, 'timemodified' is general modification date for grade_grades records
                                $itemcell->text = html_writer::tag('span', userdate($grade->get_datesubmitted(), get_string('strftimedatetimeshort')), array('class' => 'datesubmitted'));
                            } else {
                                $itemcell->text = '-';
                            }
                            $itemrow->cells[] = $itemcell;
                            continue;
                        }*/

                        // emulate grade element
                        $eid = $this->gtree->get_grade_eid($grade);
                        $element = array('eid' => $eid, 'object' => $grade, 'type' => 'grade');

                        $itemcell->attributes['class'] .= ' grade';
                        if ($item->is_category_item()) {
                            $itemcell->attributes['class'] .= ' cat';
                        }
                        if ($item->is_course_item()) {
                            $itemcell->attributes['class'] .= ' course';
                        }
                        if ($grade->is_overridden()) {
                            $itemcell->attributes['class'] .= ' overridden';
                        }
                        if ($grade->is_excluded()) {
                            // $itemcell->attributes['class'] .= ' excluded';
                            $itemcell->text .= html_writer::tag('span', get_string('excluded', 'grades'), array('class' => 'excludedfloater'));
                        }

                        $hidden = $grade->is_hidden() ? ' hidden ' : '';

                        $gradepass = ' gradefail ';
                        if ($grade->is_passed($item)) {
                            $gradepass = ' gradepass ';
                        } elseif (is_null($grade->is_passed($item))) {
                            $gradepass = '';
                        }

                        // if in editing mode, we need to print either a text box
                        // or a drop down (for scales)
                        // grades in item of type grade category or course are not directly editable
                        if ($item->needsupdate) {
                            $itemcell->text .= html_writer::tag('span', get_string('error'), array('class' => "gradingerror$hidden"));
                        } else { // Not editing
                            $gradedisplaytype = $item->get_displaytype();

                            if ($item->scaleid && !empty($scalesarray[$item->scaleid])) {
                                $itemcell->attributes['class'] .= ' grade_type_scale';
                            } else if ($item->gradetype != GRADE_TYPE_TEXT) {
                                $itemcell->attributes['class'] .= ' grade_type_text';
                            }

                            if ($this->get_pref('enableajax')) {
                                $itemcell->attributes['class'] .= ' clickable';
                            }

                            if ($item->needsupdate) {
                                $itemcell->text .= html_writer::tag('span', get_string('error'), array('class' => "gradingerror$hidden$gradepass"));
                            } else {
                                $itemcell->text .= html_writer::tag('span', grade_format_gradevalue($gradeval, $item, true, $gradedisplaytype, null), array('class' => "gradevalue$hidden$gradepass"));
                                if ($this->get_pref('showanalysisicon')) {
                                    $itemcell->text .= $this->gtree->get_grade_analysis_icon($grade);
                                }
                            }
                        }
                        if (!empty($this->gradeserror[$item->id][$userid])) {
                            $itemcell->text .= $this->gradeserror[$item->id][$userid];
                        }
                        $graderow->cells[] = $itemcell;
                    }
                }
                $transtable->data[] = $graderow;
            }
        }

        return html_writer::table($transtable);
    }

}

