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
    private static function ask_stdin_question($question) {
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
     *
     * @return bool Will always return false.
     */
    public function roles_protected() {
        return false;
    }

    /**
     * Take a list of students and enrol to the course. Create accounts if not currently existing.
     *
     * @param array $userlist An array of students to enrol to the course.
     * @param stdClass $course The course object to enrol students to.
     */
    private function enrol_list_of_users($userlist, $course) {
        $automaticenrolment    = $this->get_config('automaticenrolment');
        $automaticusercreation = $this->get_config('automaticusercreation');
        $courseresource        = $this->get_config('courseresource');
        $userrealm             = $this->get_config('userrealm');
        $userresource          = $this->get_config('userresource');

        $courseinformation = $this->curl_request(array($courseresource, $course->idnumber));

        if (isset($courseinformation->startDate)) {
            $coursestart = strtotime($courseinformation->startDate);
        } else {
            $coursestart = 0;
        }

        foreach ($userlist as $user) {
            global $DB;

            $userinmoodle        = $DB->get_record('user', array('idnumber' => $user->person->id));
            $fullname            = new stdClass;
            $fullname->firstname = $user->person->firstName;
            $fullname->lastname  = $user->person->lastName;

            if (!$userinmoodle || $userinmoodle->deleted == 1) {
                $usernames = $this->curl_request(array($userresource, $user->person->id, 'usernames'));

                if ($userrealm) {
                    foreach ($usernames as $usernamerecord) {
                        if (isset($usernamerecord->realm) && $usernamerecord->realm == $userrealm) {
                            $username = strtolower($usernamerecord->username.'@'.$usernamerecord->realm);
                            break;
                        }
                    }
                } else {
                    $username = $user->person->email;
                }

                if ($username) {
                    if ($automaticusercreation) {
                        $createuser = true;
                    } else {
                        $a           = new stdClass;
                        $a->fullname = fullname($fullname);
                        $a->username = $username;
                        echo get_string("noaccountfound", "enrol_rest", $a)."\n";
                        $createuser = self::ask_stdin_question(get_string('confirmusercreation', 
                                'enrol_rest', fullname($fullname)));
                    }

                    if ($createuser) {
                        if (!$userinmoodle) {
                            $DB->insert_record('user', array(
                                'auth'       => 'shibboleth',
                                'confirmed'  => 1,
                                'mnethostid' => 1,
                                'username'   => $username,
                                'idnumber'   => $user->person->id,
                                'firstname'  => $user->person->firstName,
                                'lastname'   => $user->person->lastName,
                                'email'      => $user->person->email
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
                $moodleuser    = $DB->get_record('user', array('idnumber' => $user->person->id));
                $coursecontext = context_course::instance($course->id);

                if ($automaticenrolment) {
                    $enroluser = true;
                } else {
                    $a              = new stdClass;
                    $a->username    = fullname($moodleuser);
                    $a->coursename  = $course->fullname;
                    $a->coursestart = date("r", $coursestart);
                    $enroluser = self::ask_stdin_question(get_string('confirmenrolment', 'enrol_rest', $a));
                }

                if ($enroluser) {
                    $this->process_records('add', 5, $moodleuser, $course, $coursestart, 0);
                }
            }
        }
    }

    /**
     * Take a list of students and unenrol from the course.
     *
     * @param array $userlist The array of users.
     * @param stdClass $course The course to unenrol from.
     */
    private function unenrol_list_of_users($userlist, $course) {
        global $DB;
        $automaticunenrolment = $this->get_config('automaticunenrolment');
        $userstounenrol       = $DB->get_records_list('user', 'idnumber', array_keys($userlist));

        foreach ($userstounenrol as $user) {
            $a = new stdClass;
            $a->username = fullname($user);
            $a->coursename = $course->fullname;
            if (!$automaticunenrolment) {
                $unenrolfromcourse = self::ask_stdin_question(get_string('confirmunenrolment', 'enrol_rest', $a));
            } else {
                $unenrolfromcourse = true;
            }

            if ($unenrolfromcourse) {
                $this->process_records('delete', 0, $user, $course, 0, 0);
            }
        }
    }

    /**
     * A method that takes an two arrays, one source array and one array that contains keys from the first array.
     * The method will pick the elements specified by the keys in the secound array and return a new array that 
     * only contains those keys.
     *
     * @param array $array The source array
     * @param array $keys An array of keys to select from the source array.
     * @return array The resulting array with the selected keys.
     */
    private static function pick_elements_from_array($array, $keys) {
        $result = array();
        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                $result[$key] = $array[$key];
            }
        }
        return $result;
    }

    /**
     * The cron function that will be run in intervals. 
     * Will iterate through all courses and fetch student lists for all courses that have an id-number.
     * All students will be enrolled to the course (depending on the settings).
     */
    public function cron() {
        global $CFG, $DB;
        $sapiname                   = php_sapi_name();
		$manualenrolmentenvironment = getenv('MANUALENROLMENT');
        $automaticenrolment         = $this->get_config('automaticenrolment');
        $courseresource             = $this->get_config('courseresource');

        if (!$automaticenrolment && ($sapiname != 'cli' || $manualenrolmentenvironment == 'true')) {
            echo get_string('automaticenrolmentdisabled', 'enrol_rest')."\n";
            return;
        }

        $allcourses = get_courses();
        foreach ($allcourses as $course) {
            if ($course->idnumber) {
                if ($automaticenrolment) {
                    $enroltocourse = true;
                } else {
                    $enroltocourse = self::ask_stdin_question(
                            get_string("confirmenrolmenttocourse", "enrol_rest", $course->fullname));
                }

                if ($enroltocourse) {
                    $courseids = preg_split('/,/', $course->idnumber);
                    foreach($courseids as $courseid) {
                        $courseid          = trim($courseid);
                        $studentlist       = $this->curl_request(array($courseresource, $courseid, 'participants'));

                        if (empty($studentlist)) {
                            echo get_string('emptystudentlist', 'enrol_rest', $courseid)."\n";
                            continue;
                        }

                        $studentdict = array();

                        foreach ($studentlist as $student) {
                            $studentdict[$student->person->id] = $student;
                        }

                        $coursecontext = context_course::instance($course->id);
                        $enrolledusers = $DB->get_records_sql('SELECT u.idnumber FROM {user_enrolments} ue '.
                                                              'JOIN {user} u ON u.id = ue.userid '.
                                                              'JOIN {enrol} e ON ue.enrolid = e.id '.
                                                              'WHERE e.enrol = ? '.
                                                              'AND e.courseid = ?', array('rest', $course->id));

                        $userstoenroll = array_diff(array_keys($studentdict), array_keys($enrolledusers));
                        $this->enrol_list_of_users(self::pick_elements_from_array($studentdict, $userstoenroll), $course);

                        $userstounenroll = array_diff(array_keys($enrolledusers), array_keys($studentdict));
                        $this->unenrol_list_of_users(self::pick_elements_from_array($enrolledusers, $userstounenroll), $course);
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
        } else if ($action == 'delete') {
            $instances = $DB->get_records('enrol', array(
                    'enrol'    => 'rest', 
                    'courseid' => $course->id
            ));
            foreach ($instances as $instance) {
                $this->unenrol_user($instance, $user->id);
            }
        }

        return true;
    }
}
