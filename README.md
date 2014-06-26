the enrol_rest plugin for Moodle
=================================

Usage
------

If "Automatic enrolment" and/or "Automatic unenrolment" is checked in the plugin settings, this
script will be automatically run each time the site runs its cron jobs.

The script can also (always) be run manually. In this case, you will be prompted for enrolment and
unenrolment of all users that the script needs to take action on (i.e. enrol or unenrol). Please
note however, that you will be asked about each student individually - if there are 54200 students
to enrol you will be prompted for each student!

The cli arguments to give in order to run the script manually are:
```shell
$ cd <moodleinstance root dir>/admin/cli

$ export MANUALENROLMENT=true

$ php cron.php
```


How it works
-------------

There are basically seven steps taken by the script (a bit abstracted, but this is the gist of it):

 - Get all courses from the Moodle instance
 - For each course in Moodle:
  - Get the students that are currently enrolled
  - Get all students that should be enrolled (from the REST API)
  - Figure out which students aren't yet enrolled in or should be unenrolled from the course
  - Enrol / unenrol students
 - If anything went wrong during enrolment/unenrolment, try to email a list of the errors to the
   email address specified in the plugin settings

The above varies slightly depending on the settings you've entered into the plugin (automatic
unenrollment might be turned off, for example). Also, if "Automatic user creation" is checked in
settings then user accounts will be created before enrolment (if the student doesn't already have
an account).




Settings
---------

The settings for this plugin can be found (once the plugin is installed) under "Site
administration" -> "Plugins" -> "Enrolments" -> "REST enrolment".

These settings consists of:

<h4>Automatic enrolment</h4>
Determines whether users should be automatically enrolled every time cron is run. Please note that
only users who _needs_ to be enrolled into courses actually are. This means that users that are
already enrolled in their respective courses will not be enrolled again (naturally..).

<h4>Automatic unenrolment</h4>
Determines whether users should be automatically unenrolled every time cron is run. __WARNING:__
this is potentially dangerous! If (for example) there's a glitch in the network connection to the
REST API, all users might be unenrolled from courses they're still taking. Use with large amounts
of caution. Remember; "With great power..."

<h4>Automatic user creation</h4>
This tells the script whether to create a new Moodle user if one isn't found for a given user in the
REST API. Recommended to keep checked (even though it defaults to off).

<h4>Error receiver</h4>
If errors are encountered during the enrollment process, they will be sent to this address when the
process is complete. Please note that only one email address can be put in currently.

<h4>Rest API URL, Username, and Password</h4>
These are all quite self-explanatory. This is the URL for the REST APi, and the accompanying
username and password.

<h4>Course information resource</h4>
This setting tells the script where to look on the REST API for course information (such as
participants).

<h4>User information resource</h4>
Same as the above, but for user information. Primarily used when creating Moodle user accounts.

<h4>User realm</h4>
This is a bit more tricky to explain. If this is set in Moodle, the enrolment script will try to
only add users who have this realm in one of their emails. Say for example that there is a student
in the REST API who has two email addresses: "johndoe@dsv.su.se" and "johndoe@su.se". If
no user realm is set, the script will take the first email occurence and be happy with it. If a user
realm is set however (say, SU.SE), the script will look through all of the user's emails and go with
the first occurence of a matching email address. In the above example, the "johndoe@su.se" would be
chosen.
Now, say that the user realm is actually set to "@liu.se", but we still get the same user as above
from the REST API. None of this user's email addresses matches the user realm, and so an error will
be output, the user will not be enrolled, and the script will continue with the next user to enrol.


The MOODLE_25_STABLE branch
----------------------------

This branch is made and tested for Moodle 2.5, but should also work for at least 2.4. Later versions
of Moodle (2.6 onwards) includes something called "Additional name fields"
(http://docs.moodle.org/dev/Additional_name_fields), which made it necessary to split the master
branch in order to keep support for 2.5 and have the latest version possible supported.