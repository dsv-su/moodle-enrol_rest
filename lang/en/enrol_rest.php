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
 * Strings for component 'enrol_rest', language 'en'
 *
 * @package enrol_rest
 * @copyright 2012 Department of Computer and System Sciences,
 *         Stockholm University {@link http://dsv.su.se}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['automaticenrolment'] = 'Automatic enrolment';
$string['automaticenrolment_desc'] = 'With automatic enrolment enabled users will be enrolled to courses without confirmation. If disabled, students can only be enrolled through interactive enrolment.';
$string['automaticenrolmentdisabled'] = 'Automatic enrolment disabled for REST enrolment. Skipping.';
$string['automaticunenrolment'] = 'Automatic unenrolment';
$string['automaticusercreation'] = 'Automatic user creation';
$string['automaticusercreation_desc'] = 'With automatic user creation enabled users that does not have an account will get one created for them. If disabled, users without accounts will be skipped.';
$string['confirmenrolmenttocourse'] = 'Do you want to enrol/unenrol students to {$a}';
$string['confirmenrolment'] = 'Do you want to enrol {$a->username} to {$a->coursename} from {$a->coursestart}';
$string['confirmunenrolment'] = 'Do you want to unenrol {$a->username} from {$a->coursename}';
$string['confirmusercreation'] = 'Do you want to create an account for {$a}';
$string['courseresource'] = 'Course information resource';
$string['daisyidadded'] = 'DaisyID added to user {$a}';
$string['daisyidaddfailed'] = 'Failed to add DaisyID to user {$a}';
$string['daisydown'] = 'Daisy connection appears to be down/flaky, aborting automatic enrollment!';
$string['database_error'] = 'Database error: ';
$string['emptystudentlist'] = 'No students found for courseid {$a}. Is the courseid incorrect?';
$string['enrolmentexists'] = 'User {$a} is already enrolled';
$string['errorreceiver'] = 'Error receiver';
$string['errorreceiver_desc'] = 'Email address to which error messages are sent';
$string['noaccountfound'] = 'No account found for {$a->fullname} ({$a->username})';
$string['pluginname'] = 'REST enrolment';
$string['pluginname_desc'] = 'REST enrolment allows users to be fetched from an external source via a RESTful API. Users can either be enrolled automatically using cron or interactivley by invocing cron through cli.';
$string['restapiurl'] = 'Rest API URL';
$string['servererror'] = 'Unexpected server reply. The server replied with http code {$a->httpcode} requesting {$a->path}.';
$string['userenroled'] = 'Enroled user {$a->user} to course {$a->course}';
$string['usercreated'] = 'New user {$a} created/updated';
$string['usercreatefailed'] = 'Failed to create new user {$a}';
$string['userexists'] = 'User {$a} does already have an account.';
$string['userinfofetched'] = 'User info fetched from Daisy: ';
$string['usernamenotfound'] = 'Username for {$a} not found. No user created';
$string['userrealm'] = 'User realm';
$string['userresource'] = 'User information resource';
$string['userunenroled'] = 'Unenroled user {$a->user} from course {$a->course}';
$string['withoutdaisy'] = '{$a} does already have an account, but it\'s missing a DaisyID. Trying to add...';
