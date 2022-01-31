<?php
	if(!defined('ROOT')) die('Don\'t call this directly.');

	header('Content-Type: text/html; charset=utf-8');

	$ip = $_SERVER['REMOTE_ADDR'];

	if (isset($_GET['key'])) {
		// email verification (double opt-in)
		$key = $_GET['key'];
		if ($email = db_lookup_subscriber($key)) {
			// new subscriber, yay!
			send_email(
				$to=$config['email']['from'],
				$subject="microblog - new subscriber!",
				$message="Congrats! You have a new subscriber: " . $email
			);
			db_upsert_subscriber($email, $ip, $key, $confirmed=true);
			$message = array(
				'status' => 'success',
				'message' => 'Thank you for verifying your email. Your subscription is confirmed.'
			);
		} else {
			$message = array(
				'status' => 'error',
				'message' => 'Invalid key.'
			);
		}
	} elseif (isset($_POST['email'])) {
		$email = $_POST['email'];
		if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$key = bin2hex(random_bytes(8));

			$subject = empty($config['microblog_account']) ? "" : $config['microblog_account'] . "'s ";
			$subject .= "microblog - subscription confirmation";

			$message = "Please click the link below to receive posts via email:" . NL . NL;
			$message .= $config['url'] . "/subscribe?key=" . $key . NL . NL . NL;
			$message .= "If you did not request this subscription, simply ignore this email." . NL;

			send_email($to=$email, $subject, $message);
			db_upsert_subscriber($email, $ip, $key, $confirmed=false);

			$message = array(
				'status' => 'success',
				'message' => 'Your subscription request has been received. Check your email for a link to confirm.'
			);
		} else {
			$message = array(
				'status' => 'error',
				'message' => 'The email entered was invalid.'
			);
		}
	}

?><!DOCTYPE html>
<html lang="<?= $config['language'] ?>" class="login">
<head>
	<title><?= empty($config['microblog_account']) ? "" : $config['microblog_account'] . "'s " ?>microblog - subscribe</title>
	<meta name="viewport" content="width=device-width" />
	<link rel="stylesheet" href="<?= $config['url'] ?>/microblog.css" />
	<script async defer data-website-id="a2c0cc3e-4951-41f3-a9f5-6b59df682756" src="https://umami.izrm.net/umami.js" data-do-not-track="true"></script>
</head>
<body>
	<div class="wrap">
		<nav>
			<ul>
				<li><a href="<?= $config['url'] ?>/"><?= empty($config['microblog_account']) ? "" : $config['microblog_account'] . "'s " ?>microblog</a></li>
			</ul>
		</nav>
		<?php if(isset($message['status']) && isset($message['message'])): ?>
		<p class="message <?= $message['status'] ?>"><?= $message['message'] ?></p>
		<?php else: ?>
		<form action="" method="post">
			<p><label>To sign up to have posts delivered to you, enter your email address:<input type="email" name="email" /></label></p>
			<input type="submit" name="" value="Subscribe" />
		</form>
		<?php endif; ?>
	</div>
</body>
</html>
