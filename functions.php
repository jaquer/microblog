<?php

function path($fragment=null) {
	global $config;
	if($fragment === null) return $config['path'];
	return (!empty($config['path'][$fragment])) ? $config['path'][$fragment] : false;
}

function db_insert($content, $timestamp=NOW) {
	global $db;
	if(empty($db)) return false;

	$statement = $db->prepare('INSERT INTO posts (post_content, post_timestamp) VALUES (:post_content, :post_timestamp)');

	$statement->bindParam(':post_content', $content, PDO::PARAM_STR);
	$statement->bindParam(':post_timestamp', $timestamp, PDO::PARAM_INT);

	$statement->execute();

	return $db->lastInsertId();
}

function db_select_post($id=0) {
	global $db;
	if(empty($db)) return false;
	if($id === 0) return false;

	$statement = $db->prepare('SELECT * FROM posts WHERE id = :id LIMIT 1');
	$statement->bindParam(':id', $id, PDO::PARAM_INT);
	$statement->execute();
	$row = $statement->fetch(PDO::FETCH_ASSOC);

	return (!empty($row)) ? $row : false;
}

function db_select_posts($from=NOW, $amount=10, $sort='desc', $page=1) {
	global $config;
	global $db;
	if(empty($db)) return false;
	if($sort !== 'desc') $sort = 'asc';

	$offset = ($page-1)*$config['posts_per_page'];

	$statement = $db->prepare('SELECT * FROM posts WHERE post_timestamp < :post_timestamp ORDER BY id '.$sort.' LIMIT :limit OFFSET :page');
	$statement->bindParam(':post_timestamp', $from, PDO::PARAM_INT);
	$statement->bindParam(':limit', $amount, PDO::PARAM_INT);
	$statement->bindParam(':page', $offset, PDO::PARAM_INT);
	$statement->execute();
	$rows = $statement->fetchAll(PDO::FETCH_ASSOC);

	return (!empty($rows)) ? $rows : false;
}

function db_posts_count() {
	global $config;
	global $db;
	if(empty($db)) return false;

	$statement = $db->prepare('SELECT COUNT(*) AS posts_count FROM posts');
	$statement->execute();
	$row = $statement->fetch(PDO::FETCH_ASSOC);

	return (int) $row['posts_count'];
}

function db_upsert_subscriber($email='', $ip, $key, $confirmed, $timestamp=NOW) {
	global $config;
	global $db;
	if(empty($db)) return false;
	if(empty($email)) return false;

	$statement = $db->prepare('
		INSERT INTO subscribers (
			subscriber_email,
			subscriber_confirmed,
			subscriber_ip,
			subscriber_key,
			subscriber_timestamp
		) VALUES (
			:email,
			:confirmed,
			:ip,
			:key,
			:timestamp
		) ON CONFLICT (subscriber_email) DO UPDATE SET
			subscriber_confirmed = :confirmed,
			subscriber_ip = :ip,
			subscriber_key = :key,
			subscriber_timestamp = :timestamp'
	);

	$statement->bindParam(':email', $email, PDO::PARAM_STR);
	$statement->bindParam(':confirmed', $confirmed, PDO::PARAM_BOOL);
	$statement->bindParam(':ip', $ip, PDO::PARAM_STR);
	$statement->bindParam(':key', $key, PDO::PARAM_STR);
	$statement->bindParam(':timestamp', $timestamp, PDO::PARAM_INT);

	return $statement->execute();
}

function db_lookup_subscriber($key='') {
	global $config;
	global $db;
	if(empty($db)) return false;
	if(empty($key)) return false;

	$statement = $db->prepare('SELECT * FROM subscribers WHERE subscriber_key = :key LIMIT 1');
	$statement->bindParam(':key', $key, PDO::PARAM_STR);
	$statement->execute();
	$row = $statement->fetch(PDO::FETCH_ASSOC);

	return (!empty($row)) ? $row['subscriber_email'] : false;
}

function db_lookup_confirmed_subscribers() {
	global $config;
	global $db;
	if(empty($db)) return false;

	$statement = $db->prepare('SELECT * FROM subscribers WHERE subscriber_confirmed = 1');
	$statement->execute();
	$rows = $statement->fetchAll(PDO::FETCH_ASSOC);

	return (!empty($rows)) ? $rows : false;
}

function email_subscribers($post_content, $post_id) {
	global $config;
	global $db;
	if(empty($db)) return false;
	if(empty($post_content)) return false;

	$subscribers = db_lookup_confirmed_subscribers();

	if (empty($subscribers)) return; // :cry:

	$from = $config['email']['from'];
	$bcc = implode(",", array_column($subscribers, 'subscriber_email'));

	$subject = "New post on ";
	$subject .= empty($config['microblog_account']) ? "" : $config['microblog_account'] . "'s ";
	$subject .= "microblog - #" . $post_id;

	$message = $post_content;
	$message .= NL . NL . NL;
	$message .= "-- " . NL . NL;
	$message .= "View this post online: " . $config['url'] . "/" . $post_id . NL . NL;
	$message .= "To unsubscribe, send your request to: " . $from . NL;

	return send_email(null, $subject, $message, $from, array('Bcc' => $bcc));
}

function send_email($to, $subject, $message, $from='', $additional_headers=array()) {
	global $config;
	if (empty($from)) $from = $config['email']['from'];

	$additional_headers['From'] = $from;
	$additional_headers['Content-Type'] = 'text/plain;charset=utf-8';

	/* https://www.php.net/manual/en/function.mail.php */
	/* "Each line should be separated with a CRLF (\r\n)." */
	$message = preg_replace("/\r\n|\r|\n/", "\r\n", $message);
	/* "Lines should not be larger than 70 characters." */
	$message = line_wrap($message);

	return mail($to, $subject, $message, $additional_headers);
}

/* https://www.php.net/manual/en/function.wordwrap.php#58802 */
function line_wrap($string, $width=70, $break="\r\n", $cut_long_words=false) {
	$lines = explode($break, $string);
	$string = "";
	foreach($lines as $line) {
		$string .= wordwrap($line, $width, $break, $cut_long_words);
		$string .= $break;
	}
	return $string;
}

/* function that pings the official micro.blog endpoint for feed refreshes */
function ping_microblog() {
	global $config;
	$ping_url = 'https://micro.blog/ping';
	$feed_url = $config['url'].'/feed.json';

	$ch = curl_init($ping_url);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, 'url='.urlencode($feed_url));
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_NOBODY, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 10);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

	$response = curl_exec($ch);
	$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

	return ($status == 200) ? true : false;
}

function rebuild_feed($amount=10) {
	global $config;

	$feed = array(
		'version' => 'https://jsonfeed.org/version/1',
		'title' => 'status updates by '.$config['microblog_account'],
		'description' => '',
		'home_page_url' => $config['url'],
		'feed_url' => $config['url'].'/feed.json',
		'user_comment' => '',
		'favicon' => '',
		'author' => array('name' => $config['microblog_account']),
		'items' => array()
	);

	// make a timezone string for dates (is this dumb?)
	$timezone_offset = timezone_offset_get(timezone_open('UTC'), new DateTime())/60/60; // this is probably incorrect
	$timezone_offset_string = (is_int($timezone_offset) && $timezone_offset >= 0) ? '+'.str_pad($timezone_offset, 2, '0', STR_PAD_LEFT).':00' : '-'.str_pad($timezone_offset, 2, '0', STR_PAD_LEFT).':00';

	$posts = db_select_posts(NOW+60, $amount, 'desc');

	foreach($posts as $post) {

		$feed['items'][] = array(
			'id' => $config['url'].'/'.$post['id'],
			'url' => $config['url'].'/'.$post['id'],
			'title' => '',
			'content_html' => $post['post_content'],
			'date_published' => strftime('%Y-%m-%dT%H:%M:%S', $post['post_timestamp']).$timezone_offset_string
		);
	}

	if(file_put_contents(ROOT.DS.'feed.json', json_encode($feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
		return true;
	} else return false;
}

function twitter_post_status($status='') {
	global $config;
	require_once(ROOT.DS.'twitter_api.php');

	if(empty($status)) return array('errors' => 1);
	if(empty($config['twitter']['oauth_access_token']) ||
		empty($config['twitter']['oauth_access_token_secret']) ||
		empty($config['twitter']['consumer_key']) ||
		empty($config['twitter']['consumer_secret'])) return array('errors' => 2);

	$url = 'https://api.twitter.com/1.1/statuses/update.json';
	$postfields = array(
		'status' => $status,
		'trim_user' => 1
	);

	$twitter = new TwitterAPIExchange($config['twitter']);
	return $twitter->buildOauth($url, 'POST')->setPostfields($postfields)->performRequest();
}
