<?php
/**
 * Mail Niaga SMTP.
 *
 * @author  Web Impian
 * @license GPLv3
 *
 * @see    https://mailniaga.com
 */

/*
 * @wordpress-plugin
 * Plugin Name:         Mail Niaga SMTP
 * Plugin URI:          https://mailniaga.com
 * Version:             2.2.7
 * Description:         Streamline your WordPress email delivery with Mail Niaga API integration. Boost email deliverability, track performance, and ensure reliable SMTP service for all your website's outgoing emails.
 * Author:              Web Impian
 * Author URI:          https://webimpian.com
 * Requires at least:   5.6
 * Tested up to:        7.0
 * Requires PHP:        7.4
 * License:             GPLv3
 * License URI:         https://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain:         mailniaga-smtp
 * Domain Path:         /languages
 */

namespace Webimpian\MailniagaWPConnector;

defined('ABSPATH') || exit;

define(
	'MAILNIAGA_WP_CONNECTOR',
	[
		'SLUG'     => 'mailniaga-smtp',
		'FILE'     => __FILE__,
		'HOOK'     => plugin_basename(__FILE__),
		'PATH'     => realpath(plugin_dir_path(__FILE__)),
		'URL'      => trailingslashit(plugin_dir_url(__FILE__)),
		'VERSION'  => '2.2.7',
	]
);


if (!function_exists('as_enqueue_async_action')) {
	require_once plugin_dir_path(__FILE__) . 'includes/vendor/woocommerce/action-scheduler/action-scheduler.php';
}

add_action('plugins_loaded', function() {
	if (function_exists('as_enqueue_async_action')) {
		require_once plugin_dir_path(__FILE__) . 'includes/vendor/woocommerce/action-scheduler/action-scheduler.php';
	}
}, 0);

require __DIR__.'/includes/load.php';


register_activation_hook(__FILE__, function() {
	MailniagaDatabaseManager::create_email_queue_table();
	MailniagaDatabaseManager::create_failed_delivery_table();

	update_option('mailniaga_db_version', MAILNIAGA_WP_CONNECTOR['VERSION'], false);
});

add_action('plugins_loaded', function() {
	MailniagaConnector::get_instance()->register();
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
	$settings_link = '<a href="' . admin_url('admin.php?page=mailniaga-smtp') . '">' . __('Settings', 'mailniaga-smtp') . '</a>';
	array_unshift($links, $settings_link);
	return $links;
});