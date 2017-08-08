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

    public function allow_unenrol(stdClass $instance) {
        // Users with unenrol cap may unenrol other users manually.
        return true;
    }

    /**
     * Gets an array of the user enrolment actions.
     *
     * @param course_enrolment_manager $manager
     * @param stdClass $ue A user enrolment object
     * @return array An array of user_enrolment_actions
     */
    public function get_user_enrolment_actions(course_enrolment_manager $manager, $ue) {
        $actions = array();
        $context = $manager->get_context();
        $instance = $ue->enrolmentinstance;
        $params = $manager->get_moodlepage()->url->params();
        $params['ue'] = $ue->id;
        if ($this->allow_unenrol_user($instance, $ue) && has_capability("enrol/manual:unenrol", $context)) {
            $url = new moodle_url('/enrol/unenroluser.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/delete', ''), get_string('unenrol', 'enrol'), $url, array('class'=>'unenrollink', 'rel'=>$ue->id));
        }
        if ($this->allow_manage($instance) && has_capability("enrol/manual:manage", $context)) {
            $url = new moodle_url('/enrol/editenrolment.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/edit', ''), get_string('edit'), $url, array('class'=>'editenrollink', 'rel'=>$ue->id));
        }
        return $actions;
    }

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
     * Sends an email with errors (if errors occured during enrolment), if a receiver is specified in the plugin's settings
     *
     * @param array $message containing encountered errors
     */
    private function send_error_email($message) {
        global $CFG;
        $errorreceiver = $this->get_config('errorreceiver');

        // If there's an error-receiver set in the settings
        if ($errorreceiver) {
            $instancename = $CFG->wwwroot;
            $email = get_string('errormailbody', 'enrol_rest', $instancename)."\n";
            $email .= "<pre>";

            foreach ($message as $m) {
            	$email .= $m . "\n";
            }

            $email .= "</pre>";

            $headers = 'Content-type: text/html; charset=iso-8859-1' . "\r\n";

            // Send email
            $sent = mail($errorreceiver, get_string('errormailtitle', 'enrol_rest', $instancename),
                $email, $headers);

            if ($sent) {
                echo get_string('errormailsent', 'enrol_rest')."\n";
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
     * Private helper method for enrol_list_of_users, tries to create missing email address
     *
     * @return string with newly created email
     */
    private function try_fix_for_daisy_email($username) {
        // Extract the pure username
        $exploded = explode("@", $username);

        return $exploded[0] . "@student.su.se";
    }

    /**
     * Take a list of students and enrol to the course. Create accounts if not currently existing.
     *
     * @param array $userlist An array of students to enrol to the course.
     * @param stdClass $course The course object to enrol students to.
     *
     * @return array containing any generated error messages
     */
    private function enrol_list_of_users($userlist, $course, $courseid, $coursestart) {
        $automaticenrolment    = $this->get_config('automaticenrolment');
        $automaticusercreation = $this->get_config('automaticusercreation');
        $courseresource        = $this->get_config('courseresource');
        $userrealm             = $this->get_config('userrealm');
        $userresource          = $this->get_config('userresource');

        // This list keeps track of any errors that might arise during the enrollment
        $errors = array();

        // Loop through users, create accounts if necessary, and finally enrol them in this course
        foreach ($userlist as $user) {
            global $DB;

            // Check if the user already has an account
            $userinmoodle                = $DB->get_record('user', array('idnumber' => $user->person->id));

            // Temporarily store user name in a "fake" user object, so that a proper fullname can be generated later on
            // if we need to create a Moodle user
            $fullname                    = new stdClass;
            $fullname->firstname         = $user->person->firstName;
            $fullname->firstnamephonetic = "";
            $fullname->middlename        = "";
            $fullname->lastname          = $user->person->lastName;
            $fullname->lastnamephonetic  = "";
            $fullname->alternatename     = "";

            // If this student doesn't have a corresponding Moodle user, or if one exists but has been deleted in Moodle,
            // create a new user account or activate
            if (!$userinmoodle || $userinmoodle->deleted == 1) {
                $usernames = $this->curl_request(array($userresource, $user->person->id, 'usernames'));
                $username = NULL; // declare username (will hopefully be filled in properly later)

                // If a userrealm is set in moodle
                if ($userrealm) {
                    foreach ($usernames as $usernamerecord) {
                        if (isset($usernamerecord->realm) && $usernamerecord->realm == $userrealm) {
                            // This is a username for the specified realm (set in settings)! Save it!
                            $username = strtolower($usernamerecord->username.'@'.$usernamerecord->realm);
                            break;
                        }
                    }

                } else {
                    // Create a new username from the user's email
                    $username = $user->person->email;
                }

                if ($username) {
                    if ($automaticusercreation) {
                        $createuser = true;

                    } else {
                        $a           = new stdClass;
                        $a->fullname = fullname($fullname);
                        $a->username = $username;
                        echo get_string('noaccountfound', 'enrol_rest', $a).'\n';
                        $createuser = self::ask_stdin_question(get_string('confirmusercreation', 
                                'enrol_rest', fullname($fullname)));
                    }

                    if ($createuser) {
                        $createuserfailed = false;

                        if (!$userinmoodle) {
                            // Check if the user actually exists, but is without a DaisyID
                            $withoutdaisy = $DB->get_record('user', array('username' => $username));

                            if ($withoutdaisy) {
                                // Try to add DaisyID to the existing Moodle user
                                echo get_string('withoutdaisy', 'enrol_rest', $username)."\n";

                                $params = array(
                                    'context' => context_user::instance($withoutdaisy->id),
                                    'objectid' => $withoutdaisy->id,
                                    'other' => array(
                                        'enrol' => 'rest'
                                    )
                                );

                                try {
                                    $DB->update_record('user', array(
                                        'id' => $withoutdaisy->id,
                                        'idnumber' => $user->person->id));

                                    echo get_string('daisyidadded', 'enrol_rest', $username)."\n";

                                    // Log that daisy id is added.
                                    \enrol_rest\event\daisyid_added::create($params)->trigger();

                                } catch (dml_exception $e) {
                                    // Couldn't update existing Moodle user with a daisy ID
                                    $error = get_string('database_error', 'enrol_rest')
                                        .get_string('daisyidaddfailed', 'enrol_rest', $username)."\n";

                                    echo $error;

                                    // Log that daisy failed to be added.
                                    \enrol_rest\event\daisyid_add_failed::create($params)->trigger();

                                    // Add this to the errors array
                                    $errors[] = $error;

                                    $createuserfailed = true;
                                }

                            // A Moodle user doesn't exist for this student, try to create a new one
                            } else {
                                /* Sometimes, Daisy decides that some users shouldn't have email addresses associated with them
                                   even though Daisy has all the data it needs to add an email address. The creation of a user
                                   within iLearn2 requires an email address to be supplied, so this right here tries to remedy
                                   this issue in order not to fail the enrolment */

                                $emailmissing = false;

                                if ($user->person->email === NULL) {
                                    $emailmissing = true;
                                    // Try to fix!
                                    $user->person->email = $this->try_fix_for_daisy_email($username);

                                    // Add this to the error's array, and then log it
                                    $errors[] = get_string('emailtempfix', 'enrol_rest', $username . ', ID:' . $user->person->id);
                                }

                                // Create the new user
                                try {
                                    $id = $DB->insert_record('user', array(
                                        'auth'       => 'shibboleth',
                                        'confirmed'  => 1,
                                        'mnethostid' => 1,
                                        'username'   => $username,
                                        'idnumber'   => $user->person->id,
                                        'firstname'  => $user->person->firstName,
                                        'lastname'   => $user->person->lastName,
                                        'email'      => $user->person->email
                                    ));

                                    echo get_string('usercreated', 'enrol_rest', $username)."\n";

                                    // Log that user is created.
                                    \core\event\user_created::create_from_userid($id)->trigger();

                                    if ($emailmissing) {
                                        $params = array(
                                            'context' => context_user::instance($id),
                                            'objectid' => $id,
                                            'other' => array(
                                                'enrol' => 'rest'
                                            )
                                        );

                                        // Log that email if created out of username
                                        \enrol_rest\event\email_fixed::create($params)->trigger();
                                    }

                                } catch (dml_exception $e) {
                                    // If an error occurs when creating the user, make sure to log it thoroughly
                                    $error = get_string('database_error', 'enrol_rest')
                                        .get_string('usercreatefailed', 'enrol_rest', $username)."\n"
                                        .get_string('userinfofetched', 'enrol_rest')."\n"
                                        ."ID: ".$user->person->id."\n"
                                        ."Username: ".$username."\n"
                                        ."Firstname: ".$user->person->firstName."\n"
                                        ."Lastname: ".$user->person->lastName."\n"
                                        ."Email: ".$user->person->email."\n";
                                    echo $error;

                                    // Add this to the errors array
                                    $errors[] = $error;

                                    $createuserfailed = true;
                                }
                            }

                        } else if ($userinmoodle->deleted == 1) {
                            // Reactivate disabled user so that we can enrol him/her
                            $userinmoodle->deleted = 0;
                            $DB->update_record('user', $userinmoodle);
                        }

                        $userinmoodle = (!$createuserfailed ? true : false);
                    }

                } else {
                    echo get_string('usernamenotfound', 'enrol_rest', fullname($fullname))."\n";

                    $params = array(
                        'context' => context_system::instance(),
                        'other' => array(
                            'enrol' => 'rest',
                            'daisyid' => $user->person->id
                        )
                    );

                    // Log that username was not found.
                    \enrol_rest\event\username_not_found::create($params)->trigger();
                }

            } else {
                echo get_string('userexists', 'enrol_rest', fullname($fullname))."\n";
            }

            // If this student already has a Moodle user for it, then either automatically enrol or ask whether to enrol
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

        return $errors;
    }

    /**
     * Take a list of students and unenrol from the course.
     *
     * @param array $userlist The array of users.
     * @param obj $course The course object.
     * @param int $coursestart The coursestart datetime.
     * @param boolean $break True if userlist consists of students with breaks, that we need to unenroll.
     * @param stdClass $course The course to unenrol from.
     */
    private function unenrol_list_of_users($userlist, $course, $coursestart, $break = false) {
        global $DB;
        $manualenrolmentenvironment = getenv('MANUALENROLMENT');
        $automaticunenrolment = $this->get_config('automaticunenrolment');

        if (!$manualenrolmentenvironment && ($automaticunenrolment || (time() < $coursestart) || $break)) {
            // Unenroll students automatically
            $userstounenrol = $DB->get_records_list('user', 'idnumber', array_keys($userlist));

            foreach ($userstounenrol as $user) {
                $a = new stdClass;
                $a->user = fullname($user);
                $a->course = $course->fullname;
                $this->process_records('delete', 0, $user, $course, 0, 0);
                echo get_string('userunenroled', 'enrol_rest', $a)."\n";
            }

        } else if ($manualenrolmentenvironment) {
            // Unenroll students manually
            $userstounenrol = $DB->get_records_list('user', 'idnumber', array_keys($userlist));

            foreach ($userstounenrol as $user) {
                $a = new stdClass;
                $a->user = fullname($user);
                $a->course = $course->fullname;

                // Ask user if this student is to be unenrolled
                $unenrolfromcourse = self::ask_stdin_question(get_string('confirmunenrolment', 'enrol_rest', $a));

                if ($unenrolfromcourse) {
                    $this->process_records('delete', 0, $user, $course, 0, 0);
                    echo get_string('userunenroled', 'enrol_rest', $a)."\n";
                }
            }
        }
    }

    /**
     * A method that takes two arrays, one source array and one array that contains keys from the first array.
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
    public function process_courses($coursefilter = 'all') {
        global $CFG, $DB;
        $sapiname                   = php_sapi_name();
        $manualenrolmentenvironment = getenv('MANUALENROLMENT');
        $automaticenrolment         = $this->get_config('automaticenrolment');
        $courseresource             = $this->get_config('courseresource');

        if (!$automaticenrolment && ($sapiname != 'cli' || $manualenrolmentenvironment != 'true')) {
            echo get_string('automaticenrolmentdisabled', 'enrol_rest')."\n";
            return;
        }

        $allcourses = get_courses();
        foreach ($allcourses as $course) {
            if ($course->idnumber) {
                if ($automaticenrolment) {
                    $enroltocourse = true;
                } else if ($manualenrolmentenvironment) {
                    $enroltocourse = self::ask_stdin_question(
                            get_string("confirmenrolmenttocourse", "enrol_rest", $course->fullname));
                } else {
                    continue;
                }

                if ($enroltocourse) {
                    $maxcoursestart = 0;
                    $courseids = preg_split('/,/', $course->idnumber);
                    $studentdict = array();
                    foreach($courseids as $courseid) {
                        $courseid           = trim($courseid);
                        $programid          = '';
                        $coursestart        = 0;

                        if ((strpos($courseid, 'program') !== false) && ($coursefilter == 'program')) {
                            $programid = explode("_", $courseid)[1];
                            $studentlist = $this->get_program_admissions($programid, true);
                        } else if (is_numeric($courseid) && $coursefilter == 'course') {
                            $studentlist = $this->curl_request(array($courseresource, $courseid, 'participants'));
                            $courseinformation = $this->curl_request(array($courseresource, $courseid));
                            if (isset($courseinformation->startDate)) {
                                $coursestart = strtotime($courseinformation->startDate);
                            } else {
                                $coursestart = 0;
                            }
                        } else {
                            continue;
                        }

                        if (empty($studentlist)) {
                            echo get_string('emptystudentlist', 'enrol_rest', $courseid)."\n";
                            continue;

                        } else if ($studentlist === false) {
                            echo get_string('daisydown', 'enrol_rest')."\n";
                            $this->send_error_email(get_string('daisydown', 'enrol_rest')."\n");
                            die();
                        }

                        // Students who have 'break' for a course.
                        $studentbreak = array();
                        foreach ($studentlist as $student) {
                            if (isset($student->break) && $student->break === true) {
                                $studentbreak[$student->person->id] = $student;
                            } else {
                                $studentdict[$student->person->id] = $student;
                            }
                        }

                        if ($coursestart > $maxcoursestart) {
                            $maxcoursestart = $coursestart;
                        }

                        // Select the idnumers of students already enrolled to this course
                        $enrolledusers = $DB->get_records_sql('SELECT u.idnumber FROM {user_enrolments} ue '.
                                                              'JOIN {user} u ON u.id = ue.userid '.
                                                              'JOIN {enrol} e ON ue.enrolid = e.id '.
                                                              'WHERE e.enrol = ? '.
                                                              'AND e.courseid = ?', array('rest', $course->id));

                        // Determine what users to enrol, then try to enrol them
                        $userstoenroll = array_diff(array_keys($studentdict), array_keys($enrolledusers));
                        $errors = $this->enrol_list_of_users(self::pick_elements_from_array(
                            $studentdict, $userstoenroll), $course, $courseid, $coursestart);

                        // Determine users with 'break' attribute enrolled to the courses
                        $userstobreak = array_intersect(array_keys($studentbreak), array_keys($enrolledusers));
                        $this->unenrol_list_of_users(self::pick_elements_from_array(
                            $enrolledusers, $userstobreak), $course, $coursestart, true);

                        // If any errors occured during enrolment, send email!
                        if (!empty($errors)) {
                            $this->send_error_email($errors);
                        }
                    }

                    if ($this->get_config('automaticunenrolment')) {
                        // If autoenrolment is turned on, we grab all students for possible unenrolment.
                        $enrolmentdatebound = 0;
                    } else {
                        // Set an enrolment bound to 1 week earlier than maximum course start date.
                        // This is done so we don't unenrol old students from past course runs.
                        // The idea is that if a course has multiple daisy instances, they might have different course start dates. One week is for safety.
                        $enrolmentdatebound = strtotime('-1 week', $maxcoursestart);
                    }
                    // Select the idnumers of students already enrolled to this course (recently in case of no autounenrolment).
                    $recentlyenrolledusers = $DB->get_records_sql('SELECT u.idnumber FROM {user_enrolments} ue '.
                                                              'JOIN {user} u ON u.id = ue.userid '.
                                                              'JOIN {enrol} e ON ue.enrolid = e.id '.
                                                              'WHERE e.enrol = ? '.
                                                              'AND ue.timestart >= ? '.
                                                              'AND e.courseid = ?', array('rest', $enrolmentdatebound, $course->id));
                    // Determine what users to unenrol, then try to unenrol them
                    $userstounenroll = array_diff(array_keys($recentlyenrolledusers), array_keys($studentdict));
                    $this->unenrol_list_of_users(self::pick_elements_from_array(
                            $recentlyenrolledusers, $userstounenroll), $course, $maxcoursestart);
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
        $context = context_course::instance($course->id);

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

    private function get_program_admissions($programid, $onlyregistered = false, $startingterm = 20132) {
        $sl = $this->curl_request(array('program', $programid, 'admissions?includeDiscontinued=false'));
        if ($onlyregistered) {
            foreach ($sl as $key => $student) {
                $degrees = $this->curl_request(array('student', $student->studentId, 'degrees'));
                foreach ($degrees as $keydegree => $degree) {
                    if (isset($degree->programId) && ($degree->programId == $programid)) {
                        echo "Skipping person $student->studentId because of degree\n\r";
                        unset($sl[$key]);
                    }
                }
                $courseregistrations = $this->curl_request(array('student', $student->studentId, 'courseRegistrations'));
                $registeredtoacourse = false;
                //var_dump($courseregistrations);
                foreach ($courseregistrations as $courseregistration) {
                    if (isset($courseregistration->programId) && $courseregistration->programId == $programid && ($courseregistration->semester>= $startingterm)) {
                        $registeredtoacourse = true;
                    }
                }
                if (!$registeredtoacourse) {
                    echo "Skipping person $student->studentId because of no course registration\n\r";
                    unset($sl[$key]);
                }
                $student->person = $this->curl_request(array('person', $student->studentId));
            }
        }
        return $sl;
    }
}
