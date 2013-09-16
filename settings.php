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

defined('MOODLE_INTERNAL') || die();

/**
 * Settings file for the REST enrolment plugin.
 * 
 * @package enrol_rest
 * @copyright 2012 Department of Computer and System Sciences,
 *         Stockholm University {@link http://dsv.su.se}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading('enrol_rest_settings', '', get_string('pluginname_desc', 'enrol_rest')));
    $settings->add(new admin_setting_configcheckbox('enrol_rest/automaticenrolment', get_string('automaticenrolment', 'enrol_rest'), get_string('automaticenrolment_desc', 'enrol_rest'), 1));
    $settings->add(new admin_setting_configcheckbox('enrol_rest/automaticunenrolment', get_string('automaticunenrolment', 'enrol_rest'), '', 0));
    $settings->add(new admin_setting_configcheckbox('enrol_rest/automaticusercreation', get_string('automaticusercreation', 'enrol_rest'), get_string('automaticusercreation_desc', 'enrol_rest'), 0));
    $settings->add(new admin_setting_configtext('enrol_rest/errorreceiver', get_string('errorreceiver', 'enrol_rest'), get_string('errorreceiver_desc', 'enrol_rest'), ''));
    $settings->add(new admin_setting_configtext('enrol_rest/restapiurl', get_string('restapiurl', 'enrol_rest'), '', '')); 
    $settings->add(new admin_setting_configtext('enrol_rest/username', get_string('username'), '', ''));
    $settings->add(new admin_setting_configtext('enrol_rest/password', get_string('password'), '', ''));
    $settings->add(new admin_setting_configtext('enrol_rest/courseresource', get_string('courseresource', 'enrol_rest'), '', ''));
    $settings->add(new admin_setting_configtext('enrol_rest/userresource', get_string('userresource', 'enrol_rest'), '', ''));
    $settings->add(new admin_setting_configtext('enrol_rest/userrealm', get_string('userrealm', 'enrol_rest'), '', ''));
}
