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
 * Implementaton of the quizaccess_seb plugin.
 *
 * @package    quizaccess_seb
 * @author     Andrew Madden <andrewmadden@catalyst-au.net>
 * @author     Dmitrii Metelkin <dmitriim@catalyst-au.net>
 * @copyright  2019 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use quizaccess_seb\access_manager;
use quizaccess_seb\quiz_settings;
use quizaccess_seb\settings_provider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/accessrule/accessrulebase.php');

class quizaccess_seb extends quiz_access_rule_base {

    /** @var access_manager $accessmanager Instance to manage the access to the quiz for this plugin. */
    private $accessmanager;

    /**
     * Create an instance of this rule for a particular quiz.
     *
     * @param quiz $quizobj information about the quiz in question.
     * @param int $timenow the time that should be considered as 'now'.
     * @param access_manager $accessmanager the quiz accessmanager.
     */
    public function __construct(quiz $quizobj, int $timenow, access_manager $accessmanager) {
        parent::__construct($quizobj, $timenow);
        $this->accessmanager = $accessmanager;
    }

    /**
     * Return an appropriately configured instance of this rule, if it is applicable
     * to the given quiz, otherwise return null.
     *
     * @param quiz $quizobj information about the quiz in question.
     * @param int $timenow the time that should be considered as 'now'.
     * @param bool $canignoretimelimits whether the current user is exempt from
     *      time limits by the mod/quiz:ignoretimelimits capability.
     * @return quiz_access_rule_base|null the rule, if applicable, else null.
     */
    public static function make (quiz $quizobj, $timenow, $canignoretimelimits) {
        $accessmanager = new access_manager($quizobj);
        // If Safe Exam Browser is not required, this access rule is not applicable.
        if (!$accessmanager->seb_required()) {
            return null;
        }

        return new self($quizobj, $timenow, $accessmanager);
    }

    /**
     * Add any fields that this rule requires to the quiz settings form. This
     * method is called from {@link mod_quiz_mod_form::definition()}, while the
     * security section is being built.
     *
     * @param mod_quiz_mod_form $quizform the quiz settings form that is being built.
     * @param MoodleQuickForm $mform the wrapped MoodleQuickForm.
     */
    public static function add_settings_form_fields(mod_quiz_mod_form $quizform, MoodleQuickForm $mform) {
        global $DB;
        $defaults = settings_provider::get_quiz_defaults();
        $types = settings_provider::get_quiz_element_types();
        $hideifs = settings_provider::get_quiz_hideifs();

        // The course context for new modules, or the module context for existing modules.
        $context = $quizform->get_context();

        // Insert all the form elements before the 'security' section as a group.
        foreach (settings_provider::get_quiz_elements($context) as $name => $type) {

            // Check if the user has capability to edit setting, otherwise use hidden setting type.
            if ($type != 'header' && !has_capability('quizaccess/seb:manage_' . $name, $context)) {
                $type = 'hidden'; // A disabled element may be more appropriate but does not currently exist.
            }

            // Create element.
            if (is_array($type) && $type[0] == 'filemanager') {
                $element = $mform->createElement($type[0], $name, get_string($name, 'quizaccess_seb'), null, $type[1]);
            } else if (is_array($type)) {
                $element = $mform->createElement($type[0], $name, get_string($name, 'quizaccess_seb'), $type[1]);
            } else {
                $element = $mform->createElement($type, $name, get_string($name, 'quizaccess_seb'));
            }

            // Insert element.
            $mform->insertElementBefore($element, 'security');
            unset($element); // We need to make sure each &element only references the current element in loop.

            $mform->addHelpButton($name, $name, 'quizaccess_seb');

            // Set defaults.
            if (isset($defaults[$name])) {
                $mform->setDefault($name, $defaults[$name]);
            }

            // Set types.
            if (isset($types[$name])) {
                $mform->setType($name, $types[$name]);
            }

            // Second pass to populate the filemanager with any existing saved self config file.
            if (is_array($type) && $type[0] == 'filemanager') {
                $itemid = null;
                if ($quizform->get_coursemodule()) {
                    $itemid = $quizform->get_coursemodule()->id;
                }
                $draftitemid = 0;
                file_prepare_draft_area(
                    $draftitemid,
                    $context->id,
                    'quizaccess_seb',
                    $name,
                    $itemid
                );
                $mform->setDefault($name, $draftitemid);
                $mform->addHelpButton($name, $name, 'quizaccess_seb');
            }

            // Set hideifs.
            if (isset($hideifs[$name])) {
                foreach ($hideifs[$name] as $hideif) {
                    $mform->hideIf(
                        $hideif->get_element(),
                        $hideif->get_dependantname(),
                        $hideif->get_condition(),
                        $hideif->get_dependantvalue()
                    );
                }
            }
        }

        // If there have been any quiz attempts, freeze SEB settings, and explain why in section tooltip.
        if ($quizform->get_instance()) {
            $attempts = $DB->get_records('quiz_attempts', ['quiz' => $quizform->get_instance()]);
            if (!empty($attempts)) {
                $mform->hardFreeze(array_keys(settings_provider::get_quiz_elements()));
                $mform->addHelpButton('seb', 'disabledsettings', 'quizaccess_seb');
            }
        }
    }

    /**
     * Validate the data from any form fields added using {@link add_settings_form_fields()}.
     *
     * @param array $errors the errors found so far.
     * @param array $data the submitted form data.
     * @param array $files information about any uploaded files.
     * @param mod_quiz_mod_form $quizform the quiz form object.
     * @return array $errors the updated $errors array.
     */
    public static function validate_settings_form_fields(array $errors,
                                                         array $data, $files, mod_quiz_mod_form $quizform) : array {
        global $DB;
        $settings = self::filter_plugin_settings((object) $data);

        // Validate basic settings using persistent class.
        $quizsettings = (new quiz_settings())->from_record($settings);
        // Set non-form fields.
        $quizsettings->set('quizid', $data['instance']);
        $quizsettings->set('cmid', $data['coursemodule']);
        $quizsettings->validate();

        // Add any errors to list.
        foreach ($quizsettings->get_errors() as $name => $error) {
            $name = self::add_prefix($name); // Re-add prefix to match form element.
            $errors[$name] = $error->out();
        }

        // If there have been any quiz attempts, prevent settings being saved.
        if ($quizform->get_instance()) {
            $attempts = $DB->get_records('quiz_attempts', ['quiz' => $quizform->get_instance()]);
            if (!empty($attempts)) {
                $errors['seb'] = get_string('settingsfrozen', 'quizaccess_seb');
            }
        }

        // Edge case for filemanager_sebconfig.
        $requiresetting = $quizsettings->get('requiresafeexambrowser');
        if ($requiresetting == settings_provider::USE_SEB_UPLOAD_CONFIG) {
            $itemid = $data['filemanager_sebconfigfile'];
            $drafterror = settings_provider::validate_draftarea_configfile($itemid);
            if (!empty($drafterror)) {
                $errors['filemanager_sebconfigfile'] = $drafterror;
            }
        }

        // Edge case to force user to select a template.
        if ($requiresetting == settings_provider::USE_SEB_TEMPLATE) {
            $templateid = $data['seb_templateid'];
            if (empty($templateid)) {
                $errors['seb_templateid'] = get_string('invalidtemplate', 'quizaccess_seb');
            }
        }

        // Global settings may be active which require a quiz password to be set.
        if (!empty(get_config('quizaccess_seb', 'quizpasswordrequired')) && empty($data['quizpassword'])) {
            $errors['quizpassword'] = get_string('passwordnotset', 'quizaccess_seb');
        }

        return $errors;
    }

    /**
     * Save any submitted settings when the quiz settings form is submitted. This
     * is called from {@link quiz_after_add_or_update()} in lib.php.
     *
     * @param object $quiz the data from the quiz form, including $quiz->id
     *      which is the id of the quiz being saved.
     */
    public static function save_settings($quiz) {

        $settings = self::filter_plugin_settings($quiz);

        // Associate settings with quiz.
        $settings->quizid = $quiz->id;

        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course);
        $settings->cmid = $cm->id;

        // Get existing settings or create new settings if none exist.
        $quizsettings = quiz_settings::get_record(['quizid' => $quiz->id]);
        if (!$quizsettings) {
            $quizsettings = new quiz_settings(0, $settings);
        } else {
            $settings->id = $quizsettings->get('id');
            $quizsettings->from_record($settings);
        }

        // If specified to not use a template, reset this back to default.
        if ($quizsettings->get('requiresafeexambrowser') != settings_provider::USE_SEB_TEMPLATE) {
            $quizsettings->set('templateid', 0);
        }

        // Ensure that a cm exists before deleting any files.
        if ($cm && $quizsettings->get('requiresafeexambrowser') == settings_provider::USE_SEB_UPLOAD_CONFIG) {
            $draftitemid = file_get_submitted_draft_itemid('filemanager_sebconfigfile');
            settings_provider::save_filemanager_sebconfigfile_draftarea($draftitemid, $cm->id);
        } else if ($cm) {
            settings_provider::delete_uploaded_config_file($cm->id);
        }

        if ($quizsettings->get('id') && $quizsettings->get('requiresafeexambrowser') == settings_provider::USE_SEB_NO) {
            $quizsettings->delete();
        } else {
            // Validate and save settings. Settings should already be validated by validate_settings_form_fields but
            // the validation method also adds in default fields which is useful here.
            $quizsettings->validate();
            $quizsettings->save();
        }
    }

    /**
     * Delete any rule-specific settings when the quiz is deleted. This is called
     * from {@link quiz_delete_instance()} in lib.php.
     *
     * @param object $quiz the data from the database, including $quiz->id
     *      which is the id of the quiz being deleted.
     */
    public static function delete_settings($quiz) {
        $quizsettings = quiz_settings::get_record(['quizid' => $quiz->id]);
        // Check that there are existing settings.
        if ($quizsettings !== false) {
            $quizsettings->delete();
        }
    }

    /**
     * Return the bits of SQL needed to load all the settings from all the access
     * plugins in one DB query. The easiest way to understand what you need to do
     * here is probalby to read the code of {@link quiz_access_manager::load_settings()}.
     *
     * If you have some settings that cannot be loaded in this way, then you can
     * use the {@link get_extra_settings()} method instead, but that has
     * performance implications.
     *
     * @param int $quizid the id of the quiz we are loading settings for. This
     *     can also be accessed as quiz.id in the SQL. (quiz is a table alisas for {quiz}.)
     * @return array with three elements:
     *     1. fields: any fields to add to the select list. These should be alised
     *        if neccessary so that the field name starts the name of the plugin.
     *     2. joins: any joins (should probably be LEFT JOINS) with other tables that
     *        are needed.
     *     3. params: array of placeholder values that are needed by the SQL. You must
     *        used named placeholders, and the placeholder names should start with the
     *        plugin name, to avoid collisions.
     */
    public static function get_settings_sql($quizid) : array {
        return [
                'seb.requiresafeexambrowser AS seb_requiresafeexambrowser, '
                . 'seb.sebconfigfile AS seb_sebconfigfile, '
                . 'seb.showsebtaskbar AS seb_showsebtaskbar, '
                . 'seb.showwificontrol AS seb_showwificontrol, '
                . 'seb.showreloadbutton AS seb_showreloadbutton, '
                . 'seb.showtime AS seb_showtime, '
                . 'seb.showkeyboardlayout AS seb_showkeyboardlayout, '
                . 'seb.allowuserquitseb AS seb_allowuserquitseb, '
                . 'seb.quitpassword AS seb_quitpassword, '
                . 'seb.linkquitseb AS seb_linkquitseb, '
                . 'seb.userconfirmquit AS seb_userconfirmquit, '
                . 'seb.enableaudiocontrol AS seb_enableaudiocontrol, '
                . 'seb.muteonstartup AS seb_muteonstartup, '
                . 'seb.allowspellchecking AS seb_allowspellchecking, '
                . 'seb.allowreloadinexam AS seb_allowreloadinexam, '
                . 'seb.activateurlfiltering AS seb_activateurlfiltering, '
                . 'seb.filterembeddedcontent AS seb_filterembeddedcontent, '
                . 'seb.expressionsallowed AS seb_expressionsallowed, '
                . 'seb.regexallowed AS seb_regexallowed, '
                . 'seb.expressionsblocked AS seb_expressionsblocked, '
                . 'seb.regexblocked AS seb_regexblocked, '
                . 'seb.allowedbrowserexamkeys AS seb_allowedbrowserexamkeys, '
                . 'seb.suppresssebdownloadlink AS seb_suppresssebdownloadlink, '
                . 'sebtemplate.id AS seb_templateid '
                , 'LEFT JOIN {quizaccess_seb_quizsettings} seb ON seb.quizid = quiz.id '
                . 'LEFT JOIN {quizaccess_seb_template} sebtemplate ON seb.templateid = sebtemplate.id '
                , []
        ];
    }

    /**
     * Whether the user should be blocked from starting a new attempt or continuing
     * an attempt now.
     *
     * @return string false if access should be allowed, a message explaining the
     *      reason if access should be prevented.
     */
    public function prevent_access() {
        $quizsettings = $this->accessmanager->get_quiz_settings();

        // If Safe Exam Browser is not required or user can bypass check, access to quiz should not be prevented.
        if (!$this->accessmanager->seb_required() || $this->accessmanager->can_bypass_seb()) {
            return false;
        }

        // If using client configuration with no browser exam keys, do basic check that user is using Safe Exam Browser.
        // It is more secure to use Browser Exam Keys than to rely on this check.
        if ($quizsettings->get('requiresafeexambrowser') == settings_provider::USE_SEB_CLIENT_CONFIG
                && empty($quizsettings->get('allowedbrowserexamkeys'))
                && !$this->accessmanager->validate_basic_header()) {
            // Return error message with download link.
            $errormessage = get_string('clientrequiresseb', 'quizaccess_seb')
                    . $this->get_download_button_only();
            // TODO: Issue #8 - Trigger event if access is prevented.
            return $errormessage;
        }

        // Check if the quiz can be validated with the quiz Config Key or Browser Exam Keys.
        if ($this->accessmanager->validate_access_keys()) {
            return false;
        } else {
            // Return error message with download link and links to get the seb config.
            $errormessage = get_string('invalidkeys', 'quizaccess_seb')
                    . $this->get_action_buttons();
            // Display action buttons to assist user in gaining access to quiz.
            // TODO: Issue #8 - Trigger event if access is prevented.
            return $errormessage;
        }
    }

    /**
     * Hepler function to display an Exit Safe Exam Browser button if configured to do so and attempts are > 0.
     *
     * @return string empty or a button which has the configured seb quit link.
     */
    private function display_quit_button() : string {
        global $OUTPUT, $USER;

        $quizsettings = $this->accessmanager->get_quiz_settings();
        $attempts = quiz_get_user_attempts($quizsettings->get('quizid'), $USER->id, 'finished', false);
        $quitbutton = '';

        if (empty($attempts)) {
            return $quitbutton;
        }

        // Only display if the link has been configured and attempts are greater than 0.
        if ($quizsettings->get('linkquitseb')) {
            $url = new moodle_url($quizsettings->get('linkquitseb'));
            $quitbutton = $OUTPUT->single_button($url, get_string('exitsebbutton', 'quizaccess_seb'), 'get');
        }

        return $quitbutton;
    }

    /**
     * Information, such as might be shown on the quiz view page, relating to this restriction.
     * There is no obligation to return anything. If it is not appropriate to tell students
     * about this rule, then just return ''.
     *
     * @return mixed a message, or array of messages, explaining the restriction
     *         (may be '' if no message is appropriate).
     */
    public function description() {
        $messages = [get_string('sebrequired', 'quizaccess_seb')];

        // Those with higher level access will be able to see the button if they've made an attempt.
        if (!$this->prevent_access()) {
            $messages[] = $this->display_quit_button();
        }

        return $messages;
    }

    /**
     * Sets up the attempt (review or summary) page with any special extra
     * properties required by this rule.
     *
     * @param moodle_page $page the page object to initialise.
     */
    public function setup_attempt_page($page) {
        $page->set_title($this->quizobj->get_course()->shortname . ': ' . $page->title);
        $page->set_cacheable(false);
        $page->set_popup_notification_allowed(false); // Prevent message notifications.
        $page->set_heading($page->title);
        $page->set_pagelayout('secure');
    }

    /**
     * Get buttons to prompt user to download SEB or config file.
     *
     * @return string Button html as a block.
     */
    private function get_action_buttons() {
        global $OUTPUT;

        $buttons = html_writer::start_div();

        // If suppresssebdownloadlink setting is enabled, do not show download button.
        if (empty($this->quiz->seb_suppresssebdownloadlink)) {
            if (!empty($this->get_seb_download_url())) {
                $buttons .= $OUTPUT->single_button($this->get_seb_download_url(), get_string('sebdownloadbutton', 'quizaccess_seb'));
            }
        }

        // Get config for displaying links.
        $linkconfig = explode(',', get_config('quizaccess_seb', 'showseblinks'));

        if (in_array('seb', $linkconfig)) {
            $seblink = \quizaccess_seb\link_generator::get_link($this->quiz->cmid, true, is_https());
            $buttons .= $OUTPUT->single_button($seblink, get_string('seblinkbutton', 'quizaccess_seb'), 'get');
        }

        if (in_array('http', $linkconfig)) {
            $httplink = \quizaccess_seb\link_generator::get_link($this->quiz->cmid, false, is_https());
            $buttons .= $OUTPUT->single_button($httplink, get_string('httplinkbutton', 'quizaccess_seb'), 'get');
        }

        $buttons .= html_writer::end_div();

        return $buttons;
    }

    /**
     * Get button that links to Safe Exam Browser download.
     * This will return an empty string if quizaccess_seb/sebdownloadbutton is not set.
     *
     * @return string HTML for button.
     */
    private function get_download_button_only() {
        global $OUTPUT;
        $buttons = '';

        if (empty($this->get_seb_download_url())) {
            return $buttons;
        }

        $buttons .= html_writer::start_div();
        // If suppresssebdownloadlink setting is enabled, do not show download button.
        if (empty($this->quiz->seb_suppresssebdownloadlink)) {
            $buttons .= $OUTPUT->single_button($this->get_seb_download_url(), get_string('sebdownloadbutton', 'quizaccess_seb'));
        }
        $buttons .= html_writer::end_div();

        return $buttons;
    }

    /**
     * Returns SEB download URL.
     *
     * @return string
     */
    private function get_seb_download_url() {
        return get_config('quizaccess_seb', 'downloadlink');
    }

    /**
     * Strip the seb_ prefix from each setting key.
     *
     * @param \stdClass $settings Object containing settings.
     * @return \stdClass The modified settings object.
     */
    private static function strip_all_prefixes(\stdClass $settings) : \stdClass {
        $newsettings = new \stdClass();
        foreach ($settings as $name => $setting) {
            $newname = preg_replace("/^seb_/", "", $name);
            $newsettings->$newname = $setting; // Add new key.
        }
        return $newsettings;
    }

    /**
     * Add prefix to string.
     *
     * @param string $name String to add prefix to.
     * @return string String with prefix.
     */
    private static function add_prefix(string $name) : string {
        if (strpos($name, 'seb_') !== 0) {
            $name = 'seb_' . $name;
        }
        return $name;
    }

    /**
     * Filter a standard class by prefix.
     *
     * @param stdClass $settings Quiz settings object.
     * @return stdClass Filtered object.
     */
    private static function filter_by_prefix(\stdClass $settings) : \stdClass {
        $newsettings = new \stdClass();
        foreach ($settings as $name => $setting) {
            // Only add it, if not there.
            if (strpos($name, "seb_") === 0) {
                $newsettings->$name = $setting; // Add new key.
            }
        }
        return $newsettings;
    }

    /**
     * Filter quiz settings for this plugin only.
     *
     * @param stdClass $settings Quiz settings.
     * @return stdClass Filtered settings.
     */
    private static function filter_plugin_settings(stdClass $settings) {
        $settings = self::filter_by_prefix($settings);
        return self::strip_all_prefixes($settings);
    }
}
