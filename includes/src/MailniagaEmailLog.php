<?php

namespace Webimpian\MailniagaWPConnector;

class MailniagaEmailLog {
	private int $per_page = 10;
	private int $days_to_keep = 7;

	public function register() {
		add_action('admin_menu', [$this, 'add_submenu_page']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('admin_post_mailniaga_bulk_action', [$this, 'handle_bulk_action']);
	}

	public function add_submenu_page() {
		$hook = add_submenu_page(
			'mailniaga-smtp',
			__('Email Log', 'mailniaga-smtp'),
			__('Email Log', 'mailniaga-smtp'),
			'manage_options',
			'mailniaga-smtp-log',
			[$this, 'render_log_page']
		);
		add_action("load-$hook", [$this, 'screen_option']);
	}

	public function screen_option() {
	}

	public function enqueue_scripts($hook) {
		if (strpos($hook, 'mailniaga-smtp-log') === false) {
			return;
		}

		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-core');
		wp_enqueue_script('jquery-ui-dialog');
		wp_enqueue_script('jquery-ui-datepicker');
		wp_enqueue_style('wp-jquery-ui-dialog');
		wp_enqueue_style('jquery-ui-datepicker');

		wp_enqueue_style('jquery-ui-style', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');

		wp_enqueue_script(
			'mailniaga-email-log',
			MAILNIAGA_WP_CONNECTOR['URL'] . 'includes/src/assets/js/email-log.js',
			['jquery', 'jquery-ui-core', 'jquery-ui-dialog', 'jquery-ui-datepicker'],
			MAILNIAGA_WP_CONNECTOR['VERSION'],
			true
		);

		wp_enqueue_style(
			'mailniaga-email-log',
			MAILNIAGA_WP_CONNECTOR['URL'] . 'includes/src/assets/css/email-log.css',
			[],
			MAILNIAGA_WP_CONNECTOR['VERSION']
		);

		wp_localize_script('mailniaga-email-log', 'mailniagaEmailLog', [
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('mailniaga_email_details'),
			'i18n' => [
				'emailDetails' => __('Email Details', 'mailniaga-smtp'),
			],
		]);
	}

	public function render_log_page() {
		if (isset($_GET['bulk_deleted'])) {
			$deleted_count = intval($_GET['bulk_deleted']);
			echo '<div class="updated"><p>' . sprintf(_n('%s email log deleted.', '%s email logs deleted.', $deleted_count, 'mailniaga-smtp'), number_format_i18n($deleted_count)) . '</p></div>';
		}

		if (isset($_GET['bulk_requeued'])) {
			$requeued_count = intval($_GET['bulk_requeued']);
			echo '<div class="updated"><p>' . sprintf(_n('%s failed email requeued.', '%s failed emails requeued.', $requeued_count, 'mailniaga-smtp'), number_format_i18n($requeued_count)) . '</p></div>';
		}

		if (isset($_GET['all_cleared'])) {
			echo '<div class="updated"><p>' . __('All email logs have been cleared.', 'mailniaga-smtp') . '</p></div>';
		}

		if (isset($_GET['all_failed_requeued'])) {
			$requeued_count = intval($_GET['all_failed_requeued']);
			echo '<div class="updated"><p>' . sprintf(_n('%s failed email requeued.', '%s failed emails requeued.', $requeued_count, 'mailniaga-smtp'), number_format_i18n($requeued_count)) . '</p></div>';
		}

		if (isset($_GET['processing_recovered'])) {
			$recovered_count = intval($_GET['processing_recovered']);
			echo '<div class="updated"><p>' . sprintf(_n('%s stuck processing email recovered.', '%s stuck processing emails recovered.', $recovered_count, 'mailniaga-smtp'), number_format_i18n($recovered_count)) . '</p></div>';
		}

		$page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
		$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
		$from_date = isset($_GET['from_date']) ? sanitize_text_field($_GET['from_date']) : '';
		$to_date = isset($_GET['to_date']) ? sanitize_text_field($_GET['to_date']) : '';
		$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

		$total_items = $this->get_total_emails($status, $from_date, $to_date, $search);
		$emails = $this->get_emails($page, $status, $from_date, $to_date, $search);

		$status_counts = $this->get_status_counts();

		?>
        <div class="wrap">
            <h1><?php echo esc_html(__('Mail Niaga Email Log', 'mailniaga-smtp')); ?></h1>

			<?php
			$this->render_filter_tabs($status, $status_counts);
			$this->display_auto_delete_notice();
			?>
            <div class="datefilter alignright">
				<?php $this->render_date_filter($from_date, $to_date, $search); ?>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="email-log-form">
                <input type="hidden" name="action" value="mailniaga_bulk_action">
				<?php wp_nonce_field('mailniaga_bulk_action', 'mailniaga_bulk_action_nonce'); ?>

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label for="bulk-action-selector-top" class="screen-reader-text"><?php _e('Select bulk action', 'mailniaga-smtp'); ?></label>
                        <select name="bulk_action" id="bulk-action-selector-top">
                            <option value="-1"><?php _e('Bulk Actions', 'mailniaga-smtp'); ?></option>
                            <option value="delete"><?php _e('Delete', 'mailniaga-smtp'); ?></option>
                            <option value="requeue"><?php _e('Resend Email', 'mailniaga-smtp'); ?></option>
                        </select>
                        <input type="submit" id="doaction" class="button action" value="<?php esc_attr_e('Apply', 'mailniaga-smtp'); ?>">
                    </div>
                    <div>
                        <input type="hidden" name="action" value="mailniaga_bulk_action">
						<?php wp_nonce_field('mailniaga_bulk_action', 'mailniaga_bulk_action_nonce'); ?>
                        <input type="submit" name="clear_all_logs" class="button action" value="<?php esc_attr_e('Clear All Logs', 'mailniaga-smtp'); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to clear all email logs?', 'mailniaga-smtp'); ?>');">
                        <input type="submit" name="resend_all_failed" class="button action" value="<?php esc_attr_e('Resend All Failed', 'mailniaga-smtp'); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to resend all failed emails?', 'mailniaga-smtp'); ?>');">
                        <input type="submit" name="recover_processing" class="button action" value="<?php esc_attr_e('Restart Processing Emails', 'mailniaga-smtp'); ?>" onclick="return confirm('<?php esc_attr_e('Are you sure you want to requeue all processing emails?', 'mailniaga-smtp'); ?>');">
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                    <tr>
                        <td id="cb" class="manage-column column-cb check-column">
                            <label class="screen-reader-text" for="cb-select-all-1"><?php _e('Select All', 'mailniaga-smtp'); ?></label>
                            <input id="cb-select-all-1" type="checkbox">
                        </td>
                        <th><?php _e('ID', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('From', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('To', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('Subject', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('Status', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('Created', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('Updated', 'mailniaga-smtp'); ?></th>
                        <th><?php _e('Actions', 'mailniaga-smtp'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
					<?php if (empty($emails)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center;">
                                <p><?php _e('No email logs found.', 'mailniaga-smtp'); ?></p>
                            </td>
                        </tr>
					<?php else: ?>
					<?php endif; ?>
					<?php foreach ($emails as $email): ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <label class="screen-reader-text" for="cb-select-<?php echo esc_attr($email->id); ?>"><?php printf(__('Select email %s', 'mailniaga-smtp'), $email->id); ?></label>
                                <input id="cb-select-<?php echo esc_attr($email->id); ?>" type="checkbox" name="email_ids[]" value="<?php echo esc_attr($email->id); ?>">
                            </th>
                            <td><?php echo esc_html($email->id); ?></td>
                            <td><?php echo esc_html($email->from_email); ?></td>
                            <td><?php echo esc_html($email->to_email); ?></td>
                            <td><?php echo esc_html($email->subject); ?></td>
                            <td><?php echo esc_html($email->status); ?></td>
                            <td><?php echo esc_html($email->created_at); ?></td>
                            <td><?php echo esc_html($email->updated_at); ?></td>
                            <td>
                                <a href="#" class="view-details" data-id="<?php echo esc_attr($email->id); ?>"><?php _e('View Details', 'mailniaga-smtp'); ?></a>
                            </td>
                        </tr>
					<?php endforeach; ?>
                    </tbody>
                </table>
            </form>
			<?php
			$this->pagination($page, $total_items, $status, $from_date, $to_date);
			$this->render_email_details_modal();
			?>
        </div>
		<?php
	}

	private function render_filter_tabs($current_status, $status_counts) {
		$statuses = [
			'all' => __('All', 'mailniaga-smtp'),
			'sent' => __('Sent', 'mailniaga-smtp'),
			'queued' => __('Queue', 'mailniaga-smtp'),
			'processing' => __('Processing', 'mailniaga-smtp'),
			'failed' => __('Failed', 'mailniaga-smtp'),
		];

		echo '<ul class="subsubsub">';
		$links = [];
		foreach ($statuses as $status => $label) {
			$count = $status_counts[$status] ?? 0;
			$class = ($status === $current_status) ? 'current' : '';
			$links[] = sprintf(
				'<li><a href="%s" class="%s">%s <span class="count">(%s)</span></a></li>',
				esc_url(add_query_arg('status', $status)),
				esc_attr($class),
				esc_html($label),
				number_format_i18n($count)
			);
		}
		echo implode(' | ', $links);
		echo '</ul>';
	}

	private function get_status_counts(): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';
		$results = $wpdb->get_results("
            SELECT status, COUNT(*) as count
            FROM $table_name
            GROUP BY status
        ");

		$counts = [
			'all' => 0,
			'sent' => 0,
			'queue' => 0,
			'processing' => 0,
			'failed' => 0,
		];

		foreach ($results as $result) {
			$counts[$result->status] = $result->count;
			$counts['all'] += $result->count;
		}

		return $counts;
	}

	private function get_total_emails($status = 'all', $from_date = '', $to_date = '', $search = ''): ?string {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';

		$query = "SELECT COUNT(*) FROM $table_name";
		$where_clauses = [];
		$args = [];

		if ($status !== 'all') {
			$where_clauses[] = "status = %s";
			$args[] = $status;
		}

		if ($from_date) {
			$where_clauses[] = "created_at >= %s";
			$args[] = $from_date . ' 00:00:00';
		}

		if ($to_date) {
			$where_clauses[] = "created_at <= %s";
			$args[] = $to_date . ' 23:59:59';
		}

		if ($search) {
			$where_clauses[] = "(to_email LIKE %s OR from_email LIKE %s OR subject LIKE %s)";
			$search_term = '%' . $wpdb->esc_like($search) . '%';
			$args[] = $search_term;
			$args[] = $search_term;
			$args[] = $search_term;
		}

		if (!empty($where_clauses)) {
			$query .= " WHERE " . implode(' AND ', $where_clauses);
			return $wpdb->get_var($wpdb->prepare($query, $args));
		} else {
			// Direct query without prepare when no conditions
			return $wpdb->get_var($query);
		}
	}

	private function get_emails($page, $status = 'all', $from_date = '', $to_date = '', $search = '') {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';
		$offset = ($page - 1) * $this->per_page;

		$query = "SELECT * FROM $table_name";
		$where_clauses = [];
		$args = [];

		if ($status !== 'all') {
			$where_clauses[] = "status = %s";
			$args[] = $status;
		}

		if ($from_date) {
			$where_clauses[] = "created_at >= %s";
			$args[] = $from_date . ' 00:00:00';
		}

		if ($to_date) {
			$where_clauses[] = "created_at <= %s";
			$args[] = $to_date . ' 23:59:59';
		}

		if ($search) {
			$where_clauses[] = "(to_email LIKE %s OR from_email LIKE %s OR subject LIKE %s)";
			$search_term = '%' . $wpdb->esc_like($search) . '%';
			$args[] = $search_term;
			$args[] = $search_term;
			$args[] = $search_term;
		}

		if (!empty($where_clauses)) {
			$query .= " WHERE " . implode(' AND ', $where_clauses);
		}

		$query .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$args[] = $this->per_page;
		$args[] = $offset;

		return $wpdb->get_results($wpdb->prepare($query, $args));
	}

	private function pagination($page, $total_items, $status, $from_date, $to_date) {
		$total_pages = ceil($total_items / $this->per_page);

		$output = '<div class="tablenav"><div class="tablenav-pages">';
		$output .= '<span class="displaying-num">' . sprintf(_n('%s item', '%s items', $total_items, 'mailniaga-smtp'), number_format_i18n($total_items)) . '</span>';

		$output .= '<span class="pagination-links">';

		$base_url = add_query_arg([
			'status' => $status,
			'from_date' => $from_date,
			'to_date' => $to_date,
		]);

		if ($page > 1) {
			$output .= '<a class="first-page button" href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '">«</a>';
			$output .= '<a class="prev-page button" href="' . esc_url(add_query_arg('paged', $page - 1, $base_url)) . '">‹</a>';
		} else {
			$output .= '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>';
			$output .= '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>';
		}

		$output .= '<span class="paging-input">';
		$output .= '<label for="current-page-selector" class="screen-reader-text">' . __('Current Page', 'mailniaga-smtp') . '</label>';
		$output .= '<input class="current-page" id="current-page-selector" type="text" name="paged" value="' . esc_attr($page) . '" size="1" aria-describedby="table-paging">';
		$output .= '<span class="tablenav-paging-text"> ' . __('of', 'mailniaga-smtp') . ' <span class="total-pages">' . number_format_i18n($total_pages) . '</span></span>';
		$output .= '</span>';

		if ($page < $total_pages) {
			$output .= '<a class="next-page button" href="' . esc_url(add_query_arg('paged', $page + 1, $base_url)) . '">›</a>';
			$output .= '<a class="last-page button" href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '">»</a>';
		} else {
			$output .= '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>';
			$output .= '<span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>';
		}

		$output .= '</span></div></div>';

		echo $output;
	}

	private function render_email_details_modal() {
		?>
        <div id="email-details-modal" style="display: none;">
            <div id="email-details-content"></div>
        </div>
		<?php
	}
	public function get_email_details() {
		check_ajax_referer('mailniaga_email_details', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('You do not have sufficient permissions to access this page.'));
		}

		$email_id = intval($_POST['email_id']);
		$email = $this->get_email_by_id($email_id);

		if (!$email) {
			wp_send_json_error(__('Email not found.', 'mailniaga-smtp'));
		}

		$details = sprintf(
			'<p><strong>%s:</strong> %s</p>
            <p><strong>%s:</strong> %s</p>
            <p><strong>%s:</strong> %s</p>
            <p><strong>%s:</strong> %s</p>
            <p><strong>%s:</strong> %s</p>
            <p><strong>%s:</strong></p>
            <pre>%s</pre>',
			__('To', 'mailniaga-smtp'),
			esc_html($email->to_email),
			__('From', 'mailniaga-smtp'),
			esc_html("{$email->from_name} <{$email->from_email}>"),
			__('Subject', 'mailniaga-smtp'),
			esc_html($email->subject),
			__('Status', 'mailniaga-smtp'),
			esc_html($email->status),
			__('Created At', 'mailniaga-smtp'),
			esc_html($email->created_at),
			__('Message', 'mailniaga-smtp'),
			esc_html($email->message)
		);

		wp_send_json_success($details);
	}

	private function get_email_by_id($email_id) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $email_id));
	}

	public function handle_bulk_action() {
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'mailniaga-smtp'));
		}

		check_admin_referer('mailniaga_bulk_action', 'mailniaga_bulk_action_nonce');

		if (isset($_POST['clear_all_logs'])) {
			$cleared_count = $this->clear_all_logs();
			wp_redirect(add_query_arg('all_cleared', $cleared_count, wp_get_referer()));
			exit;
		}

		if (isset($_POST['resend_all_failed'])) {
			$requeued_count = $this->requeue_all_failed_emails();
			wp_redirect(add_query_arg('all_failed_requeued', $requeued_count, wp_get_referer()));
			exit;
		}

		if (isset($_POST['recover_processing'])) {
			$recovered_count = $this->recover_processing_emails();
			wp_redirect(add_query_arg('processing_recovered', $recovered_count, wp_get_referer()));
			exit;
		}

		if (!isset($_POST['email_ids']) || !is_array($_POST['email_ids'])) {
			wp_redirect(wp_get_referer());
			exit;
		}

		$action = $_POST['bulk_action'] ?? '';
		$email_ids = array_map('intval', $_POST['email_ids']);

		switch ($action) {
			case 'delete':
				$deleted_count = $this->delete_emails($email_ids);
				wp_redirect(add_query_arg('bulk_deleted', $deleted_count, wp_get_referer()));
				break;
			case 'requeue':
				$requeued_count = $this->requeue_emails($email_ids);
				wp_redirect(add_query_arg('bulk_requeued', $requeued_count, wp_get_referer()));
				break;
			default:
				wp_redirect(wp_get_referer());
		}
		exit;
	}

	private function delete_emails($email_ids) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';

		$placeholders = implode(', ', array_fill(0, count($email_ids), '%d'));
		$query = $wpdb->prepare("DELETE FROM $table_name WHERE id IN ($placeholders)", $email_ids);

		return $wpdb->query($query);
	}

	private function clear_all_logs() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';
		return $wpdb->query("TRUNCATE TABLE $table_name");
	}

	private function requeue_all_failed_emails() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';
		return $wpdb->query($wpdb->prepare(
			"UPDATE $table_name SET status = 'queued', attempts = 0, error_message = NULL, updated_at = %s WHERE status = 'failed'",
			current_time('mysql')
		));
	}

	private function recover_processing_emails() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE $table_name SET status = 'queued', updated_at = %s WHERE status = 'processing'",
				current_time('mysql')
			)
		);
	}

	private function requeue_emails($email_ids) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'mailniaga_email_queue';

		$placeholders = implode(', ', array_fill(0, count($email_ids), '%d'));
		$query = $wpdb->prepare(
			"UPDATE $table_name SET status = 'queued', attempts = 0, error_message = NULL, updated_at = %s WHERE id IN ($placeholders) AND status = 'failed'",
			current_time('mysql'),
			...$email_ids
		);

		return $wpdb->query($query);
	}

	private function render_date_filter($from_date, $to_date, $search) {
		?>
        <form method="get" action="">
            <input type="hidden" name="page" value="mailniaga-smtp-log">
            <input type="hidden" name="status" value="<?php echo esc_attr($_GET['status'] ?? 'all'); ?>">
            <label for="from_date"><?php _e('From:', 'mailniaga-smtp'); ?></label>
            <input type="text" id="from_date" name="from_date" value="<?php echo esc_attr($from_date); ?>" class="date-picker">
            <label for="to_date"><?php _e('To:', 'mailniaga-smtp'); ?></label>
            <input type="text" id="to_date" name="to_date" value="<?php echo esc_attr($to_date); ?>" class="date-picker">
            <label for="search"><?php _e('Search:', 'mailniaga-smtp'); ?></label>
            <input type="text" id="search" name="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search emails...', 'mailniaga-smtp'); ?>">
            <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'mailniaga-smtp'); ?>">
        </form>
		<?php
	}

	public function display_auto_delete_notice() {
		$message = sprintf(
			__('Notice: Email logs older than %d days will be automatically deleted.', 'mailniaga-smtp'),
			$this->days_to_keep
		);
		echo "<div class='notice notice-warning'><p>{$message}</p></div>";
	}
}