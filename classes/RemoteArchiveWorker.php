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
 * This file defines the RemoteArchiveWorker class.
 *
 * @package   quiz_archiver
 * @copyright 2023 Niels Gandraß <niels@gandrass.de>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quiz_archiver;

defined('MOODLE_INTERNAL') || die();

class RemoteArchiveWorker {

    /** @var string URL of the remote Quiz Archive Worker instance */
    protected string $server_url;
    /** @var int Seconds to wait until a connection can be established before aborting */
    protected int $connection_timeout;
    /** @var int Seconds to wait for the request to complete before aborting */
    protected int $request_timeout;
    /** @var \stdClass Moodle config object for this plugin */
    protected \stdClass $config;

    /** @var int Version of the used API */
    public const API_VERSION = 3;

    /**
     * RemoteArchiveWorker constructor
     *
     * @param $server_url string URL of the remote Archive Worker instance
     * @param $connection_timeout int Seconds to wait until a connection can be established before aborting
     * @param $request_timeout int Seconds to wait for the request to complete before aborting
     */
    public function __construct(string $server_url, int $connection_timeout, int $request_timeout) {
        $this->server_url = $server_url;
        $this->connection_timeout = $connection_timeout;
        $this->request_timeout = $request_timeout;
        $this->config = get_config('quiz_archiver');
    }

    /**
     * Tries to enqueue a new archive job at the archive worker service
     *
     * @param $wstoken string Moodle webervice token to use
     * @param $courseid int Moodle course id
     * @param $cmid int Moodle course module id
     * @param $quizid int Moodle quiz id
     * @param $task_archive_quiz_attempts mixed Array containing payload data for
     * the archive quiz attempts task, or null if it should not be executed
     * @param $task_moodle_backups mixed Array containing payload data for
     * the moodle backups task, or null if it should not be executed
     *
     * @throws \UnexpectedValueException if the communication to the archive worker
     * service or decoding of the response failed
     * @throws \RuntimeException if the archive worker service reported an error
     *
     * @return mixed Job information returned from the archive worker on success
     */
    public function enqueue_archive_job(string $wstoken, int $courseid, int $cmid, int $quizid, $task_archive_quiz_attempts, $task_moodle_backups) {
        global $CFG;
        $moodle_url_base = rtrim($this->config->internal_wwwroot ?: $CFG->wwwroot, '/');

        // Prepare and execute request
        $request_payload = json_encode([
            "api_version" => self::API_VERSION,
            "moodle_ws_url" => $moodle_url_base.'/webservice/rest/server.php',
            "moodle_upload_url" => $moodle_url_base.'/webservice/upload.php',
            "wstoken" => $wstoken,
            "courseid" => $courseid,
            "cmid" => $cmid,
            "quizid" => $quizid,
            "task_archive_quiz_attempts" => $task_archive_quiz_attempts,
            "task_moodle_backups" => $task_moodle_backups
        ]);

        $ch = $this->prepare_curl_request($request_payload);
        $result = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($result);

        // Handle errors
        if ($http_status != 200) {
            if ($data === null) {
                throw new \UnexpectedValueException("Decoding of the archive worker response failed. HTTP status code $http_status");
            }
            throw new \RuntimeException($data->error);
        } else {
            if ($data === null) {
                throw new \UnexpectedValueException("Decoding of the archive worker response failed.");
            }
        }

        // Decoded JSON data containing jobid and job_status returned on success
        return $data;
    }

    /**
     * Prepares a JSON POST-request containing given $json_data to $this->server_url.
     *
     * @param string $json_data Encoded JSON-data to post to the server
     *
     * @return resource Preconfigured CURL resource
     */
    private function prepare_curl_request($json_data) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->server_url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_VERBOSE, 0);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connection_timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->request_timeout);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json_data)
        ]);

        return $ch;
    }

}