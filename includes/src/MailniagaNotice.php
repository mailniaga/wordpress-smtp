<?php

namespace Webimpian\MailniagaWPConnector;

/**
 * Shared admin callout, so every message the plugin shows looks the same.
 */
class MailniagaNotice {
	/**
	 * @param string   $title   Short heading.
	 * @param string   $text    One or two sentences.
	 * @param callable $actions Prints the buttons, or null for none.
	 * @param string   $tone    'info', 'warning' or 'busy'.
	 */
	public static function render(string $title, string $text, ?callable $actions = null, string $tone = 'info'): void {
		self::styles();

		printf('<div class="mailniaga-callout mailniaga-callout--%s">', esc_attr($tone));
		echo '<div class="mailniaga-callout__body">';
		printf('<p class="mailniaga-callout__title">%s</p>', esc_html($title));
		printf('<p class="mailniaga-callout__text">%s</p>', esc_html($text));
		echo '</div>';

		if ($actions !== null) {
			echo '<div class="mailniaga-callout__actions">';
			$actions();
			echo '</div>';
		}

		echo '</div>';
	}

	public static function button(string $action, string $label, string $class = 'button'): void {
		printf(
			'<form method="post" action="%s" style="display:inline">%s<input type="hidden" name="action" value="%s"><button type="submit" class="%s">%s</button></form>',
			esc_url(admin_url('admin-post.php')),
			wp_nonce_field($action, '_wpnonce', true, false),
			esc_attr($action),
			esc_attr($class),
			esc_html($label)
		);
	}

	private static function styles(): void {
		static $done = false;

		if ($done) {
			return;
		}

		$done = true;
		?>
		<style>
			.mailniaga-callout{display:flex;flex-wrap:wrap;align-items:center;gap:12px 24px;
				margin:16px 0;padding:16px 18px;background:#fff;border:1px solid #dcdcde;
				border-radius:4px;box-shadow:0 1px 1px rgba(0,0,0,.04)}
			.mailniaga-callout--warning{background:#fcf9e8;border-color:#e6d9a8}
			.mailniaga-callout--busy{background:#f6f7f7;border-color:#dcdcde}
			.mailniaga-callout__body{flex:1 1 32rem;min-width:0}
			.mailniaga-callout__title{margin:0 0 4px;font-size:14px;font-weight:600;line-height:1.4;color:#1d2327}
			.mailniaga-callout__text{margin:0;font-size:13px;line-height:1.6;color:#50575e}
			.mailniaga-callout__actions{display:flex;flex-wrap:wrap;align-items:center;gap:10px}
			.mailniaga-callout__actions .button{margin:0}
		</style>
		<?php
	}
}
