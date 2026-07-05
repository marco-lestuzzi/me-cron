<?php

/**
 * meCron/lock - Concurrency protection and exclusive file-locking library.
 *
 * @author   Marco Lestuzzi (https://www.marcolestuzzi.it/)
 * @license  MIT License
 * @link     https://github.com/marco-lestuzzi/mecron
 */

function acquire_lock($memory_file)
{
	if (!file_exists($memory_file)) {
		return null;
	}

	$lock_handle = fopen($memory_file, 'r+');
	if ($lock_handle === false) {
		return null;
	}

	if (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
		fclose($lock_handle);
		return null;
	}

	return array(
		"handle" => $lock_handle,
		"file" => $memory_file
	);
}

function release_lock($resource)
{
	if (is_array($resource) && array_key_exists("handle", $resource) && is_resource($resource["handle"])) {
		$lock_handle = $resource["handle"];
		flock($lock_handle, LOCK_UN);
		fclose($lock_handle);
	}
}
