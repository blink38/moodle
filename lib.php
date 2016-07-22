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
 * Lise enrolment plugin.
 *
 * This plugin synchronises enrolment and roles with Lise database table.
 *
 * @package    enrol_lise
 * @copyright  2016 MARC Matthieu (ENSAM - matthieu.marc@ensam.eu)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Lise enrolment plugin implementation.
 * @author  MARC Matthieu  - based on External Database enrolment plugin
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_lise_plugin extends enrol_plugin {
    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);
        if (!has_capability('enrol/lise:config', $context)) {
            return false;
        }
        if (!enrol_is_enabled('database')) {
            return true;
        }
        if (!$this->get_config('dbtype') or !$this->get_config('remoteenroltable') or !$this->get_config('remotecoursefield') or !$this->get_config('remoteuserfield')) {
            return true;
        }

        //TODO: connect to external system and make sure no users are to be enrolled in this course
        return false;
    }

    /**
     * Is it possible to hide/show enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/database:config', $context);
    }

    /**
     * Does this plugin allow manual unenrolment of a specific user?
     * Yes, but only if user suspended...
     *
     * @param stdClass $instance course enrol instance
     * @param stdClass $ue record from user_enrolments table
     *
     * @return bool - true means user with 'enrol/xxx:unenrol' may unenrol this user, false means nobody may touch this user enrolment
     */
    public function allow_unenrol_user(stdClass $instance, stdClass $ue) {
        if ($ue->status == ENROL_USER_SUSPENDED) {
            return true;
        }

        return false;
    }

    protected function get_cache(){
    	
    	return cache::make('enrol_lise','lisecache');
    }
    
	/**
	 * Performs a full sync with lise database
	 * 
	 * First creates all new cohort if necessary, then 
	 * add users in.
	 * If users do not already exists in database, then create them
	 * 
	 * @param progress_trace $trace
	 * @return int 0 means success, 1 db connect failure, 4 db read failure
	 */
    
    public function sync_cohort_from_lise(progress_trace $trace) {
    	

    	error_log("sync_cohort_from_lise");

    	$cache = $this->get_cache();
    	    	
    	$trace->output('Starting cohort synchronisation...');
    	
    	// We may need a lot of memory here.
    	core_php_time_limit::raise();
    	raise_memory_limit(MEMORY_HUGE);
    	
    	if (!$pgdb = $this->db_init($trace)) {
    		$trace->output('Error while communicating with postgresql database');
    		$trace->finished();
    		return 1;
    	}
    	
    	$this->db_parse_table($pgdb, $trace);
    	
    	$trace->output('...cohort synchronisation finished.');
    	$trace->finished();
    	$cache->purge();
    	
    	return 0;
    	
    }
    
    
    protected function db_parse_table($pgconn, progress_trace $trace){
    	
    	if (!pg_ping($pgconn)){
    		die("connection lost !");
    	}
    	
    	// id_interne_aurion		0
    	// Code_Module				1
    	// Libelle_Module			2
    	// id_Apprenant				3
    	// Nom							4
    	// Prenom						5
    	// DN								6
    	// login							7
    	 
    	$result = pg_query($pgconn, 'select id_interne_aurion, "Code_Module", "Libelle_Module","id_Apprenant", "Nom", "Prenom", "DN", login from ensam."AM_SAVOIR_MEMBRES_GROUPES"');
    	
    	if (!$result){
    		$trace->output(pg_last_error($pgconn));
    	}
    	
    	$cache = $this->get_cache();
    	$cache->set("cohort_created",array());
    	
    	while ($row = pg_fetch_row($result)){
    		 
    		$id_aurion = $row[0];
    		$cohort_code = $row[1];
    		$cohort_name = $row[2];
    		
    		$user = new stdClass();
    		$user->id = $row[3];
    		$user->nom = $row[4];
    		$user->prenom = $row[5];
    		$user->dn = $row[6];
    		$user->login = $row[7];
    		
    		// create or update cohort
			$cohort = $this->create_or_update_cohort($cohort_code, $cohort_name, $id_aurion);
			
			// register id of cohort created
			$cohorts = $cache->get("cohort_created");
			if (!in_array($cohort->id, $cohorts)){
				array_push($cohorts,$cohort->id);
				$cache->set("cohort_created",$cohorts);
			}
			unset($cohorts);
			
			$members = $this->cohort_get_members($cohort);
			
			// add user to cohort
			$dbuser = $this->add_user_to_cohort($cohort, $user);
			
			// remove user from cohort members cache (needed to found user to remove from cohort)
			unset($members[$dbuser->id]);
			$cache->set("cohort_members_" . $cohort->id, $members);
			
			
    	}
    	
    	// now we need to remove user from cohort.
    	$this->cohort_clean();
    }
    
    /**
     * Remove users from cohort according Lise data
     */
    protected function cohort_clean(){
    	
    	// just use cache data cohort_members_[cohort id].
    	// [cohort id] are in 'cohort_created' cache
    	
    	$cache = $this->get_cache();
    	
    	$cohorts_id = $cache->get('cohort_created');
    	
    	foreach ($cohorts_id as $id){
    			
    		$members = $cache->get('cohort_members_'.$id);
    		
    		foreach ($members as $key => $member){
    			error_log("remove user " . $key . " from cohort " . $id);
    			cohort_remove_member($id, $key);
    		}
    	}
    }
    
    
    /**
     * Return an array containing $user objet members of $cohort
     * 
     * List are store in cache "cohort_members"
     * @param unknown $cohort
     */
    protected function cohort_get_members($cohort){
    	
    	global $DB;

    	$key = "cohort_members_" . $cohort->id;
    		
    	$cache = $this->get_cache();
    	$liste = $cache->get($key);
    	
    	if ($liste == false){
	    	$record = new stdClass();
	    	$record->cohortid  = $cohort->id;
	
	    	$members = $DB->get_records('cohort_members', array('cohortid' => $cohort->id));
	    	
	    	if ($members == false){
	    		error_log("cohort members not found ".$cohort->id);
	    		return array();
	    	}
	    	
	    	$liste = array();
	    	
	    	foreach($members as $member){
	    		$liste[$member->userid] = $member;
	    	}
    	}
    	
    	$cache->set($key, $liste);
    	
    	return $liste;
    }
    
    
    /**
     * Add a user into a cohort
     * Need to fetch or create user 
     * 
     * @param unknown $cohort
     * @param unknown $login
     */
    protected function add_user_to_cohort($cohort,$user){
    	
    	global $CFG, $DB;
    	require_once("$CFG->dirroot/user/lib.php");
    	
    	if (empty($user->login)){
    		return false;
    	}
    	
    	$cache = $this->get_cache();
    	$dbuser = $cache->get($user->login);
    	if ($dbuser == false){
    		$dbuser = $DB->get_record('user', array('username' => $user->login));
    	}
    	
    	if ($dbuser == false){
    		error_log("dbuser not found");
    		
    		$dbuser = new stdClass();
    		$dbuser->username = $user->login;
    		$dbuser->auth  = 'cas';
    		$dbuser->idnumber = $user->id;
    		$dbuser->firstname =  $user->prenom;
    		$dbuser->lastname = $user->nom;
    		$dbuser->country = 'FR';
    		$dbuser->password = 'not cached';
    		
    		$id = user_create_user($dbuser, false);
    		$dbuser->id = $id;
    		$cache->set($dbuser->username, $dbuser);
    		error_log("user created");
    	}
    	
    	if (!empty($cohort->id) && !empty($dbuser->id)){
    		cohort_add_member($cohort->id, $dbuser->id);
    	} else {
    		error_log("failed to add user to cohort");
    	}
    	
    	return $dbuser;
    	
    }
    
    protected function db_addslashes($text) {
    	// Use custom made function for now - it is better to not rely on adodb or php defaults.
    	if ($this->get_config('dbsybasequoting')) {
    		$text = str_replace('\\', '\\\\', $text);
    		$text = str_replace(array('\'', '"', "\0"), array('\\\'', '\\"', '\\0'), $text);
    	} else {
    		$text = str_replace("'", "''", $text);
    	}
    	return $text;
    }
    
    protected function db_encode($text) {
    	$dbenc = $this->get_config('dbencoding');
    	if (empty($dbenc) or $dbenc == 'utf-8') {
    		return $text;
    	}
    	if (is_array($text)) {
    		foreach($text as $k=>$value) {
    			$text[$k] = $this->db_encode($value);
    		}
    		return $text;
    	} else {
    		return core_text::convert($text, 'utf-8', $dbenc);
    	}
    }
    
    protected function db_decode($text) {
    	$dbenc = $this->get_config('dbencoding');
    	if (empty($dbenc) or $dbenc == 'utf-8') {
    		return $text;
    	}
    	if (is_array($text)) {
    		foreach($text as $k=>$value) {
    			$text[$k] = $this->db_decode($value);
    		}
    		return $text;
    	} else {
    		return core_text::convert($text, $dbenc, 'utf-8');
    	}
    }
    
    
 
    /**
     * Create or update existing cohorte
     * 
     * @param unknown $cohort
     * @param unknown $cohort_name
     * @param unknown $id_aurion
     * @param unknown $login
     * 
     * @return $cohort created or updated, or false
     */
    protected function create_or_update_cohort($cohort_code, $cohort_name, $id_aurion){
    	
    	global $CFG, $DB;
    	require_once("$CFG->dirroot/cohort/lib.php");
    	
    	$cache = $this->get_cache();
    	
    	$existingcohort = $cache->get($id_aurion);
    	if (!$existingcohort){
    		$existingcohort = $DB->get_record('cohort', array('idnumber' => $id_aurion));
    		error_log("get cohort from database");
    	} else {
    		error_log("get cohhort from cache");
    	}
    	
    	if ($existingcohort){
    		error_log("cohort existante");
    		$existingcohort->name = $cohort_code;
    		$existingcohort->description = $cohort_name;
    		$existingcohort->descriptionformat = FORMAT_HTML;
    		$existingcohort->component = "enrol_lise";
    		cohort_update_cohort($existingcohort);	
    		$cache->set($id_aurion, $existingcohort);
    		return $existingcohort;
    	} 
    	
    	$cohort = new stdClass();
    	$cohort->contextid = context_system::instance()->id;
    	$cohort->name = $cohort_code;
    	$cohort->idnumber = $id_aurion;
    	$cohort->description = $this->db_addslashes($cohort_name);
    	$cohort->descriptionformat = FORMAT_HTML;
    	$cohort->component = "enrol_lise";
    	
    	$id = cohort_add_cohort($cohort);
    	
    	if ($id != null){
    		$cache->set($id_aurion, $cohort);
    		error_log("cohort created $id");
    	   	return $cohort;
    	}
    	
    	return false;
    	
    }

    
    
    /**
     * Tries to make connection to the external database.
     *
     * @return null|ADONewConnection
     */
    protected function db_init(progress_trace $trace) {
    	
    	$dbhost = $this->get_config('dbhost');
    	$dbport = $this->get_config('dbport');
    	$dbname= $this->get_config('dbname');
    	$dbuser = $this->get_config('dbuser');
    	$dbpwd = $this->get_config('dbpass');
    	
    	$connect_string = "host=$dbhost port=$dbport dbname=$dbname user=$dbuser password=$dbpwd";
    	
    	error_log("connect to pgsql ".$connect_string);
    	
    	$pgdb = pg_connect($connect_string) or die('connection failed');
    	
    	if (!pg_ping($pgdb)){
    		die("connection lost !");
    	}
    	
   		$trace->output("connected !");
    	
    	error_log("connected ! ");
    	
		return $pgdb;
    }
    
    
    
}
