<?php
// This file is part of Moodle - http://moodle.org/
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
 * This file defines the quiz grades table.
 *
 * @package   quiz_randomsummary
 * @copyright 2015 Dan Marsden http://danmarsden.com
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/attemptsreport_table.php');


/**
 * This is a table subclass for displaying the quiz grades report.
 *
 * @copyright 2008 Jamie Pratt
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_randomsummary_table extends quiz_attempts_report_table {

    protected $regradedqs = array();

    /**
     * Constructor
     * @param object $quiz
     * @param context $context
     * @param string $qmsubselect
     * @param quiz_randomsummary_options $options
     * @param array $groupstudents
     * @param array $students
     * @param array $questions
     * @param moodle_url $reporturl
     */
    public function __construct($quiz, $context, $qmsubselect,
            quiz_randomsummary_options $options, $groupstudents, $students, $questions, $reporturl) {
        parent::__construct('mod-quiz-report-randomsummary-report', $quiz , $context,
                $qmsubselect, $options, $groupstudents, $students, $questions, $reporturl);
    }

    public function build_table() {
        global $DB;

        if (!$this->rawdata) {
            return;
        }

        $this->strtimeformat = str_replace(',', ' ', get_string('strftimedatetime'));
        parent::build_table();

        // End of adding the data from attempts. Now add averages at bottom.
        $this->add_separator();

        if ($this->groupstudents) {
            $this->add_average_row(get_string('groupavg', 'grades'), $this->groupstudents);
        }

        if ($this->students) {
            $this->add_average_row(get_string('overallaverage', 'grades'), $this->students);
        }
    }

    /**
     * Add an average grade over the attempts of a set of users.
     * @param string $label the title ot use for this row.
     * @param array $users the users to average over.
     */
    protected function add_average_row($label, $users) {
        global $DB;

        list($fields, $from, $where, $params) = $this->base_sql($users);
        $record = $DB->get_record_sql("
                SELECT AVG(quiza.sumgrades) AS grade, COUNT(quiza.sumgrades) AS numaveraged
                  FROM $from
                 WHERE $where", $params);
        $record->grade = quiz_rescale_grade($record->grade, $this->quiz, false);

        if ($this->is_downloading()) {
            $namekey = 'lastname';
        } else {
            $namekey = 'fullname';
        }
        $averagerow = array(
            $namekey    => $label,
            'sumgrades' => $this->format_average($record),
            'feedbacktext'=> strip_tags(quiz_report_feedback_for_grade(
                                        $record->grade, $this->quiz->id, $this->context))
        );


        $dm = new quiz_randomsummary_question_engine_data_mapper();
        $qubaids = new qubaid_join($from, 'quiza.uniqueid', $where, $params);
        $slots = array();
        print_object($this->questions);
        foreach ($this->questions as $qa) {
            $slots[] = $qa->slot;
        }
        $attempts = $dm->load_questions_usages_question_state_summary($qubaids, $slots);

        $avggradebyq = $dm->load_average_marks($qubaids, array_keys($this->questions));


        $averagerow += $this->format_average_grade_for_questions($avggradebyq);

        $this->add_data_keyed($averagerow);
    }

    /**
     * Helper userd by {@link add_average_row()}.
     * @param array $gradeaverages the raw grades.
     * @return array the (partial) row of data.
     */
    protected function format_average_grade_for_questions($gradeaverages) {
        $row = array();

        if (!$gradeaverages) {
            $gradeaverages = array();
        }

        foreach ($this->questions as $question) {
            if (isset($gradeaverages[$question->slot]) && $question->maxmark > 0) {
                $record = $gradeaverages[$question->slot];
                $record->grade = quiz_rescale_grade(
                        $record->averagefraction * $question->maxmark, $this->quiz, false);

            } else {
                $record = new stdClass();
                $record->grade = null;
                $record->numaveraged = 0;
            }

            $row['qsgrade' . $question->slot] = $this->format_average($record, true);
        }

        return $row;
    }

    /**
     * Format an entry in an average row.
     * @param object $record with fields grade and numaveraged
     */
    protected function format_average($record, $question = false) {
        if (is_null($record->grade)) {
            $average = '-';
        } else if ($question) {
            $average = quiz_format_question_grade($this->quiz, $record->grade);
        } else {
            $average = quiz_format_grade($this->quiz, $record->grade);
        }

        if ($this->download) {
            return $average;
        } else if (is_null($record->numaveraged) || $record->numaveraged == 0) {
            return html_writer::tag('span', html_writer::tag('span',
                    $average, array('class' => 'average')), array('class' => 'avgcell'));
        } else {
            return html_writer::tag('span', html_writer::tag('span',
                    $average, array('class' => 'average')) . ' ' . html_writer::tag('span',
                    '(' . $record->numaveraged . ')', array('class' => 'count')),
                    array('class' => 'avgcell'));
        }
    }

    protected function submit_buttons() {
        parent::submit_buttons();
    }

    public function col_sumgrades($attempt) {
        if ($attempt->state != quiz_attempt::FINISHED) {
            return '-';
        }

        $grade = quiz_rescale_grade($attempt->sumgrades, $this->quiz);
        if ($this->is_downloading()) {
            return $grade;
        }

        if (isset($this->regradedqs[$attempt->usageid])) {
            $newsumgrade = 0;
            $oldsumgrade = 0;
            foreach ($this->questions as $question) {
                if (isset($this->regradedqs[$attempt->usageid][$question->slot])) {
                    $newsumgrade += $this->regradedqs[$attempt->usageid]
                            [$question->slot]->newfraction * $question->maxmark;
                    $oldsumgrade += $this->regradedqs[$attempt->usageid]
                            [$question->slot]->oldfraction * $question->maxmark;
                } else {
                    $newsumgrade += $this->lateststeps[$attempt->usageid]
                            [$question->slot]->fraction * $question->maxmark;
                    $oldsumgrade += $this->lateststeps[$attempt->usageid]
                            [$question->slot]->fraction * $question->maxmark;
                }
            }
            $newsumgrade = quiz_rescale_grade($newsumgrade, $this->quiz);
            $oldsumgrade = quiz_rescale_grade($oldsumgrade, $this->quiz);
            $grade = html_writer::tag('del', $oldsumgrade) . '/' .
                    html_writer::empty_tag('br') . $newsumgrade;
        }
        return html_writer::link(new moodle_url('/mod/quiz/review.php',
                array('attempt' => $attempt->attempt)), $grade,
                array('title' => get_string('reviewattempt', 'quiz')));
    }

    /**
     * @param string $colname the name of the column.
     * @param object $attempt the row of data - see the SQL in display() in
     * mod/quiz/report/randomsummary/report.php to see what fields are present,
     * and what they are called.
     * @return string the contents of the cell.
     */
    public function other_cols($colname, $attempt) {
        // If this is trying to display the student response to a question, pull it out.
        if (preg_match('/^qsresponse(\d+)$/', $colname, $matches)) {
            if (isset($this->lateststeps[$attempt->usageid][$matches[1]])) {
                return $this->lateststeps[$attempt->usageid][$matches[1]]->responsesummary;
            }
            return '';
        }

        // The only other column supported here is the grade, return null if for something else.
        if (!preg_match('/^qsgrade(\d+)$/', $colname, $matches)) {
            return null;
        }

        $questionid = $matches[1];

        $question = $this->questions[$questionid];
        $slot = $question->slot;
        if (!isset($this->lateststeps[$attempt->usageid][$slot])) {
            return '-';
        }
        if ($this->lateststeps[$attempt->usageid][$slot]->questionid <> $questionid) {
            return get_string('notanswered', 'quiz_randomsummary'); // This random question wasn't answer by this user.
        }

        if ($this->is_downloading()) {
            $state = $this->slot_state($attempt, $slot);
            if ($state->is_finished() && $state != question_state::$needsgrading) {
                $fraction = $this->slot_fraction($attempt, $slot);
                $feedbackclass = question_state::graded_state_for_fraction($fraction)->get_feedback_class();

                return get_string($feedbackclass, 'question');
            }
            return '';
        }

        // We don't pass the grade to review link as we are just displaying state.
        return $this->make_review_link('', $attempt, $slot);
    }

    protected function requires_latest_steps_loaded() {
        return $this->options->slotmarks;
    }

    protected function is_latest_step_column($column) {
        if (preg_match('/^qsgrade([0-9]+)/', $column, $matches)) {
            return $matches[1];
        }
        return false;
    }

    protected function get_required_latest_state_fields($slot, $alias) {
        return "$alias.fraction * $alias.maxmark AS qsgrade$slot";
    }

    public function query_db($pagesize, $useinitialsbar = true) {
        parent::query_db($pagesize, $useinitialsbar);

    }

    /**
     * Only show the question status - not grade.
     *
     * @param string $data HTML fragment. The text to make into the link.
     * @param object $attempt data for the row of the table being output.
     * @param int $slot the number used to identify this question within this usage.
     */
    public function make_review_link($data, $attempt, $slot) {
        global $OUTPUT;

        $flag = '';
        if ($this->is_flagged($attempt->usageid, $slot)) {
            $flag = $OUTPUT->pix_icon('i/flagged', get_string('flagged', 'question'),
                'moodle', array('class' => 'questionflag'));
        }

        $feedbackimg = '';
        $state = $this->slot_state($attempt, $slot);
        if ($state->is_finished() && $state != question_state::$needsgrading) {
            $fraction = $this->slot_fraction($attempt, $slot);
            $feedbackimg = $this->icon_for_fraction($fraction);
            $feedbackclass = question_state::graded_state_for_fraction($fraction)->get_feedback_class();
            $data = get_string($feedbackclass, 'question');
        }

        $output = html_writer::tag('span', $feedbackimg . html_writer::tag('span',
                $data, array('class' => $state->get_state_class(true))) . $flag, array('class' => 'que'));

        $reviewparams = array('attempt' => $attempt->attempt, 'slot' => $slot);
        if (isset($attempt->try)) {
            $reviewparams['step'] = $this->step_no_for_try($attempt->usageid, $slot, $attempt->try);
        }
        $url = new moodle_url('/mod/quiz/reviewquestion.php', $reviewparams);
        $output = $OUTPUT->action_link($url, $output,
            new popup_action('click', $url, 'reviewquestion',
                array('height' => 450, 'width' => 650)),
            array('title' => get_string('reviewresponse', 'quiz')));

        return $output;
    }
    /**
     * Load information about the latest state of selected questions in selected attempts.
     * The questions array keys aren't the slot numbers so we need to get just the slots.
     *
     * The results are returned as an two dimensional array $qubaid => $slot => $dataobject
     *
     * @param qubaid_condition|null $qubaids used to restrict which usages are included
     * in the query. See {@link qubaid_condition}.
     * @return array of records. See the SQL in this function to see the fields available.
     */
    protected function load_question_latest_steps(qubaid_condition $qubaids = null) {
        if ($qubaids === null) {
            $qubaids = $this->get_qubaids_condition();
        }
        $dm = new question_engine_data_mapper();
        // Get Slot ids from $this->questions.
        $slots = array();
        foreach ($this->questions as $question) {
            $slots[] = $question->slot;
        }

        // Check to see if we need to pull in any other slots/questions - used to display the student response to certain questions.
        $responsecolumnconfig = get_config('quiz_randomsummary', 'showstudentresponse');
        if (!empty($responsecolumnconfig)) {
            $responsecolumns = explode(',', $responsecolumnconfig);
            foreach ($responsecolumns as $rc) {
                $slots[] = $rc;
            }
        }

        $latesstepdata = $dm->load_questions_usages_latest_steps(
            $qubaids, array_keys($slots));

        $lateststeps = array();
        foreach ($latesstepdata as $step) {
            $lateststeps[$step->questionusageid][$step->slot] = $step;
        }

        return $lateststeps;
    }
}


class quiz_randomsummary_question_engine_data_mapper extends question_engine_data_mapper {
    /**
     * Modified version of load_questions_usages_question_state_summary() to obtain summary of responses to questions.
     *
     * This method may be called publicly.
     *
     * @param qubaid_condition $qubaids used to restrict which usages are included
     * in the query. See {@link qubaid_condition}.
     * @param array $slots A list of slots for the questions you want to konw about.
     * @return array The array keys are slot,qestionid. The values are objects with
     * fields $slot, $questionid, $inprogress, $name, $needsgrading, $autograded,
     * $manuallygraded and $all.
     */
    public function load_questions_usages_question_state_summary(
        qubaid_condition $qubaids, $slots) {
        list($slottest, $params) = $this->db->get_in_or_equal($slots, SQL_PARAMS_NAMED, 'slot');

        $rs = $this->db->get_recordset_sql("
          SELECT qa.slot,
               qa.questionid,
               q.name,
               qas.state,
               COUNT(1) AS numstate

           FROM {$qubaids->from_question_attempts('qa')}
           JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
               AND qas.sequencenumber = {$this->latest_step_for_qa_subquery()}
           JOIN {question} q ON q.id = qa.questionid

          WHERE {$qubaids->where()} AND qa.slot $slottest

          GROUP BY
            qa.slot,
            qa.questionid,
            q.name,
            q.id,
            qas.state

          ORDER BY
           qa.slot,
           qa.questionid,
           q.name,
           q.id
           ", $params + $qubaids->from_where_params());

        $results = array();
        foreach ($rs as $row) {
            $index = $row->slot . ',' . $row->questionid;

            if (!array_key_exists($index, $results)) {
                $res = new stdClass();
                $res->slot = $row->slot;
                $res->questionid = $row->questionid;
                $res->name = $row->name;
                $res->all = 0;
                $results[$index] = $res;
            }
            $results[$index]->{$row->state} = $row->numstate;

            $results[$index]->all += $row->numstate;
        }
        $rs->close();

        return $results;
    }
}