<?php

/**
 * meCron/project - Core orchestration engine, module discovery, and dashboard utility.
 *
 * @author   Marco Lestuzzi (https://www.marcolestuzzi.it/)
 * @license  MIT License
 * @link     https://github.com/marco-lestuzzi/mecron
 */

function discover_modules($base_dir)
{
	if (!is_dir($base_dir)) {
		return array();
	}

	$modules = array();
	$dirs = glob($base_dir . '/*', GLOB_ONLYDIR);

	foreach ($dirs as $dir) {
		$folder_name = basename($dir);
		$script_file = $dir . '/' . $folder_name . '.php';
		$config_file = $dir . '/config.json';

		if (!file_exists($script_file)) {
			continue;
		}

		if (!file_exists($config_file)) {
			continue;
		}

		$config = json_decode(file_get_contents($config_file), true);
		if (!is_array($config)) {
			continue;
		}

		if (empty($config['enabled']) || !$config['enabled']) {
			continue;
		}

		if (!should_run_now($dir, $config)) {
			continue;
		}

		$modules[] = $folder_name;
	}

	return $modules;
}

function should_run_now($module_dir, $config)
{
	$interval = isset($config['interval_minutes']) ? (int)$config['interval_minutes'] : 1;

	if ($interval <= 1) {
		return true;
	}

	$memory_file = $module_dir . '/memory.json';
	if (!file_exists($memory_file)) {
		return true;
	}

	$memory = json_decode(file_get_contents($memory_file), true);
	if (empty($memory['last_run'])) {
		return true;
	}

	$last_run = strtotime($memory['last_run']);
	$next_run = $last_run + ($interval * 60);

	return time() >= $next_run;
}

function launch_parallel($modules)
{
	global $_url_base, $_curl_timeout;
	$mh = curl_multi_init();
	$handles = array();

	foreach ($modules as $module_name) {
		$url = $_url_base . '/mecron.php?script=' . urlencode($module_name);
		$ch = curl_init($url);

		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => $_curl_timeout,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FOLLOWLOCATION => false,
		));

		curl_multi_add_handle($mh, $ch);
		$handles[$module_name] = $ch;
	}

	$running = null;
	do {
		$status = curl_multi_exec($mh, $running);
		if ($status === CURLM_CALL_MULTI_PERFORM) {
			continue;
		}
		if ($running > 0) {
			curl_multi_select($mh, 1.0);
		}
	} while ($running > 0 && $status === CURLM_OK);

	foreach ($handles as $ch) {
		curl_multi_remove_handle($mh, $ch);
		curl_close($ch);
	}
	curl_multi_close($mh);
}

function run_single_script($script_name)
{
	global $_script_dir;

	$script_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $script_name);

	$module_dir = $_script_dir . '/' . $script_name;
	$script_file = $module_dir . '/' . $script_name . '.php';
	$memory_file = $module_dir . '/memory.json';
	$config_file = $module_dir . '/config.json';

	if (!file_exists($script_file) || !file_exists($config_file)) {
		return;
	}

	$config = json_decode(file_get_contents($config_file), true);
	if (!is_array($config)) {
		return;
	}

	if (empty($config['enabled']) || !$config['enabled']) {
		return;
	}

	if (!should_run_now($module_dir, $config)) {
		return;
	}

	if (!file_exists($memory_file)) {
		$default = default_memory();
		file_put_contents($memory_file, json_encode($default));
	}

	$lock_acquired = acquire_lock($memory_file);
	if (!$lock_acquired) {
		return;
	}

	$memory = load_memory($memory_file);

	$config = json_decode(file_get_contents($config_file), true);
	if (!is_array($config)) {
		release_lock($lock_acquired);
		return;
	}

	$memory['last_run'] = date('Y-m-d H:i:s');
	$memory['status'] = 'running';
	$memory['run_count'] = (isset($memory['run_count']) ? $memory['run_count'] : 0) + 1;
	save_memory($memory_file, $memory);

	$_cron_script = array();

	require $script_file;

	if (!isset($_cron_script[$script_name]) || !is_callable($_cron_script[$script_name])) {
		trigger_error(
			"Cron module '{$script_name}': funzione non registrata in \$_cron_script['{$script_name}']",
			E_USER_WARNING
		);
		$memory['status'] = 'error';
		$memory['last_error'] = 'Funzione non registrata nel modulo';
		$memory['error_count'] = (isset($memory['error_count']) ? $memory['error_count'] : 0) + 1;
		save_memory($memory_file, $memory);
		release_lock($lock_acquired);
		return;
	}

	try {
		$result = $_cron_script[$script_name]($config, $memory);

		if ($result["status"] === false) {
			$memory['status'] = 'error';
			$memory['error_count'] = (isset($memory['error_count']) ? $memory['error_count'] : 0) + 1;
		} else {
			$memory = $result["memory"];
			$memory['status'] = 'idle';
			$memory['last_success'] = date('Y-m-d H:i:s');
			$memory['last_error'] = null;
		}
	} catch (Exception $e) {
		$memory['status'] = 'error';
		$memory['last_error'] = $e->getMessage();
		$memory['error_count'] = (isset($memory['error_count']) ? $memory['error_count'] : 0) + 1;
		trigger_error(
			"Cron module '{$script_name}': " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(),
			E_USER_ERROR
		);
	}

	save_memory($memory_file, $memory);
	release_lock($lock_acquired);
}

function render_info_dashboard($script_dir)
{
	$dirs = glob($script_dir . '/*', GLOB_ONLYDIR);
	$modules = array();

	foreach ($dirs as $dir) {
		$name = basename($dir);
		$config_file = $dir . '/config.json';
		$memory_file = $dir . '/memory.json';

		$jconfig_file = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : array();
		$config = is_array($jconfig_file) ? $jconfig_file : array();

		$jmemory_file = file_exists($memory_file) ? json_decode(file_get_contents($memory_file), true) : array();
		$memory = is_array($jmemory_file) ? $jmemory_file : array();

		$modules[] = array(
			'name' => $name,
			'enabled' => !empty($config['enabled']),
			'interval' => isset($config['interval_minutes']) ? $config['interval_minutes'] : 1,
			'description' => isset($config['description']) ? $config['description'] : '',
			'status' => isset($memory['status']) ? $memory['status'] : '-',
			'last_run' => isset($memory['last_run']) ? $memory['last_run'] : null,
			'last_success' => isset($memory['last_success']) ? $memory['last_success'] : null,
			'last_error' => isset($memory['last_error']) ? $memory['last_error'] : null,
			'run_count' => isset($memory['run_count']) ? $memory['run_count'] : 0,
			'error_count' => isset($memory['error_count']) ? $memory['error_count'] : 0,
		);
	}

	$generated = date('Y-m-d H:i:s');

	$status_badge = function ($status) {
		$map = array(
			'idle' => array('#d1fae5', '#065f46', '●'),
			'running' => array('#fef3c7', '#92400e', '◉'),
			'error' => array('#fee2e2', '#991b1b', '✕'),
		);
		list($bg, $fg, $icon) = isset($map[$status]) ? $map[$status] : array('#f3f4f6', '#374151', '-');
		return '<span style="background:' . $bg . ';color:' . $fg . ';padding:2px 8px;border-radius:9999px;font-size:0.78rem;font-weight:600;white-space: nowrap;">' . $icon . ' ' . $status . '</span>';
	};

	$fmt_date = function ($d = null) {
		return $d ? date('Y-m-d H:i', strtotime($d)) : '<span style="color:#9ca3af">-</span>';
	};

	$total = count($modules);
	$enabled = count(array_filter($modules, function ($m) {
		return $m['enabled'];
	}));

	$errors = count(array_filter($modules, function ($m) {
		return $m['status'] === 'error';
	}));
	$total_runs = 0;
	foreach ($modules as $modulo) {
		if (is_array($modulo) && isset($modulo['run_count'])) {
			$total_runs += $modulo['run_count'];
		}
	}

	$html = array();
	$html[] = '<!DOCTYPE html>';
	$html[] = '<html lang="en">';
	$html[] = '<head>';
	$html[] = '<meta charset="UTF-8">';
	$html[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
	$html[] = '<title>meCron - Dashboard</title>';
	$html[] = '<style>';
	$html[] = '*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }';
	$html[] = 'body { font-family: system-ui, -apple-system, sans-serif; background: #f9fafb; color: #111827; font-size: 14px; }';
	$html[] = 'header { background: #111827; color: #f9fafb; padding: 18px 32px; display: flex; align-items: center; gap: 16px; }';
	$html[] = 'header h1 { font-size: 1.25rem; font-weight: 700; letter-spacing: -0.02em; }';
	$html[] = 'header span { font-size: 0.8rem; color: #9ca3af; margin-left: auto; }';
	$html[] = 'main { padding: 32px; max-width: 1100px; margin: 0 auto; }';
	$html[] = '.summary { display: flex; gap: 16px; margin-bottom: 28px; flex-wrap: wrap; }';
	$html[] = '.card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px 20px; min-width: 140px; }';
	$html[] = '.card .val { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }';
	$html[] = '.card .lbl { font-size: 0.75rem; color: #6b7280; margin-top: 4px; }';
	$html[] = 'table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; }';
	$html[] = 'th { background: #f3f4f6; text-align: left; padding: 10px 14px; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; font-weight: 600; }';
	$html[] = 'td { padding: 11px 14px; border-top: 1px solid #f3f4f6; vertical-align: top; }';
	$html[] = 'tr:hover td { background: #fafafa; }';
	$html[] = '.module-name { font-weight: 600; font-size: 0.9rem; }';
	$html[] = '.desc { font-size: 0.78rem; color: #6b7280; margin-top: 2px; }';
	$html[] = '.disabled td { opacity: 0.45; }';
	$html[] = '.error-msg { font-size: 0.75rem; color: #dc2626; margin-top: 3px; font-family: monospace; }';
	$html[] = '.interval { color: #6b7280; font-size: 0.82rem; }';
	$html[] = '</style>';
	$html[] = '</head>';
	$html[] = '<body>';
	$html[] = '<header>';
	$html[] = '<h1>⚙ meCron Dashboard</h1>';
	$html[] = '<span>Generated: ' . htmlspecialchars($generated, ENT_QUOTES, "UTF-8") . '</span>';
	$html[] = '</header>';
	$html[] = '<main>';
	$html[] = '<div class="summary">';
	$html[] = '<div class="card"><div class="val">' . $total . '</div><div class="lbl">Total modules</div></div>';
	$html[] = '<div class="card"><div class="val">' . $enabled . '</div><div class="lbl">Enabled</div></div>';
	$html[] = '<div class="card"><div class="val" style="color: ' . ($errors > 0 ? '#dc2626' : 'inherit') . '">' . $errors . '</div><div class="lbl">In error state</div></div>';
	$html[] = '<div class="card"><div class="val">' . number_format($total_runs) . '</div><div class="lbl">Total runs</div></div>';
	$html[] = '</div>';
	$html[] = '<table>';
	$html[] = '<thead>';
	$html[] = '<tr>';
	$html[] = '<th>Module</th>';
	$html[] = '<th>Status</th>';
	$html[] = '<th>Interval</th>';
	$html[] = '<th>Last run</th>';
	$html[] = '<th>Last success</th>';
	$html[] = '<th>Runs</th>';
	$html[] = '<th>Errors</th>';
	$html[] = '</tr>';
	$html[] = '</thead>';
	$html[] = '<tbody>';
	foreach ($modules as $m) {
		$html[] = '<tr class="' . ($m['enabled'] ? '' : 'disabled') . '">';
		$html[] = '<td>';
		$html[] = '<div class="module-name">' . htmlspecialchars($m['name'], ENT_QUOTES, "UTF-8") . '</div>';
		if ($m['description']) {
			$html[] = '<div class="desc">' . htmlspecialchars($m['description'], ENT_QUOTES, "UTF-8") . '</div>';
		}
		if ($m['last_error'] && $m['status'] === 'error') {
			$html[] = '<div class="error-msg">↳ ' . htmlspecialchars($m['last_error'], ENT_QUOTES, "UTF-8") . '</div>';
		}
		$html[] = '</td>';
		$html[] = '<td>' . ($status_badge($m['enabled'] ? $m['status'] : 'disabled')) . '</td>';
		$html[] = '<td><span class="interval">' . ($m['interval'] === 1 ? 'every tick' : 'every ' . $m['interval'] . ' min') . '</span></td>';
		$html[] = '<td>' . $fmt_date($m['last_run']) . '</td>';
		$html[] = '<td>' . $fmt_date($m['last_success']) . '</td>';
		$html[] = '<td>' . number_format($m['run_count']) . '</td>';
		$html[] = '<td style="' . ($m['error_count'] > 0 ? 'color:#dc2626;font-weight:600' : '') . '">' . number_format($m['error_count']) . '</td>';
		$html[] = '</tr>';
	}
	$html[] = '</tbody>';
	$html[] = '</table>';
	$html[] = '</main>';
	$html[] = '</body>';
	$html[] = '</html>';
	echo implode(PHP_EOL, $html);
}

function html2text($html)
{
	$text = preg_replace('/<(br|br\s*\/)\s*>/i', "\n", $html);
	$text = preg_replace('/<\/p>/i', "\n\n", $text);
	$text = strip_tags($text);
	$text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
	$text = preg_replace("/\n[ \t]+/", "\n", $text);
	return $text;
}

function array_convert_encoding_pure($data)
{
	if (is_array($data)) {
		$result = array();
		foreach ($data as $key => $value) {
			// Chiamata ricorsiva standard alla funzione stessa
			$result[$key] = array_convert_encoding_pure($value);
		}
		return $result;
	}

	if (is_string($data) && !mb_detect_encoding($data, 'utf-8', true)) {
		return mb_convert_encoding($data, 'UTF-8', 'ISO-8859-1');
	}

	return $data;
}

function error_http($number, $text, $description = '')
{
	if (!headers_sent()) {
		$protocollo = (isset($_SERVER['SERVER_PROTOCOL']) && preg_match('/^HTTP\/\d\.\d$/', $_SERVER['SERVER_PROTOCOL'])) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0';
		header($protocollo . ' ' . $number . ' ' . $text, true, $number);
		if ($description != '') header('Content-Type: text/plain; charset=utf-8');
	}
	echo $description;
	exit;
}

function detect_base_url()
{
	global $_path_base;
	$scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
	$host = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
	$doc_root = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : '';
	$script_dir = rtrim($_path_base, '/');
	$path = '';
	if ($doc_root !== '' && strpos($script_dir, $doc_root) === 0) {
		$path = substr($script_dir, strlen($doc_root));
	}
	return $scheme . '://' . $host . $path;
}
