<?php

namespace Webimpian\MailniagaWPConnector;

class MailniagaFailedDeliveriesLog {
	private int $per_page = 25;

	public function register() {
		add_action('admin_menu', [$this, 'add_submenu_page']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
	}

	public function add_submenu_page() {
		$hook = add_submenu_page(
			'mailniaga-smtp',
			__('Failed Deliveries', 'mailniaga-smtp'),
			__('Failed Deliveries', 'mailniaga-smtp'),
			'manage_options',
			'mailniaga-smtp-failed-deliveries',
			[$this, 'render_failed_deliveries_page']
		);
		add_action("load-$hook", [$this, 'screen_option']);
	}

	public function screen_option() {
	}

	public function enqueue_scripts($hook) {
		if (strpos($hook, 'mailniaga-smtp-failed-deliveries') === false) {
			return;
		}

		$base = trailingslashit(MAILNIAGA_WP_CONNECTOR['PATH']) . 'includes/src/assets/';

		wp_enqueue_style(
			'mailniaga-settings-page',
			MAILNIAGA_WP_CONNECTOR['URL'] . 'includes/src/assets/css/settings-page.css',
			[],
			(string) filemtime($base . 'css/settings-page.css')
		);

		wp_enqueue_style(
			'mailniaga-email-log',
			MAILNIAGA_WP_CONNECTOR['URL'] . 'includes/src/assets/css/email-log.css',
			['mailniaga-settings-page'],
			(string) filemtime($base . 'css/email-log.css')
		);

		wp_enqueue_script(
			'mailniaga-email-log',
			MAILNIAGA_WP_CONNECTOR['URL'] . 'includes/src/assets/js/email-log.js',
			['jquery'],
			(string) filemtime($base . 'js/email-log.js'),
			true
		);
	}

	public function render_failed_deliveries_page() {
		$page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
		$total_items = $this->get_total_failed_deliveries();
		$failed_deliveries = $this->get_failed_deliveries($page);

		?>
        <div class="wrap mn-wrap mn-logpage">
            <h1 class="screen-reader-text"><?php echo esc_html(__('Failed Deliveries', 'mailniaga-smtp')); ?></h1>

            <header class="mn-hero mn-hero-compact">
                <div class="mn-hero-brand">
                    <div>
                        <p class="mn-hero-title"><?php esc_html_e('Failed', 'mailniaga-smtp'); ?> <span class="mn-gold-word"><?php esc_html_e('Deliveries', 'mailniaga-smtp'); ?></span></p>
                        <p class="mn-hero-sub"><?php esc_html_e('Addresses Mail Niaga could not deliver to, reported by the callback.', 'mailniaga-smtp'); ?></p>
                    </div>
                </div>
                <div class="mn-hero-stats">
                    <div class="mn-stat">
                        <span class="mn-stat-value"><?php echo esc_html(number_format_i18n((int) $total_items)); ?></span>
                        <span class="mn-stat-label"><?php esc_html_e('Addresses', 'mailniaga-smtp'); ?></span>
                    </div>
                </div>
            </header>

            <section class="mn-card mn-tablecard">
                <div class="mn-table-scroll">
                <table class="mn-table">
                    <thead>
                    <tr>
                        <th><?php _e('Domain', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('To Email', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('From Email', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('MX', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('Response', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('Created At', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('Unsubscribed', 'mailniaga-smtp'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
					<?php if (empty($failed_deliveries)): ?>
                        <tr>
                            <td colspan="7" class="mn-empty">
                                <span class="mn-empty-icon mn-empty-icon-good" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></span>
                                <span class="mn-empty-title"><?php _e('No failed deliveries', 'mailniaga-smtp'); ?></span>
                                <span class="mn-empty-hint"><?php _e('Every email is reaching its inbox. Addresses that bounce will appear here.', 'mailniaga-smtp'); ?></span>
                            </td>
                        </tr>
					<?php else: ?>
						<?php foreach ($failed_deliveries as $delivery): ?>
                            <tr>
                                <td><?php echo $this->cell($delivery->domain); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                <td><?php echo $this->cell($delivery->to_email); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                <td><?php echo $this->cell($delivery->from_email); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
                                <td class="mn-col-mx"><span title="<?php echo esc_attr($delivery->mx); ?>"><?php echo $this->cell($delivery->mx); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></td>
                                <td class="mn-col-resp"><span title="<?php echo esc_attr($delivery->delivery_response); ?>"><?php echo $this->cell($delivery->delivery_response); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></td>
                                <td class="mn-col-date"><?php echo esc_html($delivery->created_at); ?></td>
                                <td>
									<?php if ($delivery->unsubscribed): ?>
                                        <span class="mn-pill mn-pill-sent"><?php _e('Yes', 'mailniaga-smtp'); ?></span>
									<?php else: ?>
                                        <span class="mn-pill mn-pill-other"><?php _e('No', 'mailniaga-smtp'); ?></span>
									<?php endif; ?>
                                </td>
                            </tr>
						<?php endforeach; ?>
					<?php endif; ?>
                    </tbody>
                </table>
                </div>
            </section>
			<?php if (!empty($failed_deliveries)): ?>
				<?php $this->pagination($page, $total_items); ?>
			<?php endif; ?>
        </div>
		<?php
	}

	private function cell($value): string {
		$value = trim((string) $value);

		if ($value === '') {
			return '<span class="mn-na">' . esc_html__('N/A', 'mailniaga-smtp') . '</span>';
		}

		return esc_html($value);
	}

	private function get_total_failed_deliveries() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_failed_deliveries';
		return $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
	}

	private function get_failed_deliveries($page) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_failed_deliveries';
		$offset = ($page - 1) * $this->per_page;

		$query = "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d";
		return $wpdb->get_results($wpdb->prepare($query, $this->per_page, $offset));
	}

	private function pagination($page, $total_items) {
		$total_pages = ceil($total_items / $this->per_page);

		echo '<div class="tablenav mn-pagenav"><div class="tablenav-pages">';
		echo '<span class="displaying-num">' . sprintf(_n('%s item', '%s items', $total_items, 'mailniaga-smtp'), number_format_i18n($total_items)) . '</span>';

		echo '<span class="pagination-links">';

		if ($page > 1) {
			echo '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1)) . '">«</a>';
			echo '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $page - 1)) . '">‹</a>';
		} else {
			echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>';
			echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
		}

		echo '<span class="paging-input">';
		echo '<label for="current-page-selector" class="screen-reader-text">' . __('Current Page', 'mailniaga-smtp') . '</label>';
		echo '<input class="current-page" id="current-page-selector" type="text" name="paged" value="' . esc_attr($page) . '" size="1" aria-describedby="table-paging">';
		echo '<span class="tablenav-paging-text"> ' . __('of', 'mailniaga-smtp') . ' <span class="total-pages">' . number_format_i18n($total_pages) . '</span></span>';
		echo '</span>';

		if ($page < $total_pages) {
			echo '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $page + 1)) . '">›</a>';
			echo '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages)) . '">»</a>';
		} else {
			echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';
			echo '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
		}

		echo '</span></div></div>';
	}
}