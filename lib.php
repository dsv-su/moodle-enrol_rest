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
 * REST enrolment plugin main library file.
 *
 * @package enrol_rest
 * @copyright 2012 Department of Computer and System Sciences,
 *         Stockholm University {@link http://dsv.su.se}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_rest_plugin extends enrol_plugin {

    /**
     * Perform a request to the REST API using the curl-library.
     * Will fetch the URL to the API along with username and password from the plugin settings.
     * 
     * @param array $parameters The requested resources/endpoints as an array that will be imploded.
     * @return string|false If successful, returns the decoded response. Else returns false.
     */
    private function curl_request($parameters) {
        $apiurl   = $this->get_config('restapiurl');
        $username = $this->get_config('username');
        $password = $this->get_config('password');
        $ch       = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERPWD, $username.':'.$password);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
        curl_setopt($ch, CURLOPT_URL, $apiurl.implode('/', $parameters));
        $contents = curl_exec($ch);
        $headers  = curl_getinfo($ch);
        curl_close($ch);

        if ($headers['http_code'] == 200) {
            return json_decode($contents);
        } else {
            $a = new stdClass;
            $a->httpcode = $headers['http_code'];
            $a->path     = implode('/', $parameters); 
            echo get_string('servererror', 'enrol_rest', $a)."\n";
            return false;
        }        
    }

    /**
     * Print a question to the user through stdout and read the answer from stdin.
     * 
     * @param string $question The question that the user needs to answer.
     * @return bool True if the user answered y, false if the user answered f.
     */
    private function ask_stdin_question($question) {
        while (true) {
            echo "\n".$question." [y/n] ";
            $answer = strtolower(trim(fgets(STDIN)));
            if ($answer == 'y' || $answer == 'n') {
                return $answer == 'y';
            }
        }
    }

    /**
     * Allow teachers and managers to fiddle with the roles.
     */
    public function roles_protected() {
        return false;
    }

    /**
     * The cron function that will be run in intervals. 
     * Will iterate through all courses and fetch student lists for all courses that have an id-number.
     * All students will be enrolled to the course (depending on the settings).
     */
    public function cron() {
        global $CFG, $DB;
        $sapiname              = php_sapi_name();
        $automaticenrolment    = $this->get_config('automaticenrolment');
        $automaticusercreation = $this->get_config('automaticusercreation');
        $courseresource        = $this->get_config('courseresource');
        $userresource          = $this->get_config('userresource');
        $userrealm             = $this->get_config('userrealm');

        if (!$automaticenrolment && $sapiname != 'cli') {
            echo get_string('automaticenrolmentdisabled', 'enrol_rest')."\n";
            return;
        }

        $allcourses = get_courses();
        foreach ($allcourses as $course) {
            if ($course->idnumber) {
                if ($automaticenrolment) {
                    $enroltocourse = true;
                } else {
                    $enroltocourse = $this->ask_stdin_question(
                            get_string("confirmenrolmenttocourse", "enrol_rest", $course->fullname));
                }
                
                if ($enroltocourse) {
                    $courseids = preg_split('/,/', $course->idnumber);
                    foreach($courseids as $courseid) {
                        $courseid          = trim($courseid);
                        $studentlist       = $this->curl_request(array($courseresource, $courseid, 'participants'));
                        $courseinformation = $this->curl_request(array($courseresource, $courseid));

                        if (isset($courseinformation->startDate)) {
                            $coursestart = strtotime($courseinformation->startDate);
                        } else {
                            $coursestart = 0;
                        }

                        if (empty($studentlist)) {
                            echo get_string('emptystudentlist', 'enrol_rest', $courseid)."\n";
                            continue;
                        }

                        foreach ($studentlist as $student) {
                            $userinmoodle        = $DB->get_record('user', array('idnumber' => $student->person->id));
                            $fullname            = new stdClass;
                            $fullname->firstname = $student->person->firstName;
                            $fullname->lastname  = $student->person->lastName;

                            if (!$userinmoodle || $userinmoodle->deleted == 1) {
                                $usernames = $this->curl_request(array($userresource, $student->person->id, 'usernames'));

                                if ($userrealm) {
                                    foreach ($usernames as $usernamerecord) {
                                        if (isset($usernamerecord->realm) && $usernamerecord->realm == $userrealm) {
                                            $username = strtolower($usernamerecord->username.'@'.$usernamerecord->realm);
                                            break;
                                        }
                                    }
                                } else {
                                    $username = $student->person->email;
                                }

                                if ($username) {
                                    if ($automaticusercreation) {
                                        $createuser = true;
                                    } else {
                                        $a           = new stdClass;
                                        $a->fullname = fullname($fullname);
                                        $a->username = $username;
                                        echo get_string("noaccountfound", "enrol_rest", $a)."\n";
                                        $createuser = $this->ask_stdin_question(get_string('confirmusercreation', 
                                                'enrol_rest', fullname($fullname)));
                                    }

                                    if ($createuser) {
                                        if (!$userinmoodle) {
                                            $DB->insert_record('user', array(
                                                'auth'       => 'shibboleth',
                                                'confirmed'  => 1,
                                                'mnethostid' => 1,
                                                'username'   => $username,
                                                'idnumber'   => $student->person->id,
                                                'firstname'  => $student->person->firstName,
                                                'lastname'   => $student->person->lastName,
                                                'email'      => $student->person->email
                                            ));
                                        } else if ($userinmoodle->deleted == 1) {
                                            $userinmoodle->deleted = 0;
                                            $DB->update_record('user', $userinmoodle);
                                        }
                                        
                                        $userinmoodle = true;
                                        echo get_string('usercreated', 'enrol_rest', $username)."\n";
                                    }
                                } else {
                                    echo get_string('usernamenotfound', 'enrol_rest', fullname($fullname))."\n";
                                }
                            
                            } else {
                                echo get_string('userexists', 'enrol_rest', fullname($fullname))."\n";
                            }

                            if ($userinmoodle) {
                                $moodleuser    = $DB->get_record('user', array('idnumber' => $student->person->id));
                                $coursecontext = context_course::instance($course->id);
                                if (!is_enrolled($coursecontext, $moodleuser)) {
                                    if ($automaticenrolment) {
                                        $enroluser = true;
                                    } else {
                                        $a              = new stdClass;
                                        $a->username    = fullname($moodleuser);
                                        $a->coursename  = $course->fullname;
                                        $a->coursestart = date("r", $coursestart);
                                        $enroluser = $this->ask_stdin_question(get_string('confirmenrolment', 'enrol_rest', $a));
                                    }

                                    if ($enroluser) {
                                        $this->process_records('add', 5, $moodleuser, $course, $coursestart, 0);
                                    }
                                } else {
                                     echo get_string('enrolmentexists', 'enrol_rest', fullname($moodleuser))."\n";
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * Perform the actual enrolment of the student. 
     * This method is a stripped down version of the method with the same name from enrol_flatfile.
     * 
     * @param string $action The only supported action is to add students to courses.
     * @param int $roleid The role that the enroled user will be given.
     * @param stdClass $user The user object for the user to enrol.
     * @param stdClass $course The course object to enrol students to
     * @param int $timestart The deadline when enrolment starts.
     * @param int $timeend The deadline when enrolment ends.
     */
    private function process_records($action, $roleid, $user, $course, $timestart, $timeend) {
        global $CFG, $DB;
        unset($elog);

        // Create/resurrect a context object
        $context = get_context_instance(CONTEXT_COURSE, $course->id);

        if ($action == 'add') {
            $instance = $DB->get_record('enrol', array(
                'courseid' => $course->id,
                'enrol'    => 'rest'
            ));

            if (empty($instance)) {
                // Only add an enrol instance to the course if non-existent
                $enrolid  = $this->add_instance($course);
                $instance = $DB->get_record('enrol', array('id' => $enrolid));
            }
            // Enrol the user with this plugin instance
            $this->enrol_user($instance, $user->id, $roleid, $timestart, $timeend);
        }
        if (empty($elog)) {
            $elog = "OK\n";
        }

        return true;
    }
}
