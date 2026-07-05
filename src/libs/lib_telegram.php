<?php

/**
 * meCron/telegram - Telegram Bot API notification component.
 *
 * @author   Marco Lestuzzi (https://www.marcolestuzzi.it/)
 * @license  MIT License
 * @link     https://github.com/marco-lestuzzi/mecron
 */

function send_to_telegram_line($testo)
{
	global $_telegram_bot_token, $_telegram_chat_id;
	$curl = curl_init();

	$testo = urlencode($testo);

	curl_setopt_array($curl, array(
		CURLOPT_URL => 'https://api.telegram.org/bot' . $_telegram_bot_token . '/sendMessage?chat_id=' . $_telegram_chat_id . '&text=' . $testo,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 15,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'GET',
	));

	$response = curl_exec($curl);
	curl_close($curl);

	if ($response) {
		$response = json_decode($response, true);
		if (is_array($response) && isset($response["ok"]) && $response["ok"] === true) {
			return true;
		}
	}
	return false;
}


function send_to_telegram_message($testo, $html = false)
{
	global $_telegram_bot_token, $_telegram_chat_id;
	$curl = curl_init();

	$data = array(
		'chat_id' => $_telegram_chat_id,
		'text' => $testo,
	);
	if ($html) {
		$data['parse_mode'] = 'HTML';
	}

	curl_setopt_array($curl, array(
		CURLOPT_URL => 'https://api.telegram.org/bot' . $_telegram_bot_token . '/sendMessage',
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_ENCODING => '',
		CURLOPT_MAXREDIRS => 10,
		CURLOPT_TIMEOUT => 15,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'POST',
		CURLOPT_POSTFIELDS => json_encode($data),
		CURLOPT_HTTPHEADER => array(
			'Content-Type: application/json'
		),
	));

	$response = curl_exec($curl);
	curl_close($curl);

	if ($response) {
		$response = json_decode($response, true);
		if (is_array($response) && isset($response["ok"]) && $response["ok"] === true) {
			return true;
		}
	}
	return false;
}
