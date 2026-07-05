<?php

$_cron_script['hn_top_stories'] = function ($config, $memory) {

	$params = isset($config['params']) ? $config['params'] : array();
	$watch_count = (int) (isset($params['watch_count']) ? $params['watch_count'] : 30); // how many top stories to track
	$score_threshold = (int) (isset($params['score_threshold']) ? $params['score_threshold'] : 100); // minimum score to notify
	$max_notify = (int) (isset($params['max_notify']) ? $params['max_notify'] : 3); // max notifications per run

	$fetch_url = function ($url) {
		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTPHEADER => array('Accept: application/json'),
		));
		$response = curl_exec($ch);
		$error = curl_errno($ch);
		curl_close($ch);

		return ($error === 0 && $response !== false) ? $response : null;
	};

	// --- Fetch current top story IDs ---
	$ids_raw = $fetch_url('https://hacker-news.firebaseio.com/v0/topstories.json');
	if ($ids_raw === null) {
		return array('status' => false, 'memory' => $memory);
	}

	$ids = json_decode($ids_raw, true);
	$top_ids = array_slice(($ids ? $ids : array()), 0, $watch_count);
	if (empty($top_ids)) {
		return array('status' => false, 'memory' => $memory);
	}

	// --- Fetch each story ---
	$current_stories = array();
	foreach ($top_ids as $id) {
		$story_raw = $fetch_url("https://hacker-news.firebaseio.com/v0/item/{$id}.json");
		if ($story_raw === null) {
			continue;
		}
		$story = json_decode($story_raw, true);
		if (!is_array($story) || empty($story['title'])) {
			continue;
		}
		$current_stories[$id] = array(
			'id' => $id,
			'title' => $story['title'],
			'url' => isset($story['url']) ? $story['url'] : "https://news.ycombinator.com/item?id={$id}",
			'score' => (int) (isset($story['score']) ? $story['score'] : 0),
			'by' => isset($story['by']) ? $story['by'] : '',
		);
	}

	$seen_ids = isset($memory['data']['seen_ids']) ? $memory['data']['seen_ids'] : array();

	$notifications = array();
	$count = 0;

	foreach ($current_stories as $id => $story) {
		if ($count >= $max_notify) {
			break;
		}
		if (in_array($id, $seen_ids, true)) {
			continue;
		}
		if ($story['score'] < $score_threshold) {
			continue;
		}

		$hn_link = "https://news.ycombinator.com/item?id=" . $id;
		$src_link = $story['url'] !== $hn_link
			? "<br><a href=\"" . htmlentities($story['url'], ENT_QUOTES, 'UTF-8') . "\">Read article</a> · "
			: "<br>";

		$notifications[] =
			"<b>🔥 " . htmlentities($story['title'], ENT_QUOTES, 'UTF-8') . "</b><br>" .
			"⭐ Score: {$story['score']} · 👤 " . htmlentities($story['by'], ENT_QUOTES, 'UTF-8') .
			$src_link .
			"<a href=\"" . htmlentities($hn_link, ENT_QUOTES, 'UTF-8') . "\">HN discussion</a>";

		$count++;
	}

	if (!empty($notifications)) {
		$header = "<b>Hacker News - Top Stories</b><br><br>";
		$text = $header . implode("<br><br>", $notifications);
		$sent = send_mail("Hacker News - Top Stories", html2text($text), $text);
		if (!$sent) {
			return array('status' => false, 'memory' => $memory);
		}
	}

	// Persist only the IDs we're currently tracking (no infinite growth)
	$memory['data']['seen_ids'] = array_keys($current_stories);

	return array('status' => true, 'memory' => $memory);
};
