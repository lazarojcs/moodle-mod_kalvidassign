<?php

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
 * Kaltura video assignment single submission page
 *
 * @package    mod
 * @subpackage kalvidassign
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once(dirname(__FILE__) . '/lib.php');
require_once(dirname(__FILE__) . '/renderer.php');
require_once(dirname(__FILE__) . '/locallib.php');
require_once(dirname(__FILE__) . '/single_submission_form.php');


$id     = required_param('cmid', PARAM_INT);           // Course Module ID
$userid = required_param('userid', PARAM_INT);
$tifirst = optional_param('tifirst', '', PARAM_TEXT);
$tilast  = optional_param('tilast', '', PARAM_TEXT);
$page    = optional_param('page', 0, PARAM_INT);


list($cm, $course, $kalvidassignobj) = validate_cmid($id);

require_login($course->id, false, $cm);

if (!confirm_sesskey()) {
    print_error('confirmsesskeybad', 'error');
}

require_course_login($course->id, true, $cm);

global $CFG, $PAGE, $OUTPUT, $USER;

$url = new moodle_url('/mod/kalvidassign/single_submission.php');
$url->params(array('cmid' => $id,
                   'userid' => $userid));

$PAGE->set_url($url);
$PAGE->set_title(format_string($kalvidassignobj->name));
$PAGE->set_heading($course->fullname);

$previousurl = new moodle_url('/mod/kalvidassign/grade_submissions.php',
                              array('cmid' => $cm->id,
                                    'tifirst' => $tifirst,
                                    'tilast' => $tilast,
                                    'page' => $page));
$prevousurlstring = get_string('singlesubmissionheader', 'kalvidassign');
$PAGE->navbar->add($prevousurlstring, $previousurl);

$context = get_context_instance(CONTEXT_MODULE, $cm->id);

require_capability('mod/kalvidassign:gradesubmission', $context);

add_to_log($course->id, 'kalvidassign', 'view submission page', 'single_submission.php?id='.$cm->id, $kalvidassignobj->id, $cm->id);

// Get a single submission record
$submission = get_submission($cm->instance, $userid);

// Process submitted data
$submitted_data = data_submitted();

if ($submitted_data) {

    if (!isset($submitted_data->cancel) &&
        isset($submitted_data->xgrade) &&
        isset($submitted_data->submissioncomment_editor)) {

        // Flag used when an instructor is about to grade a user who does not have
        // a submittion (see KALDEV-126)
        $updategrade = true;

        if ($submission) {

            if ($submission->grade == $submitted_data->xgrade &&
                0 == strcmp($submission->submissioncomment, $submitted_data->submissioncomment_editor['text'])) {

                $updategrade = false;
            } else {
                $submission->grade              = $submitted_data->xgrade;
                $submission->submissioncomment  = $submitted_data->submissioncomment_editor['text'];
                $submission->format             = $submitted_data->submissioncomment_editor['format'];
                $submission->timemarked         = time();
                $submission->teacher            = $USER->id;
                $DB->update_record('kalvidassign_submission', $submission);
            }

        } else {

            // Check for unchanged values
            if ('-1' == $submitted_data->xgrade &&
                empty($submitted_data->submissioncomment_editor['text'])) {

                $updategrade = false;
            } else {

                $submission = new stdClass();
                $submission->vidassignid        = $cm->instance;
                $submission->userid             = $userid;
                //$submission->entry_id           = $submitted_data->entry_id;
                $submission->grade              = $submitted_data->xgrade;
                $submission->submissioncomment  = $submitted_data->submissioncomment_editor['text'];
                $submission->format             = $submitted_data->submissioncomment_editor['format'];
                $submission->timemarked         = time();
                $submission->teacher            = $USER->id;

                $DB->insert_record('kalvidassign_submission', $submission);
            }


        }

        if ($updategrade) {
            $kalvidassignobj->cmidnumber = $cm->idnumber;

            $gradeobj = get_submission_grade_object($kalvidassignobj->id, $userid);

            kalvidassign_grade_item_update($kalvidassignobj, $gradeobj);

            //add to log
            add_to_log($kalvidassignobj->course, 'kalvidassign', 'update grades',
                       'grade_submissions.php?cmid='.$cm->id, $cm->id);

        }

        // Handle outcome data
        if (!empty($CFG->enableoutcomes)) {
            require_once($CFG->libdir.'/gradelib.php');

            $data = array();
            $grading_info = grade_get_grades($course->id, 'mod', 'kalvidassign',
                                             $kalvidassignobj->id, $userid);
    
            if (!empty($grading_info->outcomes)) {
                foreach($grading_info->outcomes as $n => $old) {
                    $name = 'outcome_'.$n;
                    if (isset($submitted_data->{$name}[$userid]) and 
                        $old->grades[$userid]->grade != $submitted_data->{$name}[$userid]) {
                            
                        $data[$n] = $submitted_data->{$name}[$userid];
                    }
                }
            }

            if (count($data) > 0) {
                grade_update_outcomes('mod/kalvidassign', $course->id, 'mod',
                                      'kalvidassign', $kalvidassignobj->id, $userid, $data);
            }
        }

    }

    redirect($previousurl);

}

// Try connection
$result = login(true, '');

if ($result) {

    if (has_mobile_flavor_enabled() && get_enable_html5()) {
        $uiconf_id = get_player_uiconf('presentation');
        $url = new moodle_url(htm5_javascript_url($uiconf_id));
        $PAGE->requires->js($url, true);
        $PAGE->requires->js('/local/kaltura/js/frameapi.js', true);
    }
}

$param = array('id' => $userid);
$user = $DB->get_record('user', $param);

$pageheading = get_string('gradesubmission', 'kalvidassign');



echo $OUTPUT->heading($pageheading . ': ' . fullname($user));

// Get the submission user and the time they submitted the video
$submissionuserpic   = $OUTPUT->user_picture($user);
$submission_modified = ' - ';
$datestring_late     = ' - ';
$datestring          = ' - ';
$teacher             = '';
$grading_info        = '';
$gradingdisabled     = '';

if (!empty($submission)) {
    $datestring_late = display_lateness($submission->timemodified, $kalvidassignobj->timedue);
    $submission_modified = userdate($submission->timemodified);
    $datestring = userdate($submission->timemarked)."&nbsp; (".format_time(time() - $submission->timemarked).")";

    $param = array('id' => $submission->teacher);
    $teacher = $DB->get_record('user', $param);


}

// Get grading information
$grading_info = grade_get_grades($cm->course, 'mod', 'kalvidassign', $cm->instance, array($userid));
$gradingdisabled = $grading_info->items[0]->grades[$userid]->locked || $grading_info->items[0]->grades[$userid]->overridden;


$submissionuserinfo = fullname($user) . '<br />'.$submission_modified . $datestring_late;

// Get marking teacher information and the time the submission was marked

$markingteacherpic = '';
$markingtreacherinfo = '';

if (!empty($teacher)) {
    $markingteacherpic = $OUTPUT->user_picture($teacher);
    $markingtreacherinfo = fullname($teacher) . '<br />' . $datestring;
}



// Setup form data
$formdata = new stdClass();
$formdata->submissionuserpic = $submissionuserpic;
$formdata->submissionuserinfo = $submissionuserinfo;
$formdata->markingteacherpic = $markingteacherpic;
$formdata->markingteacherinfo = $markingtreacherinfo;
$formdata->grading_info = $grading_info;
$formdata->gradingdisabled = $gradingdisabled;
$formdata->cm = $cm;
$formdata->context = $context;
$formdata->cminstance = $kalvidassignobj;
$formdata->submission = $submission;
$formdata->userid = $userid;
$formdata->enableoutcomes = $CFG->enableoutcomes;
$formdata->submissioncommentformat = FORMAT_HTML;
$formdata->tifirst = $tifirst;
$formdata->tilast = $tilast;
$formdata->page = $page;

$submissionform =  new singlesubmissionform(null, $formdata);

if ($submissionform->is_cancelled()) {
    print_object($previousurl);
    redirect($previousurl);
}

if (has_mobile_flavor_enabled() && get_enable_html5()) {
    $uiconf_id = get_player_uiconf('player');
    $url = new moodle_url(htm5_javascript_url($uiconf_id));
    $PAGE->requires->js($url, true);
    $url = new moodle_url('/local/kaltura/js/frameapi.js');
    $PAGE->requires->js($url, true);
}

echo $OUTPUT->header();

$submissionform->set_data($formdata);

$submissionform->display();
