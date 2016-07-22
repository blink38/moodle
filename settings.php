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
 * Database enrolment plugin settings and presets.
 *
 * @package    enrol_lise
 * @copyright  2010 Petr Skoda {@link http://skodak.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    //--- general settings -----------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_lise_settings', '', get_string('pluginname_desc', 'enrol_lise')));

    $settings->add(new admin_setting_heading('enrol_lise_exdbheader', get_string('settingsheaderdb', 'enrol_lise'), ''));

    $options = array('', "postgres");
    $options = array_combine($options, $options);
    $settings->add(new admin_setting_configselect('enrol_lise/dbtype', get_string('dbtype', 'enrol_lise'), get_string('dbtype_desc', 'enrol_lise'), '', $options));

//     $settings->add(new admin_setting_configtext('enrol_lise/dburl', get_string('dburl', 'enrol_lise'), get_string('dburl_desc', 'enrol_lise'), 'jdbc://postgresql://'));

    
    $settings->add(new admin_setting_configtext('enrol_lise/dbhost', get_string('dbhost', 'enrol_lise'), get_string('dbhost_desc', 'enrol_lise'), ''));
    $settings->add(new admin_setting_configtext('enrol_lise/dbport', get_string('dbport', 'enrol_lise'), get_string('dbport_desc', 'enrol_lise'), '5432'));
    $settings->add(new admin_setting_configtext('enrol_lise/dbname', get_string('dbname', 'enrol_lise'), get_string('dbname_desc', 'enrol_lise'), ''));
    
    $settings->add(new admin_setting_configtext('enrol_lise/dbuser', get_string('dbuser', 'enrol_lise'), '', ''));
    $settings->add(new admin_setting_configpasswordunmask('enrol_lise/dbpass', get_string('dbpass', 'enrol_lise'), '', ''));

}
