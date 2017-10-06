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
class report_multigrader extends grade_report {
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
        //$this->load_users();
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
 * Class providing an API for the multi course report building and displaying.
 * @uses grade_report
 * @package gradereport_multigrader
 */
class report_multicourse {

    protected $_courses = [];
    protected $_learners;
    protected $_warnings = [];

    public function __construct(stdClass $cohort = null)
    {
        global $DB, $CFG;

        if (!$cohort) {
            throw new invalid_parameter_exception('Parameter cohortid can not be empty');
        }
        $this->_courses = $DB->get_records_sql('SELECT c.id, c.fullname, c.shortname, e.sortorder
            FROM mdl_enrol e
              LEFT JOIN mdl_course c ON c.id = e.courseid
            WHERE e.enrol = :type AND e.customint1 = :cohortid
            ORDER BY e.sortorder', ['type' => 'cohort', 'cohortid' => $cohort->id]
        );
        if (!count($this->_courses)) {
            $this->_warnings[] =  get_string('not_courses', 'report_multicourse');
        }

        $search = users_search_sql('');
        $this->_learners = $DB->get_records_sql("SELECT u.id,u.picture,u.firstname,u.lastname,u.firstnamephonetic,u.lastnamephonetic,u.middlename,u.alternatename,u.imagealt,u.email,u.department,u.institution
            FROM {user} u
                JOIN {cohort_members} cm ON (cm.userid = u.id AND cm.cohortid = :cohortid)
                JOIN {cohort} c ON cm.cohortid = c.id
            WHERE $search[0]  
            ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC", array_merge(['cohortid' => $cohort->id, 'contextlevel' => CONTEXT_USER], $search[1]));
        if (!count($this->_learners)) {
            $this->_warnings[] = get_string('not_learners', 'report_multicourse');
            $this->_courses = [];
        }
    }

    public function get_courses() {
        return $this->_courses;
    }

    public function get_learners() {
        return $this->_learners;
    }

    public function get_report($page = 0, $sortitemid = 0) {
        global $OUTPUT;

        foreach ($this->_warnings as $warning) {
            echo $OUTPUT->notification($warning);
        }

        $is_first = true;
        $transtable = new html_table();

        foreach ($this->_courses as $thiscourse) {
            $courseid = $thiscourse->id;
            $context = context_course::instance($courseid);

            // First make sure we have proper final grades - this must be done before constructing of the grade tree.
            grade_regrade_final_grades($courseid);

            if (has_capability('moodle/grade:viewall', $context)) {
                if (has_capability('report/multicourse:view', $context)) {

                    $gpr = new grade_plugin_return(array('type' => 'report', 'plugin' => 'multicourse', 'courseid' => $courseid, 'page' => $page));

                    // Initialise the multi grader report object that produces the table
                    // The class grade_report_grader_ajax was removed as part of MDL-21562.
                    $report = new report_cohortcourses($courseid, $gpr, $context, $this->_learners, $is_first, $page, $sortitemid);
                    $is_first = false;
                    // Processing posted grades & feedback here.
                    if ($data = data_submitted() and confirm_sesskey() and has_capability('moodle/grade:edit', $context)) {
                        $warnings = $report->process_data($data);
                    } else {
                        $warnings = array();
                    }
                    // Final grades MUST be loaded after the processing.
                    //$numusers = $report->get_numusers();
                    $report->load_final_grades();

                    // Show warnings if any.
                    /*foreach ($warnings as $warning) {
                        echo $OUTPUT->notification($warning);
                    }*/
                    $transtable = $report->grade_transtable($transtable);

                    // Prints paging bar at bottom for large pages.
                    /*if (!empty($studentsperpage) && $studentsperpage >= 20) {
                        echo $OUTPUT->paging_bar($numusers, $report->page, $studentsperpage, $report->pbarurl);
                    }*/
                }
            }
        }
        return $OUTPUT->container(html_writer::table($transtable), 'gradeparent');
    }
}

/**
 * Class report_cohortcourses
 */
class report_cohortcourses extends report_multigrader {

    private $is_first = true;
    private $teachers = [];

    public function __construct($courseid, $gpr, $context, $users = [], $is_first = true, $page = null, $sortitemid = null)
    {
        global $CFG, $DB;
        parent::__construct($courseid, $gpr, $context, $page, $sortitemid);

        $this->is_first = $is_first;
        if (!empty($users)) {
            $this->users = $users;
            list($usql, $uparams) = $DB->get_in_or_equal(array_keys($this->users), SQL_PARAMS_NAMED, 'usid0');
            $this->userselect = "AND g.userid $usql";
            $this->userselect_params = $uparams;
        } else {
            $this->load_users();
        }
        // get teachers
        //$sql = 'SELECT ra.id as ra_id, u.id, u.firstname, u.lastname, cr.id as course_id
        $sql = 'SELECT ra.id as ra_id, cr.id as course_id, u.* 
                FROM {role_assignments} ra
                     LEFT JOIN {context} c ON c.id = ra.contextid
                     LEFT JOIN {user} u ON u.id = ra.userid
                     LEFT JOIN {course} cr ON cr.id = c.instanceid
                     LEFT JOIN {role} r ON r.id = ra.roleid
                WHERE c.contextlevel = :contextlevel 
                     AND u.deleted = :deleted AND cr.visible = :visible AND u.id <> :guestid
                     AND c.instanceid = :courseid
                     AND r.shortname in (\'teacher\', \'editingteacher\')
                ORDER BY u.lastname, u.firstname, cr.shortname';

        $this->teachers = $DB->get_records_sql($sql, [
            'contextlevel'=>CONTEXT_COURSE,
            //'roleid'=>$this->role_teacher->id,
            'deleted'=>0, 'visible'=>1, 'guestid'=>$CFG->siteguest,
            'courseid'=>$courseid,
        ]);
    }

    // new table - transponired
    public function grade_transtable(html_table $transtable) {
        global $CFG, $USER, $OUTPUT, $DB, $PAGE;

        $showuserimage = $this->get_pref('showuserimage');

        $transtable->attributes['class'] = 'gradestable flexible boxaligncenter generaltable';
        $transtable->id = 'multicourse-grades';

        // build header
        if ($this->is_first) {
            $headerrow = new html_table_row();
            $headerrow->attributes['class'] = 'heading';
            $userrow = new html_table_row();
            $userrow->attributes['class'] = 'heading';

            $courseheader = new html_table_cell();
            $courseheader->attributes['class'] = 'header';
            $courseheader->scope = 'col';
            $courseheader->header = true;
            $courseheader->id = 'courseheader';
            /*if (has_capability('gradereport/' . $CFG->grade_profilereport . ':view', $this->context)) {
                $courseheader->colspan = 2;
            }*/
            $courseheader->text = get_string('mod_title', 'report_multicourse'); //$arrows['studentname'];
            $headerrow->cells[] = $courseheader;

            $teacherheader = new html_table_cell();
            $teacherheader->attributes['class'] = 'header';
            $teacherheader->scope = 'col';
            $teacherheader->header = true;
            $teacherheader->id = 'teacherheader';
            $teacherheader->text = get_string('teacher_title', 'report_multicourse'); //$arrows['studentname'];
            $headerrow->cells[] = $teacherheader;

            $grademaxheader = new html_table_cell();
            $grademaxheader->attributes['class'] = 'header';
            $grademaxheader->scope = 'col';
            $grademaxheader->header = true;
            $grademaxheader->id = 'grademaxheader';
            $grademaxheader->text = get_string('grademax_title', 'report_multicourse'); //$arrows['studentname'];
            $headerrow->cells[] = $grademaxheader;

            $userheader = new html_table_cell();
            $userheader->header = true;
            $userheader->scope = 'col';
            $userrow->cells[] = $userheader;
            $userrow->cells[] = $userheader;
            $userrow->cells[] = $userheader;

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
                    $userreportcell = new html_table_cell();
                    $userreportcell->attributes['class'] = 'userreport';
                    $userreportcell->header = true;
                    $a = new stdClass();
                    $a->user = fullname($user);
                    $strgradesforuser = get_string('gradesforuser', 'grades', $a);
                    $url = new moodle_url('/grade/report/' . $CFG->grade_profilereport . '/index.php', array('userid' => $user->id, 'id' => $this->course->id));
                    $userreportcell->text = $OUTPUT->action_icon($url, new pix_icon('t/grades', $strgradesforuser));
                    $userreportcell->text = $OUTPUT->action_icon($url, new pix_icon('t/grades', $strgradesforuser));
                    $userrow->cells[] = $userreportcell;
                    $userreportcell->text = $OUTPUT->action_icon($url, new pix_icon('t/grades', $strgradesforuser));
                }
                $headerrow->cells[] = $usercell;
            }
            $transtable->data[] = $headerrow;
            if (count($userrow->cells) > 1) {
                $transtable->data[] = $userrow;
            }
        }

        // --------------- build body: courses + mods + grades
        $rows = array();
        $this->rowcount = 0;
//        $numrows = count($this->gtree->get_levels());
//        $numusers = count($this->users);
//        $gradetabindex = 1;
//        $columnstounset = array();
//        $strgrade = $this->get_lang_string('grade');
//        $strfeedback = $this->get_lang_string("feedback");
//        $arrows = $this->get_sort_arrows();

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

                //$eid = $element['eid'];
                $object = $element['object'];
                $type = $element['type'];
                //$categorystate = @$element['categorystate'];
                //$colspan = !empty($element['colspan'])? $element['colspan'] : 1;
                $colspan = count($this->users) + 3;
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
                    $itemcell->colspan = 1;
                    $graderow->cells[] = $itemcell;

                    $teachercell = new html_table_cell();
                    $teachercell->scope = 'col';
                    $teachercell->colspan = 1; //count($this->users) + 2;
                    $urls = [];
                    foreach ($this->teachers as $teacher) {
                        $urls[] = html_writer::link(new moodle_url('/user/view.php', ['id' => $teacher->id, /*'course' => $this->course->id*/]), fullname($teacher));
                    }
                    $teachercell->text = implode('<br>', $urls);
                    $graderow->cells[] = $teachercell;

                    $fillercell = new html_table_cell();
                    $fillercell->colspan = count($this->users) + 1;
                    $graderow->cells[] = $fillercell;
                } else { // Element is a grade_item
                    //$itemmodule = $element['object']->itemmodule;
                    //$iteminstance = $element['object']->iteminstance;
                    //$colspan = 1;
                    /*if ($element['object']->id == $this->sortitemid) {
                        if ($this->sortorder == 'ASC') {
                            $arrow = $this->get_sort_arrow('up', $sortlink);
                        } else {
                            $arrow = $this->get_sort_arrow('down', $sortlink);
                        }
                    } else {
                        $arrow = $this->get_sort_arrow('move', $sortlink);
                    }*/

                    $headerlink = $this->gtree->get_element_header($element, true, $this->get_pref('showactivityicons'), false);
                    $itemcell->text = shorten_text($headerlink);

                    $itemcell->attributes['class'] = $type . ' ' . $catlevel . ' highlightable';

                    if ($element['object']->is_hidden()) {
                        $itemcell->attributes['class'] .= ' dimmed_text';
                    }
                    $itemcell->colspan = 1; //$colspan
                    $graderow->cells[] = $itemcell;

                    $teachercell = new html_table_cell();
                    $teachercell->text = '';
                    $graderow->cells[] = $teachercell;

                    $grademaxcell = new html_table_cell();
                    $grademaxcell->text = $object->grademax;
                    $graderow->cells[] = $grademaxcell;

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
        return $transtable;
    }

}