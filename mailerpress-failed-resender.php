<?php
/**
 * Plugin Name: MailerPress Failed Resender
 * Plugin URI:  https://classicmonks.com/
 * Description: Adds a "Retry Failed" submenu under MailerPress that lists every
 *              campaign with failed emails/chunks and exposes a one-click
 *              "Retry Failed" button. The button calls the existing MailerPress
 *              REST endpoint POST /mailerpress/v1/recovery/batch/{id}/retry
 *              to reset all failed/retry/processing chunks of a batch.
 *              This plugin does NOT modify the MailerPress source code.
 * Version:     1.0.0
 * Author:      Classic Monks
 * Author URI:  https://classicmonks.com/
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 *
 * @package MailerPressFailedResender
 */

defined( 'ABSPATH' ) || exit;

/**
 * --------------------------------------------------------------------------
 * Constants
 * --------------------------------------------------------------------------
 */
if ( ! defined( 'MPFR_FILE' ) ) {
	define( 'MPFR_FILE', __FILE__ );
}
if ( ! defined( 'MPFR_DIR' ) ) {
	define( 'MPFR_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'MPFR_URL' ) ) {
	define( 'MPFR_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'MPFR_VERSION' ) ) {
	define( 'MPFR_VERSION', '1.0.0' );
}
if ( ! defined( 'MPFR_REST_NAMESPACE' ) ) {
	define( 'MPFR_REST_NAMESPACE', 'mailerpress/v1' );
}
if ( ! defined( 'MPFR_MENU_SLUG' ) ) {
	define( 'MPFR_MENU_SLUG', 'mpfr-retry-failed' );
}
if ( ! defined( 'MPFR_PARENT_SLUG' ) ) {
	define( 'MPFR_PARENT_SLUG', 'mailerpress/campaigns.php' );
}
if ( ! defined( 'MPFR_CAPABILITY' ) ) {
	define( 'MPFR_CAPABILITY', 'manage_options' );
}

/**
 * --------------------------------------------------------------------------
 * Bootstrap
 * --------------------------------------------------------------------------
 */
add_action( 'plugins_loaded', 'mpfr_bootstrap' );

function mpfr_bootstrap() {
	// Bail out gracefully if MailerPress is not active.
	if ( ! mpfr_is_mailerpress_active() ) {
		add_action( 'admin_notices', 'mpfr_render_missing_dependency_notice' );
		return;
	}

	add_action( 'admin_menu', 'mpfr_register_submenu', 100 );
	add_action( 'admin_enqueue_scripts', 'mpfr_enqueue_assets' );
	add_action( 'admin_notices', 'mpfr_maybe_render_failed_campaigns_notice' );
	add_action( 'admin_print_footer_scripts', 'mpfr_inject_row_action_script' );

	// Register the background-batch AS callback.
	add_action( mpfr_as_hook(), 'mpfr_process_resend_batch', 10, 3 );

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'mpfr retry-failed', '\\MailerPressFailedResender\\CLI\\Retry_Failed_Command' );
	}
}

/**
 * Detect if MailerPress is active. The MailerPress admin page hook contains
 * "mailerpress"; the plugin also defines the constant MAILERPRESS_VERSION.
 *
 * @return bool
 */
function mpfr_is_mailerpress_active() {
	if ( defined( 'MAILERPRESS_VERSION' ) ) {
		return true;
	}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active( 'mailerpress/mailerpress.php' )
		|| is_plugin_active( 'mailerpress-pro/mailerpress-pro.php' );
}

/**
 * Notice shown when MailerPress is not active.
 */
function mpfr_render_missing_dependency_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || strpos( $screen->id, 'mpfr' ) === false ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php esc_html_e( 'MailerPress Failed Resender', 'mpfr' ); ?></strong>:
			<?php esc_html_e( 'MailerPress plugin is not active. This add-on will not work until MailerPress is installed and activated.', 'mpfr' ); ?>
		</p>
	</div>
	<?php
}

/**
 * --------------------------------------------------------------------------
 * Admin menu
 * --------------------------------------------------------------------------
 */
function mpfr_register_submenu() {
	add_submenu_page(
		MPFR_PARENT_SLUG,
		__( 'Retry Failed Emails', 'mpfr' ),
		__( 'Retry Failed', 'mpfr' ),
		MPFR_CAPABILITY,
		MPFR_MENU_SLUG,
		'mpfr_render_admin_page'
	);
}

/**
 * --------------------------------------------------------------------------
 * Asset enqueue (only on our page)
 * --------------------------------------------------------------------------
 */
function mpfr_enqueue_assets( $hook ) {
	// The hook for a submenu page of a custom parent is
	// "{parent-slug}_page_{submenu-slug}" but the parent slug contains a slash
	// so the hook is constructed as "<sanitized>_page_<slug>".
	// We accept either the exact hook or any string containing our slug.
	if ( strpos( (string) $hook, MPFR_MENU_SLUG ) === false ) {
		return;
	}

	wp_enqueue_script(
		'mpfr-admin',
		MPFR_URL . 'admin.js',
		array( 'wp-api-fetch', 'wp-i18n', 'wp-element' ),
		MPFR_VERSION,
		true
	);

	wp_set_script_translations( 'mpfr-admin', 'mpfr' );

	wp_localize_script(
		'mpfr-admin',
		'MPFR_DATA',
		array(
			'restUrl'   => esc_url_raw( rest_url( MPFR_REST_NAMESPACE . '/' ) ),
			'restRoot'  => esc_url_raw( rest_url() ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'pageUrl'   => esc_url_raw( admin_url( 'admin.php?page=' . MPFR_MENU_SLUG ) ),
			'i18n'      => array(
				'title'           => __( 'Retry Failed Emails', 'mpfr' ),
				'subtitle'        => __( 'Campaigns with failed emails or stuck chunks. Click "Retry Failed" to reset all failed/retry/processing chunks for that campaign. Chunks will be rescheduled 60s out and processed by the MailerPress chunk worker.', 'mpfr' ),
				'loading'         => __( 'Loading…', 'mpfr' ),
				'noFailures'      => __( 'No campaigns with failed emails. ', 'mpfr' ),
				'campaign'        => __( 'Campaign', 'mpfr' ),
				'status'          => __( 'Status', 'mpfr' ),
				'total'           => __( 'Total', 'mpfr' ),
				'sent'            => __( 'Sent', 'mpfr' ),
				'errors'          => __( 'Errors', 'mpfr' ),
				'failedChunks'    => __( 'Failed chunks', 'mpfr' ),
				'actions'         => __( 'Actions', 'mpfr' ),
				'retry'           => __( 'Retry Failed', 'mpfr' ),
				'retrying'        => __( 'Retrying…', 'mpfr' ),
				'successPrefix'   => __( 'Scheduled %d chunk(s) for retry.', 'mpfr' ),
				'resetPrefix'     => __( 'Batch reset: %d chunk(s) rescheduled.', 'mpfr' ),
				'errorPrefix'     => __( 'Failed: %s', 'mpfr' ),
				'refresh'         => __( 'Refresh', 'mpfr' ),
				'reset'           => __( 'Hard Reset Batch', 'mpfr' ),
				'confirmReset'    => __( 'This will reset ALL chunks of the batch (including completed) and zero the sent/error counters. Continue?', 'mpfr' ),
				// New strings for hide/show functionality
				'hide'            => __( 'Hide', 'mpfr' ),
				'hiding'          => __( 'Hiding…', 'mpfr' ),
				'showHidden'      => __( 'Show Hidden', 'mpfr' ),
				'hideHidden'      => __( 'Hide Hidden', 'mpfr' ),
				'campaignHidden'  => __( 'Campaign hidden.', 'mpfr' ),
				'hiddenCount'     => __( '(%d)', 'mpfr' ),
			),
		)
	);

	wp_enqueue_style(
		'mpfr-admin',
		MPFR_URL . 'admin.css',
		array(),
		MPFR_VERSION
	);
}

/**
 * --------------------------------------------------------------------------
 * Admin page renderer (server side)
 *
 * The page is a simple shell. All data is loaded asynchronously by admin.js
 * via the public MailerPress REST API. We render only an empty container
 * and the localized script picks it up.
 * --------------------------------------------------------------------------
 */
function mpfr_render_admin_page() {
	if ( ! current_user_can( MPFR_CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mpfr' ) );
	}
	?>
	<div class="wrap mpfr-wrap">
		<h1 class="wp-heading-inline" id="mpfr-title"><?php esc_html_e( 'Retry Failed Emails', 'mpfr' ); ?></h1>
		<button type="button" class="page-title-action" id="mpfr-refresh">
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<?php esc_html_e( 'Refresh', 'mpfr' ); ?>
		</button>
		<hr class="wp-header-end" />

		<p class="description" id="mpfr-subtitle">
			<?php esc_html_e( 'Campaigns with failed emails or stuck chunks. Click "Retry Failed Emails" to resend each failed recipient through your active ESP, or "Reset Chunks" to re-queue failed/retry/processing chunks for the MailerPress worker.', 'mpfr' ); ?>
		</p>

		<div id="mpfr-status" class="mpfr-status" hidden></div>

		<table class="widefat striped mpfr-table" id="mpfr-table">
			<thead>
				<tr>
					<th scope="col" class="mpfr-col-campaign"><?php esc_html_e( 'Campaign', 'mpfr' ); ?></th>
					<th scope="col" class="mpfr-col-status"><?php esc_html_e( 'Status', 'mpfr' ); ?></th>
					<th scope="col" class="mpfr-col-num"><?php esc_html_e( 'Total', 'mpfr' ); ?></th>
					<th scope="col" class="mpfr-col-num"><?php esc_html_e( 'Sent', 'mpfr' ); ?></th>
					<th scope="col" class="mpfr-col-num"><?php esc_html_e( 'Errors', 'mpfr' ); ?></th>
					<th scope="col" class="mpfr-col-num"><?php esc_html_e( 'Failed emails', 'mpfr' ); ?></th>
					<th scope="col" class="mpfr-col-num"><?php esc_html_e( 'Failed chunks', 'mpfr' ); ?></th>
					<th scope="col" class="mpfr-col-actions"><?php esc_html_e( 'Actions', 'mpfr' ); ?></th>
				</tr>
			</thead>
			<tbody id="mpfr-tbody">
				<tr>
					<td colspan="8" class="mpfr-loading"><?php esc_html_e( 'Loading…', 'mpfr' ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
	<?php
}

/**
 * --------------------------------------------------------------------------
 * Optional admin notice on MailerPress pages when there are failed campaigns
 * --------------------------------------------------------------------------
 */
function mpfr_maybe_render_failed_campaigns_notice() {
	if ( ! current_user_can( MPFR_CAPABILITY ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return;
	}
	// Show only on MailerPress screens.
	if ( strpos( $screen->id, 'mailerpress' ) === false && strpos( $screen->id, 'toplevel_page_mailerpress' ) === false ) {
		return;
	}
	// Don't show on our own page to avoid loops.
	if ( strpos( $screen->id, MPFR_MENU_SLUG ) !== false ) {
		return;
	}

	$counts = mpfr_get_failed_counts();
	if ( empty( $counts['failed_campaigns'] ) ) {
		return;
	}

	$url = admin_url( 'admin.php?page=' . MPFR_MENU_SLUG );
	?>
	<div class="notice notice-warning is-dismissible">
		<p>
			<strong><?php esc_html_e( 'MailerPress Failed Resender:', 'mpfr' ); ?></strong>
			<?php
			printf(
				/* translators: 1: number of campaigns with errors, 2: total failed emails */
				esc_html__( ' %1$d campaign(s) have failed emails (%2$d total). ', 'mpfr' ),
				(int) $counts['failed_campaigns'],
				(int) $counts['failed_emails']
			);
			?>
			<a href="<?php echo esc_url( $url ); ?>" class="button button-small">
				<?php esc_html_e( 'Open Retry Failed', 'mpfr' ); ?>
			</a>
		</p>
	</div>
	<?php
}

/**
 * --------------------------------------------------------------------------
 * Direct DB helpers (used by the admin notice and WP-CLI).
 *
 * These read the same MailerPress tables the REST API uses so we don't
 * require the MailerPress REST nonce when invoked server side.
 * --------------------------------------------------------------------------
 */
function mpfr_get_failed_counts() {
	global $wpdb;

	$batches_table  = $wpdb->prefix . 'mailerpress_email_batches';
	$campaigns_table = $wpdb->prefix . 'mailerpress_campaigns';
	$logs_table     = $wpdb->prefix . 'mailerpress_email_logs';

	$row = $wpdb->get_row(
		"SELECT
			COUNT(*) AS failed_campaigns,
			COALESCE(SUM(error_emails), 0) AS failed_emails
		 FROM {$batches_table}
		 WHERE error_emails > 0"
	);

	$failed_logs = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$logs_table} WHERE status = 'error'"
	);

	return array(
		'failed_campaigns' => (int) ( $row->failed_campaigns ?? 0 ),
		'failed_emails'    => max( (int) ( $row->failed_emails ?? 0 ), $failed_logs ),
	);
}

/**
 * Returns the list of campaigns with at least one failed email, joined with
 * their batch row, ordered by the most recent failed first.
 *
 * Mirrors the join used in src/Api/Campaigns.php::response().
 *
 * @param int $limit
 * @param int $offset
 * @return array<int,array<string,mixed>>
 */
function mpfr_get_failed_campaigns( $limit = 100, $offset = 0 ) {
	global $wpdb;

	$campaigns_table = $wpdb->prefix . 'mailerpress_campaigns';
	$batches_table   = $wpdb->prefix . 'mailerpress_email_batches';
	$chunks_table    = $wpdb->prefix . 'mailerpress_email_chunks';

	$limit  = max( 1, (int) $limit );
	$offset = max( 0, (int) $offset );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT
				c.campaign_id AS id,
				c.name        AS title,
				c.subject     AS subject,
				c.status      AS status,
				c.batch_id    AS batch_id,
				c.updated_at  AS updated_at,
				b.id          AS batch_pk,
				b.status      AS batch_status,
				b.total_emails,
				b.sent_emails,
				b.error_emails,
				b.error_message AS batch_error_message,
				(SELECT COUNT(*) FROM {$chunks_table} WHERE batch_id = b.id AND status = 'failed')  AS failed_chunks,
				(SELECT COUNT(*) FROM {$chunks_table} WHERE batch_id = b.id AND status = 'retry')   AS retry_chunks,
				(SELECT COUNT(*) FROM {$chunks_table} WHERE batch_id = b.id AND status = 'processing') AS processing_chunks
			FROM {$campaigns_table} c
			INNER JOIN {$batches_table} b ON c.batch_id = b.id
			WHERE b.error_emails > 0
			ORDER BY c.updated_at DESC
			LIMIT %d OFFSET %d",
			$limit,
			$offset
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Enrich each row with a live count of failed email logs (more accurate than
	// the cached batch error_emails counter, which is only updated on send).
	if ( is_array( $rows ) ) {
		$logs = $wpdb->prefix . 'mailerpress_email_logs';
		foreach ( $rows as &$row ) {
			$cid                                  = (int) $row['id'];
			$row['failed_email_logs']             = mpfr_count_failed_emails( $cid );
		}
		unset( $row );
	}

	return is_array( $rows ) ? $rows : array();
}

/**
 * Resets all failed/retry/processing chunks of a batch to pending.
 *
 * Mirrors src/Api/Recovery.php::retryBatch() exactly so the behavior is
 * consistent with the MailerPress REST endpoint.
 *
 * @param int $batch_id
 * @return array{retried:int,batch_id:int}
 */
function mpfr_reset_failed_chunks( $batch_id ) {
	global $wpdb;

	$batch_id  = (int) $batch_id;
	$chunks    = $wpdb->prefix . 'mailerpress_email_chunks';
	$batches   = $wpdb->prefix . 'mailerpress_email_batches';

	$batch = $wpdb->get_row(
		$wpdb->prepare( "SELECT id FROM {$batches} WHERE id = %d", $batch_id )
	);
	if ( ! $batch ) {
		return array( 'retried' => 0, 'batch_id' => $batch_id );
	}

	$failed_chunks = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id FROM {$chunks} WHERE batch_id = %d AND status IN ('failed', 'retry', 'processing')",
			$batch_id
		)
	);

	$retried = 0;
	foreach ( $failed_chunks as $chunk ) {
		$wpdb->update(
			$chunks,
			array(
				'status'        => 'pending',
				'retry_count'   => 0,
				'error_message' => null,
				'scheduled_at'  => gmdate( 'Y-m-d H:i:s', time() + 60 ),
				'started_at'    => null,
			),
			array( 'id' => (int) $chunk->id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);
		++$retried;
	}

	// If anything was rescheduled, kick the chunk worker so it picks them up.
	if ( $retried > 0 && function_exists( 'as_schedule_recurring_action' )
		&& function_exists( 'as_next_scheduled_action' )
		&& ! as_next_scheduled_action( 'mailerpress_process_pending_chunks', array(), 'mailerpress' )
	) {
		as_schedule_recurring_action(
			time() + 60,
			MINUTE_IN_SECONDS,
			'mailerpress_process_pending_chunks',
			array(),
			'mailerpress'
		);
	}

	/**
	 * Fires after MPFR has reset failed chunks for a batch.
	 *
	 * @param int $batch_id
	 * @param int $retried
	 */
	do_action( 'mpfr_chunks_retried', $batch_id, $retried );

	return array(
		'retried'  => $retried,
		'batch_id' => $batch_id,
	);
}

/**
 * Hard-reset a batch (zeros counters and resets all chunks).
 *
 * Mirrors src/Api/Recovery.php::resetBatch().
 *
 * @param int $batch_id
 * @return array{retried:int,batch_id:int}
 */
function mpfr_reset_batch_full( $batch_id ) {
	global $wpdb;

	$batch_id = (int) $batch_id;
	$chunks   = $wpdb->prefix . 'mailerpress_email_chunks';
	$batches  = $wpdb->prefix . 'mailerpress_email_batches';

	$batch = $wpdb->get_row(
		$wpdb->prepare( "SELECT id FROM {$batches} WHERE id = %d", $batch_id )
	);
	if ( ! $batch ) {
		return array( 'retried' => 0, 'batch_id' => $batch_id );
	}

	$wpdb->update(
		$batches,
		array(
			'status'      => 'in_progress',
			'sent_emails' => 0,
			'error_emails'=> 0,
			'updated_at'  => current_time( 'mysql' ),
		),
		array( 'id' => $batch_id ),
		array( '%s', '%d', '%d', '%s' ),
		array( '%d' )
	);

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$chunks}
			 SET status = 'pending',
			     retry_count = 0,
			     error_message = NULL,
			     started_at = NULL,
			     completed_at = NULL,
			     scheduled_at = %s
			 WHERE batch_id = %d",
			gmdate( 'Y-m-d H:i:s', time() + 60 ),
			$batch_id
		)
	);

	$retried = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$chunks} WHERE batch_id = %d", $batch_id )
	);

	if ( $retried > 0 && function_exists( 'as_schedule_recurring_action' )
		&& function_exists( 'as_next_scheduled_action' )
		&& ! as_next_scheduled_action( 'mailerpress_process_pending_chunks', array(), 'mailerpress' )
	) {
		as_schedule_recurring_action(
			time() + 60,
			MINUTE_IN_SECONDS,
			'mailerpress_process_pending_chunks',
			array(),
			'mailerpress'
		);
	}

	do_action( 'mpfr_batch_reset', $batch_id, $retried );

	return array(
		'retried'  => $retried,
		'batch_id' => $batch_id,
	);
}

/**
 * --------------------------------------------------------------------------
 * Resending the actual failed emails (per-recipient, from email_logs).
 *
 * This is the second flow the user asked for: when the MailerPress chunk
 * is already `completed` but some recipients failed (logged in
 * `mailerpress_email_logs.status = 'error'`), the chunk-level reset does
 * nothing. We must build the body + per-recipient variables ourselves
 * and call the active ESP for each failed contact.
 *
 * Mirrors the recipient-merge logic of
 * MailerPress\Actions\ActionScheduler\Processors\ContactEmailChunk
 * ::processStandardEmailChunk() — same HtmlParser::init()->replaceVariables()
 * pipeline, same variable map (UNSUB_LINK, MANAGE_SUB_LINK, TRACK_OPEN,
 * CONTACT_ID, CAMPAIGN_ID, custom fields, …).
 * --------------------------------------------------------------------------
 */

/**
 * Count the failed email logs for a campaign.
 *
 * @param int $campaign_id
 * @return int
 */
function mpfr_count_failed_emails( $campaign_id ) {
	global $wpdb;
	$logs = $wpdb->prefix . 'mailerpress_email_logs';
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$logs} WHERE campaign_id = %d AND status = 'error'",
			(int) $campaign_id
		)
	);
}

/**
 * Get the full list of distinct failed contacts for a campaign.
 *
 * Returns rows with: contact_id, to_email, log_id, error_message, batch_id.
 *
 * @param int $campaign_id
 * @return array
 */
function mpfr_get_failed_contacts( $campaign_id ) {
	global $wpdb;
	$logs = $wpdb->prefix . 'mailerpress_email_logs';
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id AS log_id, contact_id, to_email, batch_id, error_message
			 FROM {$logs}
			 WHERE campaign_id = %d AND status = 'error'
			 ORDER BY id ASC",
			(int) $campaign_id
		),
		ARRAY_A
	);
}

/**
 * Resolve the email body for a campaign. Tries (in order):
 *  1. The transient `mailerpress_batch_{id}_html_processed`.
 *  2. The option `mailerpress_batch_{id}_html` (the saved sanitized HTML).
 *  3. The campaign's `content_html` field, JSON-decoded (block editor data).
 *
 * @param int $campaign_id
 * @return string
 */
function mpfr_get_campaign_html( $campaign_id ) {
	$campaign_id = (int) $campaign_id;
	if ( ! $campaign_id ) {
		return '';
	}

	// 1) Batch-level transient (used during active sending).
	$batch_html = get_transient( 'mailerpress_batch_' . $campaign_id . '_html_processed' );
	if ( is_string( $batch_html ) && $batch_html !== '' ) {
		return $batch_html;
	}

	// 2) Saved HTML option.
	$opt_html = get_option( 'mailerpress_batch_' . $campaign_id . '_html', '' );
	if ( is_string( $opt_html ) && $opt_html !== '' && ! str_starts_with( ltrim( $opt_html ), '{' ) ) {
		return $opt_html;
	}

	// 3) Fallback to campaign row content_html (may be JSON block tree).
	global $wpdb;
	$campaigns = $wpdb->prefix . 'mailerpress_campaigns';
	$row       = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT content_html FROM {$campaigns} WHERE campaign_id = %d",
			$campaign_id
		)
	);
	if ( ! $row || empty( $row->content_html ) ) {
		return '';
	}

	$decoded = json_decode( $row->content_html, true );
	if ( is_array( $decoded ) && isset( $decoded['html'] ) && is_string( $decoded['html'] ) ) {
		return $decoded['html'];
	}
	if ( is_string( $decoded ) ) {
		return $decoded;
	}
	return (string) $row->content_html;
}

/**
 * Build the merge-tag variables for one contact, identical to what
 * MailerPress's ContactEmailChunk does at send time.
 *
 * @param object $contact  Contact row (must have contact_id, email, first_name, last_name, unsubscribe_token, access_token).
 * @param int    $campaign_id
 * @param int    $batch_id
 * @param string $click_tracking 'yes' | 'no' | 'anonymously'
 * @return array
 */
function mpfr_build_contact_variables( $contact, $campaign_id, $batch_id, $click_tracking = 'yes' ) {
	$unsub_page = function_exists( 'mailerpress_get_page' ) ? mailerpress_get_page( 'unsub_page' ) : '';
	$manage_page = function_exists( 'mailerpress_get_page' ) ? mailerpress_get_page( 'manage_page' ) : '';

	$vars = array(
		'TRACK_CLICK'        => home_url( '/' ),
		'CONTACT_ID'         => (int) $contact->contact_id,
		'CAMPAIGN_ID'        => (int) $campaign_id,
		'UNSUB_LINK'         => wp_unslash( sprintf(
			'%s&data=%s&cid=%s&batchId=%s',
			$unsub_page,
			esc_attr( $contact->unsubscribe_token ),
			esc_attr( $contact->access_token ),
			(int) $batch_id
		) ),
		'MANAGE_SUB_LINK'    => wp_unslash( sprintf(
			'%s&cid=%s',
			$manage_page,
			esc_attr( $contact->access_token )
		) ),
		'CONTACT_NAME'       => esc_html( $contact->first_name ) . ' ' . esc_html( $contact->last_name ),
		'contact_name'       => sprintf( '%s %s', esc_html( $contact->first_name ), esc_html( $contact->last_name ) ),
		'contact_email'      => sprintf( '%s', esc_html( $contact->email ) ),
		'contact_first_name' => sprintf( '%s', esc_html( $contact->first_name ) ),
		'contact_last_name'  => sprintf( '%s', esc_html( $contact->last_name ) ),
	);

	// TRACK_OPEN pixel.
	if ( class_exists( '\\MailerPress\\Core\\HtmlParser' )
		&& method_exists( '\\MailerPress\\Core\\HtmlParser', 'generateTrackOpenUrl' )
	) {
		$vars['TRACK_OPEN'] = \MailerPress\Core\HtmlParser::generateTrackOpenUrl(
			(int) $contact->contact_id,
			(int) $campaign_id,
			(int) $batch_id
		);
	}

	// Campaign online version URL.
	if ( class_exists( '\\MailerPress\\Actions\\Shortcodes\\CampaignEmail' )
		&& method_exists( '\\MailerPress\\Actions\\Shortcodes\\CampaignEmail', 'getPublicUrl' )
	) {
		$vars['campaign_online_url'] = \MailerPress\Actions\Shortcodes\CampaignEmail::getPublicUrl( (int) $campaign_id );
	}

	// Custom fields for this contact.
	global $wpdb;
	$custom_table = $wpdb->prefix . 'mailerpress_contact_custom_fields';
	$custom_rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT field_key, field_value FROM {$custom_table} WHERE contact_id = %d",
			(int) $contact->contact_id
		)
	);
	if ( $custom_rows ) {
		foreach ( $custom_rows as $cf ) {
			$vars[ $cf->field_key ] = esc_html( $cf->field_value ?? '' );
		}
	}

	return $vars;
}

/**
 * Render the per-recipient HTML body using MailerPress's HtmlParser when
 * available; otherwise do a minimal variable substitution ourselves.
 *
 * @param string $html
 * @param array  $variables
 * @param string $click_tracking
 * @return string
 */
function mpfr_render_body( $html, $variables, $click_tracking = 'yes' ) {
	if ( $html === '' ) {
		return '';
	}
	if ( class_exists( '\\MailerPress\\Core\\HtmlParser' ) ) {
		try {
			$parser = new \MailerPress\Core\HtmlParser();
			if ( method_exists( $parser, 'preprocessBody' ) && method_exists( $parser, 'init' ) ) {
				$html = \MailerPress\Core\HtmlParser::preprocessBody( $html );
			}
			if ( method_exists( $parser, 'init' ) && method_exists( $parser, 'replaceVariables' ) ) {
				return $parser->init( $html, $variables )->replaceVariables( $click_tracking );
			}
		} catch ( \Throwable $e ) {
			// fall through to manual replacement
		}
	}
	// Manual fallback: replace {{KEY}} occurrences.
	return str_replace(
		array_map(
			static function ( $k ) { return '{{' . $k . '}}'; },
			array_keys( $variables )
		),
		array_values( $variables ),
		$html
	);
}

/**
 * --------------------------------------------------------------------------
 * Progress tracking (for background / live-progress flow)
 *
 * Progress is stored in a transient `mpfr_progress_{$campaign_id}` so the
 * REST endpoint can poll it cheaply. Each AS batch updates the transient
 * in place. A separate `cancelled` flag is also stored under the same key.
 * --------------------------------------------------------------------------
 */

function mpfr_progress_key( $campaign_id ) {
	return 'mpfr_progress_' . (int) $campaign_id;
}

function mpfr_get_progress( $campaign_id ) {
	$raw = get_transient( mpfr_progress_key( $campaign_id ) );
	if ( ! is_array( $raw ) ) {
		return null;
	}
	return wp_parse_args(
		$raw,
		array(
			'campaign_id'   => (int) $campaign_id,
			'status'        => 'idle',
			'total'         => 0,
			'sent'          => 0,
			'skipped'       => 0,
			'failed'        => 0,
			'remaining'     => 0,
			'next_offset'   => 0,
			'batch_size'    => 50,
			'batches_done'  => 0,
			'started_at'    => '',
			'updated_at'    => '',
			'eta_seconds'   => 0,
			'error_message' => '',
		)
	);
}

function mpfr_update_progress( $campaign_id, array $updates ) {
	$current = mpfr_get_progress( $campaign_id );
	if ( ! is_array( $current ) ) {
		$current = array(
			'campaign_id' => (int) $campaign_id,
			'status'      => 'idle',
		);
	}
	$merged                 = array_merge( $current, $updates );
	$merged['updated_at']   = current_time( 'mysql' );
	$merged['campaign_id']  = (int) $campaign_id;

	// Recompute ETA: avg ms per email * remaining.
	$processed = (int) $merged['sent'] + (int) $merged['skipped'] + (int) $merged['failed'];
	if ( $merged['started_at'] && $processed > 0 ) {
		$start_ts  = strtotime( (string) $merged['started_at'] . ' UTC' );
		$now_ts    = time();
		$elapsed   = max( 1, $now_ts - $start_ts );
		$avg       = $elapsed / $processed;
		$remaining = (int) $merged['remaining'];
		$merged['eta_seconds'] = (int) round( $avg * $remaining );
	}

	set_transient( mpfr_progress_key( $campaign_id ), $merged, HOUR_IN_SECONDS );
	return $merged;
}

function mpfr_clear_progress( $campaign_id ) {
	delete_transient( mpfr_progress_key( $campaign_id ) );
}

/**
 * Compute the total initial count of failed logs for a campaign (used as
 * the denominator in the progress bar).
 */
function mpfr_total_failed_for_campaign( $campaign_id ) {
	global $wpdb;
	$logs = $wpdb->prefix . 'mailerpress_email_logs';
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$logs} WHERE campaign_id = %d AND status = 'error'",
			(int) $campaign_id
		)
	);
}

/**
 * Resend every failed email for a campaign using the active ESP.
 *
 * Backwards-compatible synchronous entry point. For long-running resends
 * use mpfr_enqueue_resend_campaign() instead so the work runs in the
 * background with progress tracking.
 *
 * @param int $campaign_id
 * @param int $limit  Max recipients to resend in this run (default 100, can be raised).
 * @param int $offset Offset into the deduped failed-contact list.
 * @return array{resent:int,failed:int,skipped:int,remaining:int,total:int}
 */
function mpfr_resend_failed_emails( $campaign_id, $limit = 100, $offset = 0 ) {
	global $wpdb;

	$campaign_id = (int) $campaign_id;
	$campaigns   = $wpdb->prefix . 'mailerpress_campaigns';
	$contacts    = $wpdb->prefix . 'mailerpress_contact';
	$batches     = $wpdb->prefix . 'mailerpress_email_batches';
	$logs_table  = $wpdb->prefix . 'mailerpress_email_logs';

	// Load the campaign + its latest batch.
	$campaign = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT c.campaign_id, c.name, c.subject, c.batch_id, c.user_id,
			        b.id AS batch_pk, b.sender_name, b.sender_to
			 FROM {$campaigns} c
			 LEFT JOIN {$batches} b ON c.batch_id = b.id
			 WHERE c.campaign_id = %d",
			$campaign_id
		)
	);
	if ( ! $campaign ) {
		return array(
			'resent'    => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'remaining' => 0,
			'total'     => 0,
			'error'     => __( 'Campaign not found.', 'mpfr' ),
		);
	}

	$batch_id   = (int) $campaign->batch_pk;
	$subject    = (string) $campaign->subject;
	$sender_to  = (string) $campaign->sender_to;
	$sender_nm  = (string) $campaign->sender_name;

	// Resolve sender defaults from global settings if missing.
	$default_settings = get_option( 'mailerpress_default_settings', array() );
	if ( is_string( $default_settings ) ) {
		$default_settings = json_decode( $default_settings, true ) ?: array();
	}
	if ( $sender_to === '' ) {
		$sender_to = $default_settings['fromAddress'] ?? ( $default_settings['fromTo'] ?? get_option( 'admin_email' ) );
	}
	if ( $sender_nm === '' ) {
		$sender_nm = $default_settings['fromName'] ?? ( $default_settings['fromName'] ?? get_bloginfo( 'name' ) );
	}

	// Reply-To settings.
	$reply_to_name    = ! empty( $default_settings['replyToName'] ) ? $default_settings['replyToName'] : $sender_nm;
	$reply_to_address = ! empty( $default_settings['replyToAddress'] ) ? $default_settings['replyToAddress'] : $sender_to;

	// API key for the active ESP.
	$services = get_option( 'mailerpress_email_services', array() );
	$esp_key  = $services['default_service'] ?? 'php';
	$api_key  = $services['services'][ $esp_key ]['conf']['api_key']
		?? ( $services['services'][ $esp_key ]['conf']['apiKey'] ?? '' );

	// Rate limit (emails per second) — read the same option + structure
	// as MailerPress\Core\Jobs\SendEmailJob so the resend honours the
	// configured frequency and doesn't get the account throttled.
	$rate_limit = 10; // safe default
	$frequency_sending = get_option(
		'mailerpress_frequency_sending',
		array(
			'settings' => array(
				'numberEmail' => 25,
				'config'      => array( 'value' => 5, 'unit' => 'minutes' ),
				'rate_limit'  => 10,
			),
		)
	);
	if ( is_string( $frequency_sending ) ) {
		$frequency_sending = json_decode( $frequency_sending, true ) ?: array();
	}
	if ( isset( $frequency_sending['effectiveConfig']['rate_limit'] ) ) {
		$rate_limit = (int) $frequency_sending['effectiveConfig']['rate_limit'];
	} elseif ( isset( $frequency_sending['settings']['rate_limit'] ) ) {
		$rate_limit = (int) $frequency_sending['settings']['rate_limit'];
	}
	$rate_limit          = max( 0, $rate_limit );
	$delay_between_us    = $rate_limit > 0 ? (int) floor( 1_000_000 / $rate_limit ) : 0;
	$send_index_in_batch = 0;
	$total_in_batch      = 0;

	// Get HTML body.
	$html = mpfr_get_campaign_html( $campaign_id );
	if ( $html === '' ) {
		return array(
			'resent'    => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'remaining' => 0,
			'total'     => 0,
			'error'     => __( 'No HTML body for this campaign (was the campaign ever saved/sent?).', 'mpfr' ),
		);
	}

	// Collect failed log rows.
	$failed_rows = mpfr_get_failed_contacts( $campaign_id );
	$total_logs  = count( $failed_rows );
	if ( ! $failed_rows ) {
		return array(
			'resent'    => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'remaining' => 0,
			'total'     => 0,
		);
	}

	// Deduplicate by contact_id (the same contact may have multiple error logs).
	$unique_by_contact = array();
	foreach ( $failed_rows as $row ) {
		$cid = (int) $row['contact_id'];
		if ( $cid > 0 && ! isset( $unique_by_contact[ $cid ] ) ) {
			$unique_by_contact[ $cid ] = $row;
		} elseif ( $cid === 0 && ! isset( $unique_by_contact[ $row['to_email'] ] ) ) {
			// Anonymous: dedupe by email.
			$unique_by_contact[ $row['to_email'] ] = $row;
		}
	}

	// Resolve active email service.
	$mailer = null;
	if ( function_exists( 'mailerpress_get_provider_class' ) ) {
		try {
			$manager = mailerpress_get_provider_class();
			$mailer  = $manager->getActiveService();
		} catch ( \Throwable $e ) {
			$mailer = null;
		}
	}
	if ( ! $mailer ) {
		return array(
			'resent'    => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'remaining' => $total_logs,
			'total'     => $total_logs,
			'error'     => __( 'No active MailerPress email service is configured.', 'mpfr' ),
		);
	}

	$resent  = 0;
	$failed  = 0;
	$skipped = 0;
	$log_ids_resent = array();
	$processed      = 0;
	$open_tracking  = 'yes';
	$click_tracking = 'yes';

	// Load custom fields for the affected contacts in one query.
	$contact_ids = array_values(
		array_filter(
			array_map(
				static function ( $r ) { return (int) $r['contact_id']; },
				$unique_by_contact
			)
		)
	);

	// Apply offset + limit to the deduped list (preserves keys for batching).
	if ( $offset > 0 || $limit > 0 ) {
		$unique_by_contact = array_slice(
			$unique_by_contact,
			max( 0, (int) $offset ),
			$limit > 0 ? (int) $limit : null,
			true
		);
	}

	foreach ( $unique_by_contact as $key => $failed_row ) {
		if ( $processed >= (int) $limit ) {
			break;
		}
		$processed++;

		$contact_id = (int) $failed_row['contact_id'];
		$to_email   = (string) $failed_row['to_email'];

		// Load contact (skip if missing or unsubscribed/bounced).
		$contact = null;
		if ( $contact_id > 0 ) {
			$contact = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$contacts} WHERE contact_id = %d",
					$contact_id
				)
			);
		}
		if ( ! $contact ) {
			// Build a synthetic contact from the log row.
			$contact = (object) array(
				'contact_id'        => $contact_id,
				'email'             => $to_email,
				'first_name'        => '',
				'last_name'         => '',
				'unsubscribe_token' => '',
				'access_token'      => '',
				'subscription_status' => '',
			);
		}

		// Skip unsubscribed / bounced / complained contacts.
		$blocked_statuses = array( 'unsubscribed', 'bounced', 'complained' );
		$is_blocked       = isset( $contact->subscription_status ) && in_array( $contact->subscription_status, $blocked_statuses, true );
		if ( $is_blocked ) {
			$skipped++;
			// Mark ALL error logs for this contact as 'skipped' so the
			// `WHERE status='error'` count naturally decreases and the
			// background loop terminates.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$logs_table}
					 SET status = 'skipped',
					     error_message = CONCAT('skipped: ', %s)
					 WHERE campaign_id = %d
					   AND status = 'error'
					   AND ( contact_id = %d OR ( contact_id = 0 AND to_email = %s ) )",
					$contact->subscription_status,
					$campaign_id,
					$contact_id,
					$to_email
				)
			);
		} else {
			// Build merge variables + render body.
			$variables = mpfr_build_contact_variables( $contact, $campaign_id, $batch_id, $click_tracking );
			$body      = mpfr_render_body( $html, $variables, $click_tracking );

			// Send.
			$result = false;
			try {
				$result = $mailer->sendEmail(
					array(
						'to'               => $to_email,
						'html'             => true,
						'body'             => $body,
						'subject'          => $subject,
						'sender_name'      => $sender_nm,
						'sender_to'        => $sender_to,
						'reply_to_name'    => $reply_to_name,
						'reply_to_address' => $reply_to_address,
						'apiKey'           => $api_key,
						'campaign_id'      => $campaign_id,
						'contact_id'       => $contact_id,
						'batch_id'         => $batch_id,
					)
				);
			} catch ( \Throwable $e ) {
				$result = new \WP_Error( 'mpfr_send_exception', $e->getMessage() );
			}

			$is_error = ( $result instanceof \WP_Error ) || ( $result === false );

			if ( $is_error ) {
				$failed++;
				$err_msg = $result instanceof \WP_Error ? $result->get_error_message() : 'sendEmail returned false';
				$wpdb->update(
					$logs_table,
					array( 'error_message' => 'resend failed: ' . $err_msg ),
					array( 'id' => (int) $failed_row['log_id'] ),
					array( '%s' ),
					array( '%d' )
				);
			} else {
				// Success: mark all error logs for this contact as resolved.
				$resent++;
				$log_ids_resent[] = (int) $failed_row['log_id'];
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$logs_table}
						 SET status = 'success',
						     error_message = CONCAT('resent via MPFR on ', %s),
						     sent_at = %s
						 WHERE campaign_id = %d
						   AND status = 'error'
						   AND ( contact_id = %d OR ( contact_id = 0 AND to_email = %s ) )",
						current_time( 'mysql' ),
						current_time( 'mysql' ),
						$campaign_id,
						$contact_id,
						$to_email
					)
				);
			}
		}

		// Rate-limit delay between sends (skip after the very last contact
		// in the batch). Mirrors MailerPress\Jobs\SendEmailJob so we
		// don't trip ESP throttling.
		++$send_index_in_batch;
		if ( $delay_between_us > 0 && $send_index_in_batch < count( $unique_by_contact ) ) {
			usleep( $delay_between_us );
		}
	}

	// Decrement the batch's error_emails counter by the success count, increase sent_emails.
	if ( $resent > 0 && $batch_id ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$batches}
				 SET sent_emails  = COALESCE(sent_emails, 0) + %d,
				     error_emails = GREATEST(COALESCE(error_emails, 0) - %d, 0),
				     updated_at   = %s
				 WHERE id = %d",
				$resent,
				$resent,
				current_time( 'mysql' ),
				$batch_id
			)
		);
	}

	$remaining = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$logs_table} WHERE campaign_id = %d AND status = 'error'",
			$campaign_id
		)
	);

	return array(
		'resent'    => $resent,
		'failed'    => $failed,
		'skipped'   => $skipped,
		'remaining' => $remaining,
		'total'     => $total_logs,
	);
}

/**
 * --------------------------------------------------------------------------
 * Background (live-progress) flow
 *
 * The user wants live progress while a resend runs. The synchronous
 * `mpfr_resend_failed_emails()` is fine for tiny queues but for a 200+
 * failure campaign it can hit PHP max_execution_time and the browser
 * sits there with a spinner.
 *
 * Instead we enqueue one Action Scheduler action per batch. Each batch
 * resends up to `batch_size` (default 50) contacts, updates the
 * progress transient, and re-enqueues itself if more remain. The JS
 * polls GET /resend-progress every 600ms to render a live progress
 * bar with counters + ETA + Cancel.
 *
 * Schedule: a single AS group "mpfr" is used so the user can see and
 * cancel all in-progress resends via Tools → Scheduled Actions.
 * --------------------------------------------------------------------------
 */

function mpfr_as_group() {
	return 'mpfr';
}

function mpfr_as_hook() {
	return 'mpfr_resend_batch';
}

/**
 * Enqueue the first batch of a resend for a campaign.
 *
 * Idempotent: if a run is already in progress, returns false so the
 * caller can surface "already running" to the user.
 *
 * @param int $campaign_id
 * @param int $batch_size
 * @return array{ok:bool, reason?:string, progress?:array}
 */
function mpfr_enqueue_resend_campaign( $campaign_id, $batch_size = 50 ) {
	$campaign_id  = (int) $campaign_id;
	$batch_size   = max( 1, min( 200, (int) $batch_size ) );

	if ( ! function_exists( 'as_enqueue_async_action' ) ) {
		return array(
			'ok'     => false,
			'reason' => __( 'Action Scheduler is not available.', 'mpfr' ),
		);
	}

	$current = mpfr_get_progress( $campaign_id );
	if ( is_array( $current ) && isset( $current['status'] ) && in_array( $current['status'], array( 'running', 'starting' ), true ) ) {
		return array(
			'ok'     => false,
			'reason' => __( 'A resend is already running for this campaign. Cancel it first.', 'mpfr' ),
		);
	}

	$total = mpfr_total_failed_for_campaign( $campaign_id );
	if ( $total <= 0 ) {
		// Nothing to do — record a quick "done" so the UI gets a final state.
		mpfr_update_progress(
			$campaign_id,
			array(
				'status'        => 'done',
				'total'         => 0,
				'sent'          => 0,
				'failed'        => 0,
				'skipped'       => 0,
				'remaining'     => 0,
				'next_offset'   => 0,
				'batch_size'    => $batch_size,
				'batches_done'  => 0,
				'started_at'    => current_time( 'mysql' ),
				'eta_seconds'   => 0,
				'error_message' => '',
			)
		);
		return array(
			'ok'        => true,
			'progress'  => mpfr_get_progress( $campaign_id ),
		);
	}

	mpfr_update_progress(
		$campaign_id,
		array(
			'status'        => 'starting',
			'total'         => $total,
			'sent'          => 0,
			'failed'        => 0,
			'skipped'       => 0,
			'remaining'     => $total,
			'next_offset'   => 0,
			'batch_size'    => $batch_size,
			'batches_done'  => 0,
			'started_at'    => current_time( 'mysql' ),
			'eta_seconds'   => 0,
			'error_message' => '',
		)
	);

	as_enqueue_async_action(
		mpfr_as_hook(),
		array(
			'campaign_id' => $campaign_id,
			'offset'      => 0,
			'limit'       => $batch_size,
		),
		mpfr_as_group()
	);

	// Kick the AS queue + WP-Cron so the queued action starts
	// processing within ~1s rather than waiting for the next
	// natural page load. spawn_cron() is non-blocking (uses
	// non-blocking HTTP request to wp-cron.php).
	if ( function_exists( 'spawn_cron' ) ) {
		spawn_cron();
	}
	if ( function_exists( 'ActionScheduler' ) && class_exists( '\\ActionScheduler_QueueRunner' ) ) {
		// Try the modern API: ActionScheduler::runner() then run().
		try {
			$runner = \ActionScheduler_QueueRunner::instance();
			if ( $runner ) {
				$runner->run();
			}
		} catch ( \Throwable $e ) {
			// ignore — the next cron run will pick it up
		}
	}

	return array(
		'ok'       => true,
		'progress' => mpfr_get_progress( $campaign_id ),
	);
}

/**
 * Action Scheduler callback: resend one batch and re-enqueue if more remain.
 *
 * We deliberately ignore the `$offset` argument and re-query the
 * remaining failed list at the start of each batch — as we mark
 * contacts success/skipped, they drop out of the
 * `status='error'` query, so the next batch naturally picks up where
 * this one left off. This is simpler and avoids any offset drift.
 *
 * Args (positional): [campaign_id, offset, limit]. Offset kept for
 * backwards-compat with already-scheduled actions.
 *
 * @param int $campaign_id
 * @param int $offset       Unused; kept for signature compatibility.
 * @param int $limit
 */
function mpfr_process_resend_batch( $campaign_id, $offset, $limit ) {
	$campaign_id = (int) $campaign_id;
	$offset      = max( 0, (int) $offset );
	$limit       = max( 1, min( 200, (int) $limit ) );

	// Cancellation check (set by /resend-cancel).
	$current = mpfr_get_progress( $campaign_id );
	if ( is_array( $current ) && isset( $current['status'] ) && $current['status'] === 'cancelled' ) {
		return;
	}

	// Mark as running.
	mpfr_update_progress( $campaign_id, array( 'status' => 'running' ) );

	try {
		$result = mpfr_resend_failed_emails( $campaign_id, $limit, 0 );
	} catch ( \Throwable $e ) {
		mpfr_update_progress(
			$campaign_id,
			array(
				'status'        => 'error',
				'error_message' => $e->getMessage(),
			)
		);
		return;
	}

	if ( ! empty( $result['error'] ) ) {
		$sentinel = is_array( $current ) ? (int) ( $current['sent'] ?? 0 ) : 0;
		// If we never managed to send one (e.g. no active service) and
		// the batch made no progress, mark the whole run as errored.
		if ( $sentinel === 0 && (int) ( $result['resent'] ?? 0 ) === 0 ) {
			mpfr_update_progress(
				$campaign_id,
				array(
					'status'        => 'error',
					'error_message' => $result['error'],
				)
			);
			return;
		}
		// Mid-run error: continue with whatever was already done.
	}

	$resent    = (int) ( $result['resent']  ?? 0 );
	$failed    = (int) ( $result['failed']  ?? 0 );
	$skipped   = (int) ( $result['skipped'] ?? 0 );
	$remaining = (int) ( $result['remaining'] ?? 0 );

	$updates = array(
		'sent'         => ( is_array( $current ) ? (int) ( $current['sent'] ?? 0 ) : 0 ) + $resent,
		'failed'       => ( is_array( $current ) ? (int) ( $current['failed']  ?? 0 ) : 0 ) + $failed,
		'skipped'      => ( is_array( $current ) ? (int) ( $current['skipped'] ?? 0 ) : 0 ) + $skipped,
		'remaining'    => $remaining,
		'batches_done' => ( is_array( $current ) ? (int) ( $current['batches_done'] ?? 0 ) : 0 ) + 1,
	);

	// Done? Mark and stop.
	if ( $remaining <= 0 ) {
		$updates['status']      = 'done';
		$updates['eta_seconds'] = 0;
		mpfr_update_progress( $campaign_id, $updates );
		return;
	}

	// No progress was made this batch (e.g. transient failure with the
	// ESP). Stop here so we don't burn CPU in a tight loop. The user
	// can click Retry again to resume.
	if ( ( $resent + $failed + $skipped ) === 0 ) {
		$updates['status']        = 'error';
		$updates['error_message'] = __( 'No progress in last batch; stopping. Click Retry again to resume.', 'mpfr' );
		mpfr_update_progress( $campaign_id, $updates );
		return;
	}

	// Re-enqueue next batch.
	$updates['status'] = 'running';
	mpfr_update_progress( $campaign_id, $updates );

	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action(
			mpfr_as_hook(),
			array(
				'campaign_id' => $campaign_id,
				'offset'      => 0,
				'limit'       => $limit,
			),
			mpfr_as_group()
		);
	} else {
		mpfr_update_progress(
			$campaign_id,
			array(
				'status'        => 'error',
				'error_message' => __( 'Action Scheduler disappeared mid-run.', 'mpfr' ),
			)
		);
	}
}

/**
 * Cancel an in-progress resend. Sets the cancelled flag and unschedules
 * any pending batches. The currently-executing batch (if any) will
 * finish naturally but the callback will see cancelled=true and not
 * re-enqueue.
 *
 * @param int $campaign_id
 * @return array{ok:bool, cancelled:bool}
 */
function mpfr_cancel_resend( $campaign_id ) {
	$campaign_id = (int) $campaign_id;
	$current     = mpfr_get_progress( $campaign_id );

	if ( ! is_array( $current ) || ! in_array( $current['status'] ?? '', array( 'running', 'starting' ), true ) ) {
		return array(
			'ok'        => true,
			'cancelled' => false,
		);
	}

	mpfr_update_progress(
		$campaign_id,
		array(
			'status'        => 'cancelled',
			'eta_seconds'   => 0,
		)
	);

	// Unschedule any pending batches in our group.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( mpfr_as_hook(), null, mpfr_as_group() );
	}

	return array(
		'ok'        => true,
		'cancelled' => true,
	);
}

/**
 * --------------------------------------------------------------------------
 * REST endpoints (server-side, under our own namespace so we don't depend
 * on MailerPress REST auth quirks).
 * --------------------------------------------------------------------------
 */
add_action( 'rest_api_init', 'mpfr_register_rest_routes' );

function mpfr_register_rest_routes() {
	register_rest_route(
		'mpfr/v1',
		'/failed-campaigns',
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( MPFR_CAPABILITY );
			},
			'args'                => array(
				'limit'  => array(
					'type'              => 'integer',
					'default'           => 100,
					'sanitize_callback' => 'absint',
				),
				'offset' => array(
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
			'callback'            => function ( \WP_REST_Request $request ) {
				$rows  = mpfr_get_failed_campaigns(
					(int) $request->get_param( 'limit' ),
					(int) $request->get_param( 'offset' )
				);
				$total = mpfr_get_failed_counts();
				return rest_ensure_response(
					array(
						'rows'  => $rows,
						'total' => $total,
					)
				);
			},
		)
	);

	register_rest_route(
		'mpfr/v1',
		'/batch/(?P<batch_id>\d+)/retry',
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( MPFR_CAPABILITY );
			},
			'args'                => array(
				'batch_id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
			'callback'            => function ( \WP_REST_Request $request ) {
				$batch_id = (int) $request->get_param( 'batch_id' );
				$result   = mpfr_reset_failed_chunks( $batch_id );
				return rest_ensure_response(
					array(
						'success' => true,
						'message' => sprintf(
							/* translators: %d: number of chunks rescheduled */
							__( '%d chunk(s) scheduled for retry.', 'mpfr' ),
							$result['retried']
						),
						'chunks_retried' => $result['retried'],
					)
				);
			},
		)
	);

	register_rest_route(
		'mpfr/v1',
		'/batch/(?P<batch_id>\d+)/reset',
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( MPFR_CAPABILITY );
			},
			'args'                => array(
				'batch_id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
			'callback'            => function ( \WP_REST_Request $request ) {
				$batch_id = (int) $request->get_param( 'batch_id' );
				$result   = mpfr_reset_batch_full( $batch_id );
				return rest_ensure_response(
					array(
						'success' => true,
						'message' => sprintf(
							/* translators: %d: number of chunks rescheduled */
							__( 'Batch reset: %d chunk(s) rescheduled.', 'mpfr' ),
							$result['retried']
						),
						'chunks_rescheduled' => $result['retried'],
					)
				);
			},
		)
	);

	register_rest_route(
		'mpfr/v1',
		'/campaign/(?P<campaign_id>\d+)/failed-count',
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( MPFR_CAPABILITY );
			},
			'args'                => array(
				'campaign_id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
			'callback'            => function ( \WP_REST_Request $request ) {
				$cid = (int) $request->get_param( 'campaign_id' );
				return rest_ensure_response(
					array(
						'campaign_id' => $cid,
						'failed'      => mpfr_count_failed_emails( $cid ),
					)
				);
			},
		)
	);

	register_rest_route(
		'mpfr/v1',
		'/campaign/(?P<campaign_id>\d+)/resend-failed',
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( MPFR_CAPABILITY );
			},
			'args'                => array(
				'campaign_id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'batch_size'  => array(
					'default'           => 50,
					'sanitize_callback' => 'absint',
				),
				'sync'        => array(
					'default' => false,
				),
			),
			'callback'            => function ( \WP_REST_Request $request ) {
				$cid        = (int) $request->get_param( 'campaign_id' );
				$batch_size = max( 1, min( 200, (int) $request->get_param( 'batch_size' ) ) );
				$sync       = (bool) $request->get_param( 'sync' );

				// Legacy synchronous mode (used by WP-CLI / debugging).
				if ( $sync ) {
					$limit  = max( 1, min( 500, (int) $request->get_param( 'limit' ) ) );
					$result = mpfr_resend_failed_emails( $cid, $limit );

					if ( ! empty( $result['error'] ) ) {
						return new \WP_Error( 'mpfr_resend_error', $result['error'], array( 'status' => 400 ) );
					}

					$message = sprintf(
						/* translators: 1: resent, 2: failed, 3: remaining */
						__( 'Resent: %1$d · Still failing: %2$d · Remaining in queue: %3$d', 'mpfr' ),
						(int) $result['resent'],
						(int) $result['failed'],
						(int) $result['remaining']
					);

					return rest_ensure_response(
						array(
							'success'   => true,
							'message'   => $message,
							'resent'    => (int) $result['resent'],
							'failed'    => (int) $result['failed'],
							'skipped'   => (int) $result['skipped'],
							'remaining' => (int) $result['remaining'],
							'total'     => (int) $result['total'],
						)
					);
				}

				// Default: enqueue background batches for live progress.
				$enqueue = mpfr_enqueue_resend_campaign( $cid, $batch_size );
				if ( empty( $enqueue['ok'] ) ) {
					return new \WP_Error(
						'mpfr_enqueue_failed',
						$enqueue['reason'] ?? __( 'Could not start the resend.', 'mpfr' ),
						array( 'status' => 400 )
					);
				}

				return rest_ensure_response(
					array(
						'success'     => true,
						'scheduled'   => true,
						'campaign_id' => $cid,
						'progress'    => $enqueue['progress'],
					)
				);
			},
		)
	);

	register_rest_route(
		'mpfr/v1',
		'/campaign/(?P<campaign_id>\d+)/resend-progress',
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( MPFR_CAPABILITY );
			},
			'args'                => array(
				'campaign_id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
			'callback'            => function ( \WP_REST_Request $request ) {
				$cid      = (int) $request->get_param( 'campaign_id' );
				$progress = mpfr_get_progress( $cid );
				$failed   = mpfr_total_failed_for_campaign( $cid );

				if ( ! is_array( $progress ) ) {
					// No run ever started — return a default idle state with the
					// current failed count so the UI can show a "0/N done" bar.
					return rest_ensure_response(
						array(
							'campaign_id'   => $cid,
							'status'        => 'idle',
							'total'         => $failed,
							'sent'          => 0,
							'failed'        => 0,
							'skipped'       => 0,
							'remaining'     => $failed,
							'next_offset'   => 0,
							'batch_size'    => 50,
							'batches_done'  => 0,
							'started_at'    => '',
							'updated_at'    => '',
							'eta_seconds'   => 0,
							'error_message' => '',
						)
					);
				}

				// Always reflect live remaining from the table.
				$progress['remaining'] = $failed;
				if ( $progress['status'] === 'done' ) {
					$progress['remaining'] = 0;
				}

				return rest_ensure_response( $progress );
			},
		)
	);

	register_rest_route(
		'mpfr/v1',
		'/campaign/(?P<campaign_id>\d+)/resend-cancel',
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( MPFR_CAPABILITY );
			},
			'args'                => array(
				'campaign_id' => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
			'callback'            => function ( \WP_REST_Request $request ) {
				$cid    = (int) $request->get_param( 'campaign_id' );
				$result = mpfr_cancel_resend( $cid );
				return rest_ensure_response(
					array(
						'success'   => true,
						'cancelled' => (bool) ( $result['cancelled'] ?? false ),
						'progress'  => mpfr_get_progress( $cid ),
					)
				);
			},
		)
	);

	register_rest_route(
		'mpfr/v1',
		'/user/hidden-campaigns',
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( MPFR_CAPABILITY );
			},
			'callback'            => function ( \WP_REST_Request $request ) {
				$user_id           = get_current_user_id();
				$hidden_campaigns  = get_user_meta( $user_id, 'mpfr_hidden_campaigns', true );
				if ( ! is_array( $hidden_campaigns ) ) {
					$hidden_campaigns = array();
				}
				return rest_ensure_response(
					array(
						'hidden_campaigns' => array_values( array_map( 'intval', $hidden_campaigns ) ),
						'count'            => count( $hidden_campaigns ),
					)
				);
			},
		)
	);

	register_rest_route(
		'mpfr/v1',
		'/user/hidden-campaigns',
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( MPFR_CAPABILITY );
			},
			'args'                => array(
				'action'       => array(
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
					'validate_callback' => function ( $param ) {
						return in_array( $param, array( 'add', 'remove', 'toggle' ), true );
					},
				),
				'campaign_id'  => array(
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
			),
			'callback'            => function ( \WP_REST_Request $request ) {
				$user_id      = get_current_user_id();
				$action       = $request->get_param( 'action' );
				$campaign_id  = (int) $request->get_param( 'campaign_id' );
				
				$hidden_campaigns = get_user_meta( $user_id, 'mpfr_hidden_campaigns', true );
				if ( ! is_array( $hidden_campaigns ) ) {
					$hidden_campaigns = array();
				}
				
				$index = array_search( $campaign_id, $hidden_campaigns, true );
				
				switch ( $action ) {
					case 'add':
						if ( $index === false ) {
							$hidden_campaigns[] = $campaign_id;
						}
						break;
						
					case 'remove':
						if ( $index !== false ) {
							unset( $hidden_campaigns[ $index ] );
							$hidden_campaigns = array_values( $hidden_campaigns );
						}
						break;
						
					case 'toggle':
						if ( $index !== false ) {
							unset( $hidden_campaigns[ $index ] );
							$hidden_campaigns = array_values( $hidden_campaigns );
						} else {
							$hidden_campaigns[] = $campaign_id;
						}
						break;
				}
				
				update_user_meta( $user_id, 'mpfr_hidden_campaigns', $hidden_campaigns );
				
				return rest_ensure_response(
					array(
						'success'          => true,
						'hidden_campaigns' => array_values( array_map( 'intval', $hidden_campaigns ) ),
						'count'            => count( $hidden_campaigns ),
					)
				);
			},
		)
	);
}

/**
 * --------------------------------------------------------------------------
 * WP-CLI command
 * --------------------------------------------------------------------------
 */
if ( ! class_exists( '\\MailerPressFailedResender\\CLI\\Retry_Failed_Command' ) ) {

	/**
	 * WP-CLI command to retry failed MailerPress batches from the shell.
	 *
	 * Examples:
	 *   wp mpfr retry-failed --list
	 *   wp mpfr retry-failed --batch=123
	 *   wp mpfr retry-failed --all
	 *   wp mpfr retry-failed --batch=123 --reset
	 */
	class Retry_Failed_Command {

		/**
		 * Invoke the command.
		 *
		 * ## OPTIONS
		 *
		 * [--list]
		 * : List campaigns with failed emails and exit.
		 *
		 * [--batch=<id>]
		 * : Retry a specific batch by primary key.
		 *
		 * [--all]
		 * : Retry every batch that has failed emails.
		 *
		 * [--reset]
		 * : Hard-reset the batch (zeros counters, marks all chunks pending).
		 *
		 * [--dry-run]
		 * : Print what would happen without making any changes.
		 *
		 * @param array $args
		 * @param array $assoc
		 */
		public function __invoke( $args, $assoc ) {
			$list   = ! empty( $assoc['list'] );
			$batch  = isset( $assoc['batch'] ) ? (int) $assoc['batch'] : 0;
			$all    = ! empty( $assoc['all'] );
			$reset  = ! empty( $assoc['reset'] );
			$dryrun = ! empty( $assoc['dry-run'] );

			if ( $list ) {
				$rows = mpfr_get_failed_campaigns( 200 );
				if ( empty( $rows ) ) {
					\WP_CLI::success( 'No campaigns with failed emails.' );
					return;
				}
				$format = "%-6s %-30s %-12s %-10s %-10s %-10s %-10s %-10s";
				\WP_CLI::line( sprintf(
					$format,
					'PK',
					'Title',
					'Status',
					'Total',
					'Sent',
					'Errors',
					'Failed',
					'Retry'
				) );
				foreach ( $rows as $r ) {
					\WP_CLI::line( sprintf(
						$format,
						(int) $r['batch_pk'],
						substr( (string) $r['title'], 0, 30 ),
						(string) $r['batch_status'],
						(int) $r['total_emails'],
						(int) $r['sent_emails'],
						(int) $r['error_emails'],
						(int) $r['failed_chunks'],
						(int) $r['retry_chunks']
					) );
				}
				return;
			}

			if ( ! $batch && ! $all ) {
				\WP_CLI::error( 'Provide --batch=<id> or --all. Use --list to see candidates.' );
			}

			$ids = array();
			if ( $batch ) {
				$ids[] = $batch;
			} else {
				$rows  = mpfr_get_failed_campaigns( 1000 );
				$ids   = array_map(
					static function ( $r ) {
						return (int) $r['batch_pk'];
					},
					$rows
				);
			}

			$total_retried = 0;
			foreach ( $ids as $id ) {
				if ( $dryrun ) {
					\WP_CLI::line( sprintf( '[dry-run] would %s batch %d', $reset ? 'reset' : 'retry', $id ) );
					continue;
				}
				$result   = $reset ? mpfr_reset_batch_full( $id ) : mpfr_reset_failed_chunks( $id );
				$total_retried += (int) $result['retried'];
				\WP_CLI::line( sprintf(
					'%s batch %d: %d chunk(s) rescheduled',
					$reset ? 'Reset' : 'Retried',
					$id,
					(int) $result['retried']
				) );
			}

			\WP_CLI::success( sprintf( 'Done. %d chunk(s) total rescheduled.', $total_retried ) );
		}
	}
}

/**
 * --------------------------------------------------------------------------
 * Inject a "Retry Failed" item into the per-row Actions dropdown of the
 * MailerPress React campaigns list.
 *
 * MailerPress renders a kebab popover per row inside the React SPA. The
 * popover is mounted via a React portal in <body> as a
 * `.components-popover` containing a `role="menu"` with items
 * (Delete, View statistics, View logs, Copy public link, Duplicate, Rename).
 *
 * We cannot modify the React app. Instead, we listen for clicks on the
 * kebab button, capture the campaign id from the row's "View" link, and
 * inject a new <button role="menuitem">"Retry Failed"</button> at the top
 * of the popover when it appears.
 * --------------------------------------------------------------------------
 */
function mpfr_inject_row_action_script() {
	// Only run on MailerPress admin screens (any sub-page of the SPA).
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return;
	}
	if ( strpos( $screen->id, 'mailerpress' ) === false
		&& strpos( $screen->id, 'toplevel_page_mailerpress' ) === false ) {
		return;
	}

	// Bail if the user can't manage.
	if ( ! current_user_can( MPFR_CAPABILITY ) ) {
		return;
	}

	$nonce = wp_create_nonce( 'wp_rest' );
	$rest  = esc_url_raw( rest_url( 'mpfr/v1/' ) );
	?>
<script id="mpfr-row-actions">
( function () {
	'use strict';

	if ( window.__mpfrRowActionsInit ) {
		return;
	}
	window.__mpfrRowActionsInit = true;

	var REST_BASE = <?php echo wp_json_encode( $rest ); ?>;
	var NONCE     = <?php echo wp_json_encode( $nonce ); ?>;

	// Map campaign_id -> batch_id resolved via our endpoint.
	var batchCache = Object.create( null );

	/**
	 * Resolve campaign_id from a row: parse the "View" link href.
	 *
	 * MailerPress view link format: https://site/mp-email/{id}-slug/
	 */
	function getCampaignIdFromRow( row ) {
		if ( ! row ) return 0;
		var links = row.querySelectorAll( 'a[href*="/mp-email/"]' );
		for ( var i = 0; i < links.length; i++ ) {
			var m = links[ i ].href.match( /\/mp-email\/(\d+)(?:-[^/]*)?\/?/ );
			if ( m ) return parseInt( m[ 1 ], 10 );
		}
		return 0;
	}

	/**
	 * Find the kebab button for a row (last button in the actions cell).
	 */
	function getKebabButton( row ) {
		var cells = row.querySelectorAll( 'td' );
		var last  = cells[ cells.length - 1 ];
		if ( ! last ) return null;
		var btns  = last.querySelectorAll( 'button.components-dropdown-menu__toggle' );
		return btns.length ? btns[ btns.length - 1 ] : null;
	}

	/**
	 * Find the table row that contains a given kebab button.
	 */
	function getRowFromKebab( kebab ) {
		var el = kebab;
		while ( el && el.tagName !== 'TR' ) {
			el = el.parentElement;
		}
		return el;
	}

	/**
	 * Resolve batch_id for a campaign via our REST endpoint.
	 */
	function resolveBatchId( campaignId ) {
		if ( batchCache[ campaignId ] ) {
			return Promise.resolve( batchCache[ campaignId ] );
		}
		var url = REST_BASE + 'failed-campaigns?limit=200';
		return fetch( url, { headers: { 'X-WP-Nonce': NONCE } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				var rows = ( j && j.rows ) || [];
				for ( var i = 0; i < rows.length; i++ ) {
					if ( parseInt( rows[ i ].id, 10 ) === campaignId ) {
						batchCache[ campaignId ] = parseInt( rows[ i ].batch_pk, 10 );
						return batchCache[ campaignId ];
					}
				}
				return 0;
			} );
	}

	/**
	 * Trigger the resend endpoint (per-recipient, by campaign_id) and
	 * start a polling loop for live progress.
	 */
	function runResend( campaignId, btn ) {
		var orig = btn.textContent;
		btn.disabled = true;
		btn.textContent = 'Starting…';
		fetch( REST_BASE + 'campaign/' + campaignId + '/resend-failed', {
			method: 'POST',
			headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
			body: JSON.stringify( { batch_size: 50 } )
		} )
			.then( function ( r ) {
				if ( ! r.ok ) {
					return r.json().then( function ( j ) {
						throw new Error( ( j && j.message ) || 'HTTP ' + r.status );
					} );
				}
				return r.json();
			} )
			.then( function ( j ) {
				delete batchCache[ campaignId ];
				if ( j && j.scheduled ) {
					pollProgress( campaignId, '' );
				} else {
					setTimeout( function () {
						notify( ( j && j.message ) || ( 'Resent: ' + ( j.resent || 0 ) + '.' ), 'success' );
					}, 50 );
				}
			} )
			.catch( function ( err ) {
				setTimeout( function () {
					notify( 'Resend failed: ' + ( err.message || err ), 'error' );
				}, 50 );
			} )
			.finally( function () {
				btn.disabled = false;
				btn.textContent = orig;
			} );
	}

	/**
	 * Polling loop for the kebab-injected flow. Mirrors admin.js behaviour.
	 */
	var pollers = Object.create( null );
	function pollProgress( campaignId, title ) {
		if ( pollers[ campaignId ] ) return; // already polling
		var stop = false;
		var pinged = 0;
		var tick = function () {
			if ( stop ) return;
			// Every 3rd tick, ping wp-cron to make sure Action Scheduler
			// picks up the queued batch (REST requests don't trigger cron).
			pinged++;
			if ( pinged % 3 === 0 ) {
				var cron = new Image();
				cron.src = '/wp-cron.php?doing_wp_cron=' + Date.now();
			}
			fetch( REST_BASE + 'campaign/' + campaignId + '/resend-progress', {
				headers: { 'X-WP-Nonce': NONCE }
			} )
				.then( function ( r ) { return r.json(); } )
				.then( function ( p ) {
					if ( stop ) return;
					if ( ! p || p.status === 'idle' ) return;
					ensureCard( campaignId, title );
					updateCard( campaignId, p );
					if ( p.status === 'done' || p.status === 'cancelled' || p.status === 'error' ) {
						stop = true;
						delete pollers[ campaignId ];
						setTimeout( function () { removeCard( campaignId ); }, 6000 );
						return;
					}
					setTimeout( tick, 600 );
				} )
				.catch( function () {
					if ( stop ) return;
					setTimeout( tick, 1500 );
				} );
		};
		pollers[ campaignId ] = function () { stop = true; };
		tick();
	}

	function cancelResend( campaignId ) {
		fetch( REST_BASE + 'campaign/' + campaignId + '/resend-cancel', {
			method: 'POST',
			headers: { 'X-WP-Nonce': NONCE }
		} );
		// The polling loop will pick up the cancelled status.
	}

	/**
	 * Show / update the floating progress card on document.body.
	 */
	function ensureCard( campaignId, title ) {
		var id = 'mpfr-progress-card-' + campaignId;
		var el = document.getElementById( id );
		if ( el ) return el;
		el = document.createElement( 'div' );
		el.id = id;
		el.className = 'mpfr-progress-card';
		el.setAttribute( 'data-campaign', String( campaignId ) );
		el.innerHTML = [
			'<div class="mpfr-progress-head">',
				'<span class="mpfr-progress-title"></span>',
				'<button type="button" class="mpfr-progress-close" aria-label="Close">×</button>',
			'</div>',
			'<div class="mpfr-progress-bar"><div class="mpfr-progress-fill" style="width:0%"></div></div>',
			'<div class="mpfr-progress-stats"></div>',
			'<div class="mpfr-progress-eta"></div>',
			'<div class="mpfr-progress-actions">',
				'<button type="button" class="button mpfr-progress-cancel">Cancel</button>',
			'</div>'
		].join( '' );
		el.querySelector( '.mpfr-progress-title' ).textContent = title
			? ( 'Resending campaign #' + campaignId + ' — ' + title )
			: ( 'Resending campaign #' + campaignId );
		document.body.appendChild( el );
		el.querySelector( '.mpfr-progress-close' ).addEventListener( 'click', function () {
			el.remove();
		} );
		el.querySelector( '.mpfr-progress-cancel' ).addEventListener( 'click', function () {
			cancelResend( campaignId );
		} );
		return el;
	}

	function progressPct( progress ) {
		var total = parseInt( progress.total, 10 ) || 0;
		if ( total <= 0 ) return 0;
		var sent     = parseInt( progress.sent,     10 ) || 0;
		var failed   = parseInt( progress.failed,   10 ) || 0;
		var skipped  = parseInt( progress.skipped,  10 ) || 0;
		return Math.min( 100, Math.round( ( ( sent + failed + skipped ) / total ) * 100 ) );
	}

	function formatEta( seconds ) {
		seconds = parseInt( seconds, 10 ) || 0;
		if ( seconds <= 0 ) return '—';
		if ( seconds < 60 ) return seconds + 's';
		var m = Math.floor( seconds / 60 );
		var s = seconds % 60;
		return m + 'm ' + s + 's';
	}

	function updateCard( campaignId, progress ) {
		var el = document.getElementById( 'mpfr-progress-card-' + campaignId );
		if ( ! el ) return;
		var status = progress.status || 'running';
		el.className = 'mpfr-progress-card mpfr-progress-card--' + status;

		el.querySelector( '.mpfr-progress-fill' ).style.width = progressPct( progress ) + '%';

		var total     = parseInt( progress.total,     10 ) || 0;
		var sent      = parseInt( progress.sent,      10 ) || 0;
		var failed    = parseInt( progress.failed,    10 ) || 0;
		var skipped   = parseInt( progress.skipped,   10 ) || 0;
		var remaining = parseInt( progress.remaining, 10 ) || 0;
		var eta       = parseInt( progress.eta_seconds, 10 ) || 0;

		el.querySelector( '.mpfr-progress-stats' ).innerHTML = [
			'<span>Total: <strong>', total, '</strong></span>',
			'<span style="color:#00a32a">Sent: <strong>', sent, '</strong></span>',
			( failed > 0 ? '<span style="color:#d63638">Failed: <strong>' + failed + '</strong></span>' : '' ),
			( skipped > 0 ? '<span style="color:#dba617">Skipped: <strong>' + skipped + '</strong></span>' : '' ),
			'<span>Remaining: <strong>', remaining, '</strong></span>'
		].join( '' );

		var etaEl = el.querySelector( '.mpfr-progress-eta' );
		if ( status === 'done' ) {
			etaEl.textContent = 'Done — ' + sent + ' resent';
			etaEl.style.color = '#00a32a';
		} else if ( status === 'cancelled' ) {
			etaEl.textContent = 'Cancelled — ' + sent + ' resent, ' + remaining + ' still queued';
			etaEl.style.color = '#dba617';
		} else if ( status === 'error' ) {
			etaEl.textContent = 'Error: ' + ( progress.error_message || 'unknown' );
			etaEl.style.color = '#d63638';
		} else {
			etaEl.textContent = 'ETA: ~' + formatEta( eta );
			etaEl.style.color = '';
		}

		var cancelBtn = el.querySelector( '.mpfr-progress-cancel' );
		var isTerminal = ( status === 'done' || status === 'cancelled' || status === 'error' );
		cancelBtn.style.display = isTerminal ? 'none' : '';
	}

	function removeCard( campaignId ) {
		var el = document.getElementById( 'mpfr-progress-card-' + campaignId );
		if ( el ) el.remove();
	}

	/**
	 * Backwards-compat toast for the legacy sync flow.
	 */
	function notify( message, type ) {
		var el = document.createElement( 'div' );
		el.className = 'mpfr-toast mpfr-toast--' + ( type || 'info' );
		el.setAttribute( 'role', 'status' );
		el.textContent = message;
		el.style.cssText = 'position:fixed;top:60px;right:24px;z-index:99999;'
			+ 'background:' + ( type === 'error' ? '#fcf0f1' : '#edfaef' )
			+ ';color:#1d2327;padding:12px 18px;'
			+ 'border-left:4px solid ' + ( type === 'error' ? '#d63638' : '#00a32a' )
			+ ';border-radius:3px;font-size:13px;'
			+ 'box-shadow:0 4px 12px rgba(0,0,0,0.15);max-width:480px;'
			+ 'transition:opacity 0.3s;';
		document.body.appendChild( el );
		var guard = setInterval( function () {
			if ( ! el.isConnected ) {
				document.body.appendChild( el );
			}
		}, 250 );
		setTimeout( function () {
			clearInterval( guard );
			el.style.opacity = '0';
			setTimeout( function () { el.remove(); }, 300 );
		}, 6000 );
	}

	/**
	 * Inject our menu item into a popover if it is the MailerPress
	 * row Actions menu and the campaign has failed emails.
	 */
	function injectIntoPopover( popover ) {
		if ( popover.dataset.mpfrInjected ) {
			return;
		}
		var menu = popover.querySelector( '[role="menu"]' );
		if ( ! menu ) {
			return;
		}
		// Identify: must contain "Delete" + "Rename" (unique to row Actions).
		var labels = Array.prototype.map.call(
			menu.querySelectorAll( '[role="menuitem"]' ),
			function ( b ) { return ( b.textContent || '' ).trim(); }
		);
		if ( labels.indexOf( 'Delete' ) === -1 || labels.indexOf( 'Rename' ) === -1 ) {
			return;
		}
		// Find the kebab and its row to get the campaign id.
		// MailerPress renders the popover as a portal; the kebab is
		// identified by being the focused element before the click.
		var kebab = document.activeElement && document.activeElement.classList
			&& document.activeElement.classList.contains( 'components-dropdown-menu__toggle' )
			? document.activeElement
			: ( window.__mpfrLastKebab || null );
		if ( ! kebab ) {
			return;
		}
		var row        = getRowFromKebab( kebab );
		var campaignId = getCampaignIdFromRow( row );
		if ( ! campaignId ) {
			return;
		}

		popover.dataset.mpfrInjected   = '1';
		popover.dataset.mpfrCampaignId = String( campaignId );

		// Build our menu item mirroring the React menu item styling.
		var btn = document.createElement( 'button' );
		btn.setAttribute( 'type', 'button' );
		btn.setAttribute( 'role', 'menuitem' );
		btn.className = 'components-button components-dropdown-menu__menu-item is-compact mpfr-row-action';
		btn.textContent = '↻ Retry Failed Emails';
		btn.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			if ( ! window.confirm( 'Resend every failed email for this campaign through the active ESP?' ) ) {
				return;
			}
			runResend( campaignId, btn );
		} );

		// Insert at top of the menu.
		menu.insertBefore( btn, menu.firstChild );
	}

	// Listen for kebab clicks to remember which row opened the popover.
	document.addEventListener( 'click', function ( e ) {
		var t = e.target;
		if ( t && t.classList && t.classList.contains( 'components-dropdown-menu__toggle' ) ) {
			window.__mpfrLastKebab = t;
		}
	}, true );

	// Watch for new popovers.
	var obs = new MutationObserver( function ( records ) {
		records.forEach( function ( r ) {
			r.addedNodes.forEach( function ( n ) {
				if ( n.nodeType !== 1 ) {
					return;
				}
				if ( n.classList && n.classList.contains( 'components-popover' ) ) {
					injectIntoPopover( n );
				} else if ( n.querySelectorAll ) {
					var nested = n.querySelectorAll( '.components-popover' );
					for ( var i = 0; i < nested.length; i++ ) {
						injectIntoPopover( nested[ i ] );
					}
				}
			} );
		} );
	} );
	obs.observe( document.body, { childList: true, subtree: true } );

	// Initial pass (popover may already be open after page load).
	document.querySelectorAll( '.components-popover' ).forEach( injectIntoPopover );
} )();
</script>
	<?php
}
