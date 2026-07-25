<?php

// Default TimeZone
$_default_timezone = 'Europe/Rome';

// TODO: Add a login system based on a web interface and POST submission with $_SESSION

// Password to access the info dashboard (?info=<password>)
// Use a long random string (this is the only protection on the endpoint)
$_mecron_info_password = 'change-me';

// This key is used to prevent unauthorized calls
$_mecron_token_curl = 'change-me-too';

// Telegram data (used by lib_telegram.php)
$_telegram_bot_token = '';
$_telegram_chat_id = '';

// Hard cap (seconds) per module run. Never set to 0 (unlimited):
// with ignore_user_abort(true) a stuck module would hang a worker forever.
$_module_max_execution_time = 900;

// Timeout external api connections
$_external_api_timeout = 15;

// Mail data (used by lib_mail.php)
$_mail_config = array(
	'driver' => 'mail', // 'mail' or 'smtp'
	'from_address' => 'mecron@yourdomain.com',
	'from_name' => 'meCron',
	'to' => 'you@example.com',

	// SMTP data (when 'driver' is 'smtp'):
	// 'host' => 'smtp.example.com',
	// 'port' => 587,
	// 'encryption' => 'tls',
	// 'username' => '',
	// 'password' => '',
);
