<?php

/**
 * meCron/memory - Memory management and module state persistence component.
 *
 * @author   Marco Lestuzzi (https://www.marcolestuzzi.it/)
 * @license  MIT License
 * @link     https://github.com/marco-lestuzzi/mecron
 */

function default_memory()
{
	return array(
		'last_run' => null,
		'last_success' => null,
		'status' => 'idle',
		'run_count' => 0,
		'error_count' => 0,
		'last_error' => null,
		'data' => array()
	);
}

function load_memory($memory_file)
{
	if (!file_exists($memory_file)) {
		$default = default_memory();
		file_put_contents($memory_file, json_encode($default));
		return $default;
	}

	$content = file_get_contents($memory_file);
	$decoded = json_decode($content, true);

	if (!is_array($decoded)) {
		return default_memory();
	}

	return array_merge(default_memory(), $decoded);
}

function save_memory($memory_file, $memory)
{
	$memory = array_convert_encoding_pure($memory);
	file_put_contents($memory_file, json_encode($memory));
}
