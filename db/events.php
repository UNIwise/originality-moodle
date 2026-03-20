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
 * Event observers for plagiarism_originality.
 *
 * @package    plagiarism_originality
 * @copyright  2026 onwards
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\assessable_uploaded',
        'callback'  => '\plagiarism_originality\observer::assessable_uploaded',
    ],
    [
        'eventname' => '\core\event\assessable_submitted',
        'callback'  => '\plagiarism_originality\observer::assessable_submitted',
    ],
    [
        'eventname' => '\mod_assign\event\submission_removed',
        'callback'  => '\plagiarism_originality\observer::submission_removed',
    ],
    [
        'eventname' => '\mod_assign\event\submission_graded',
        'callback'  => '\plagiarism_originality\observer::submission_graded',
    ],
];
