<?php

/**
 * meCron - A lightweight, modular cron engine for PHP
 *
 * @author   Marco Lestuzzi (https://www.marcolestuzzi.it/)
 * @license  MIT License
 * @link     https://github.com/marco-lestuzzi/mecron
 */

require 'lib.php';

if (!file_exists('config.php')) {
	error_http(
		500,
		'Internal Server Error',
		implode(PHP_EOL, array(
			'500 Internal Server Error',
			'Error: Initial project configuration is missing.',
			'Please set up your configuration files before running the application.',
		))
	);
}

require 'config.php';

date_default_timezone_set($_default_timezone);

$_path_base = dirname(__FILE__);
$_script_dir = $_path_base . '/cron-scripts';
$_curl_timeout = 180;
$_lock_handle = null;
$_url_base = detect_base_url();

// Info dashboard
if (isset($_GET['info'])) {
	if (!isset($_mecron_info_password) || $_GET['info'] !== $_mecron_info_password) {
		error_http(403, 'Forbidden', 'Forbidden');
	}
	render_info_dashboard($_script_dir);
	exit;
}

// Single module execution
if (isset($_GET['script'])) {
	run_single_script($_GET['script']);
	exit;
}

// Orchestration
$modules = discover_modules($_script_dir);
if (empty($modules)) {
	exit;
}
launch_parallel($modules);

exit;
