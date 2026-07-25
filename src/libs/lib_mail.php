<?php

/**
 * meCron/mail - Email notification component supporting PHP mail() and SMTP.
 *
 * @author   Marco Lestuzzi (https://www.marcolestuzzi.it/)
 * @license  MIT License
 * @link     https://github.com/marco-lestuzzi/mecron
 */

// send mail (with mail or smtp driver)
function send_mail($subject, $body, $body_html = '')
{
	global $_mail_config;
	$driver = isset($_mail_config['driver']) ? $_mail_config['driver'] : 'mail';

	if ($driver === 'smtp') {
		return _send_mail_smtp($_mail_config, $subject, $body, $body_html);
	} else {
		return _send_mail_system($_mail_config, $subject, $body, $body_html);
	}
}

// Send with mail driver
function _send_mail_system($cfg, $subject, $body, $body_html)
{
	$to = _mail_recipients(isset($cfg['to']) ? $cfg['to'] : '');
	if (empty($to)) {
		return false;
	}

	$from_address = isset($cfg['from_address']) ? $cfg['from_address'] : ini_get('sendmail_from');
	$from_name = isset($cfg['from_name']) ? $cfg['from_name'] : '';
	$from_header = $from_name ? '"' . addslashes($from_name) . '" <' . $from_address . '>' : $from_address;

	$boundary = uniqid('mecron_', true);

	if ($body_html !== '') {
		$headers = "From: " . $from_header . "\r\n";
		$headers .= "MIME-Version: 1.0\r\n";
		$headers .= "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n";

		$message = "--" . $boundary . "\r\n";
		$message .= "Content-Type: text/plain; charset=UTF-8\r\n";
		$message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
		$message .= quoted_printable_encode($body) . "\r\n";
		$message .= "--" . $boundary . "\r\n";
		$message .= "Content-Type: text/html; charset=UTF-8\r\n";
		$message .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
		$message .= quoted_printable_encode($body_html) . "\r\n";
		$message .= "--" . $boundary . "--";
	} else {
		$headers = "From: " . $from_header . "\r\n";
		$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
		$headers .= "Content-Transfer-Encoding: quoted-printable\r\n";
		$message = quoted_printable_encode($body);
	}

	return mail($to, $subject, $message, $headers);
}

// Send with smtp driver
function _send_mail_smtp($cfg, $subject, $body, $body_html)
{
	global $_external_api_timeout;

	$host = isset($cfg['host']) ? $cfg['host'] : '';
	$port = intval(isset($cfg['port']) ? $cfg['port'] : 587);
	$encryption = strtolower(isset($cfg['encryption']) ? $cfg['encryption'] : '');
	$username = isset($cfg['username']) ? $cfg['username'] : '';
	$password = isset($cfg['password']) ? $cfg['password'] : '';
	$from_addr = isset($cfg['from_address']) ? $cfg['from_address'] : '';
	$from_name = isset($cfg['from_name']) ? $cfg['from_name'] : '';

	$to = _mail_recipients(isset($cfg['to']) ? $cfg['to'] : '');
	if (empty($to) || empty($host) || empty($from_addr)) {
		return false;
	}

	$socket_host = ($encryption === 'ssl') ? "ssl://" . $host : $host;

	$socket = @fsockopen($socket_host, $port, $errno, $errstr, $_external_api_timeout);
	if (!$socket) {
		trigger_error("lib_mail SMTP: connection failed — " . $errstr . " (" . $errno . ")", E_USER_WARNING);
		return false;
	}
	stream_set_timeout($socket, $_external_api_timeout);

	$read = function () use ($socket) {
		$response = '';
		while ($line = fgets($socket, 512)) {
			$response .= $line;
			if ($line[3] === ' ') break;
		}
		return $response;
	};

	$write = function ($cmd) use ($socket) {
		fwrite($socket, $cmd . "\r\n");
	};

	$expect = function ($code) use ($read) {
		$response = $read();
		return (strpos($response, $code) === 0);
	};

	$read();
	$write("EHLO " . gethostname());
	$read();

	if ($encryption === 'tls') {
		$write("STARTTLS");
		if (!$expect('220')) {
			fclose($socket);
			return false;
		}
		stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
		$write("EHLO " . gethostname());
		$read();
	}

	if (!empty($username) && !empty($password)) {
		$write("AUTH LOGIN");
		$read();
		$write(base64_encode($username));
		$read();
		$write(base64_encode($password));
		if (!$expect('235')) {
			fclose($socket);
			trigger_error("lib_mail SMTP: authentication failed", E_USER_WARNING);
			return false;
		}
	}

	$write("MAIL FROM:<" . $from_addr . ">");
	if (!$expect('250')) {
		fclose($socket);
		return false;
	}

	foreach (array_map('trim', explode(',', $to)) as $recipient) {
		$write("RCPT TO:<" . $recipient . ">");
		if (!$expect('250')) {
			fclose($socket);
			return false;
		}
	}

	$write("DATA");
	if (!$expect('354')) {
		fclose($socket);
		return false;
	}

	$boundary = uniqid('mecron_', true);
	$from_header = $from_name ? '"' . addslashes($from_name) . '" <' . $from_addr . '>' : $from_addr;
	$subject = str_replace(array("\r", "\n"), '', $subject);

	$headers = "";
	$payload = "";

	$headers .= "Date: " . date('r') . "\r\n";
	$headers .= "From: " . $from_header . "\r\n";
	$headers .= "To: " . $to . "\r\n";
	$headers .= "Subject: " . $subject . "\r\n";
	$headers .= "MIME-Version: 1.0\r\n";

	if ($body_html !== '') {
		$headers .= "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n";
		$payload .= "--" . $boundary . "\r\n";
		$payload .= "Content-Type: text/plain; charset=UTF-8\r\n";
		$payload .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
		$payload .= quoted_printable_encode($body) . "\r\n";
		$payload .= "--" . $boundary . "\r\n";
		$payload .= "Content-Type: text/html; charset=UTF-8\r\n";
		$payload .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
		$payload .= quoted_printable_encode($body_html) . "\r\n";
		$payload .= "--" . $boundary . "--";
	} else {
		$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
		$headers .= "Content-Transfer-Encoding: quoted-printable\r\n";
		$payload .= quoted_printable_encode($body);
	}

	fwrite($socket, $headers . "\r\n" . $payload . "\r\n.\r\n");
	if (!$expect('250')) {
		fclose($socket);
		return false;
	}

	$write("QUIT");
	fclose($socket);

	return true;
}

function _mail_recipients($to)
{
	if (is_array($to)) {
		return implode(', ', array_filter(array_map('trim', $to)));
	}
	return trim($to);
}
