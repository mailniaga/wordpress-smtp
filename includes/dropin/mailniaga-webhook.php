<?php
/**
 * Plugin Name: Mail Niaga Delivery Callbacks
 * Description: Answers Mail Niaga delivery reports before the rest of WordPress
 *              loads. Installed by Mail Niaga SMTP; safe to delete, the plugin
 *              still handles reports without it, just more slowly.
 * Version: 1.0.0
 * Author: Web Impian
 */

defined('ABSPATH') || exit;

if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/mailniaga-smtp/callback') {
	return;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_GET['webhook'])) {
	return;
}

$mailniaga_settings = (array) get_option('mailniaga_wp_connector_settings', []);
$mailniaga_secret = (string) ($mailniaga_settings['webhook'] ?? '');

if ($mailniaga_secret === '' || !hash_equals($mailniaga_secret, (string) $_GET['webhook'])) {
	return;
}

if (!headers_sent()) {
	header('HTTP/1.1 200 OK');
	header('Content-Type: application/json; charset=utf-8');
	header('Connection: close');
}

echo '{"status":"success"}';

if (function_exists('fastcgi_finish_request')) {
	fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
	litespeed_finish_request();
}

if (($_POST['delivered'] ?? '') !== 'false' || empty($_POST['to'])) {
	exit;
}

// Same per-minute cap the plugin applies, so a burst is answered but not stored.
$mailniaga_key = 'mn_wh_' . gmdate('YmdHi');
$mailniaga_count = (int) get_transient($mailniaga_key);
set_transient($mailniaga_key, $mailniaga_count + 1, 120);

if ($mailniaga_count >= (int) apply_filters('mailniaga_webhook_limit', 120)) {
	exit;
}

$mailniaga_table = $wpdb->prefix . 'mailniaga_failed_deliveries';

if ($wpdb->get_var($wpdb->prepare("SELECT id FROM {$mailniaga_table} WHERE to_email = %s", $_POST['to']))) {
	exit;
}

$wpdb->insert(
	$mailniaga_table,
	[
		'email_id' => $_POST['id'] ?? '',
		'domain' => $_POST['domain'] ?? '',
		'to_email' => $_POST['to'],
		'address' => $_POST['address'] ?? '',
		'user' => $_POST['user'] ?? '',
		'interface' => $_POST['interface'] ?? '',
		'from_email' => $_POST['from'] ?? '',
		'delivery_response' => $_POST['delivery_response'] ?? '',
		'ip' => $_POST['ip'] ?? '',
		'mx' => $_POST['mx'] ?? '',
		'created_at' => gmdate('Y-m-d H:i:s'),
		'unsubscribed' => 0,
	],
	['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']
);

exit;
