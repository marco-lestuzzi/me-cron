<?php

// Default TimeZone
$_default_timezone = 'Europe/Rome';

// Password to access the info dashboard (?info=<password>)
// Use a long random string (this is the only protection on the endpoint)
$_mecron_info_password = 'change-me';

// Telegram data (used by lib_telegram.php)
$_telegram_bot_token = '';
$_telegram_chat_id = '';

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
