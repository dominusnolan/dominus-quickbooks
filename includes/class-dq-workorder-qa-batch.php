<?php
/**
 * Workorder QA Batch Operations
 *
 * Provides batch admin action and WP-CLI command for marking workorders
 * as QA done based on the closed_on field.
 *
 * @package Dominus_QuickBooks
 * @since   0.3.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DQ_Workorder_QA_Batch
 *
 * Handles batch marking of workorders as QA done based on closed_on field.
 */
class DQ_Workorder_QA_Batch {

	/**
	 * Nonce action for batch operations.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'dq_mark_qa_done_action';

	/**
	 * Initialize hooks for batch QA operations.
	 *
	 * @return void
	 */
	public static function init() {
		// Add batch button to workorder list screen.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_batch_button' ), 20 );

		// Handle batch action.
		add_action( 'admin_init', array( __CLASS__, 'handle_batch_action' ) );

		// Display admin notices.
		add_action( 'admin_notices', array( __CLASS__, 'display_batch_notices' ) );

		// Register WP-CLI command if available.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'dqqb mark-qa-by-closed-on', array( __CLASS__, 'cli_mark_qa_by_closed_on' ) );
		}
	}

	/**
	 * Render the batch action button on the workorder list screen.
	 *
	 * @param string $post_type Current post type.
	 * @return void
	 */
	public static function render_batch_button( $post_type ) {
		if ( 'workorder' !== $post_type ) {
			return;
		}

		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$nonce = wp_create_nonce( self::NONCE_ACTION );
		?>
		<input type="hidden" name="dq_qa_batch_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
		<button type="submit" name="dq_mark_qa_done" value="1" class="button" style="margin-left:8px;">
			<?php esc_html_e( 'Batch: Mark QA Done (closed_on)', 'dqqb' ); ?>
		</button>
		<?php
	}

	/**
	 * Handle the batch action when the button is clicked.
	 *
	 * @return void
	 */
	public static function handle_batch_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below
		if ( ! isset( $_REQUEST['dq_mark_qa_done'] ) || '1' !== $_REQUEST['dq_mark_qa_done'] ) {
			return;
		}

		// Verify nonce.
		$nonce = isset( $_REQUEST['dq_qa_batch_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['dq_qa_batch_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid security token. Please try again.', 'dqqb' ) );
		}

		// Check capability.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dqqb' ) );
		}

		// Perform the batch operation.
		$result = self::mark_qa_done_by_closed_on();

		// Store result in transient for display after redirect.
		set_transient( 'dq_qa_batch_result', $result, 60 );

		// Redirect back to the workorder list to avoid form resubmission.
		$redirect_url = admin_url( 'edit.php?post_type=workorder&dq_qa_batch_done=1' );
		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Display admin notices for batch operation results.
	 *
	 * @return void
	 */
	public static function display_batch_notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display, no action taken
		if ( ! isset( $_GET['dq_qa_batch_done'] ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'edit-workorder' !== $screen->id ) {
			return;
		}

		$result = get_transient( 'dq_qa_batch_result' );
		if ( ! $result || ! is_array( $result ) ) {
			return;
		}

		delete_transient( 'dq_qa_batch_result' );

		$total_scanned   = isset( $result['total_scanned'] ) ? (int) $result['total_scanned'] : 0;
		$with_closed_on  = isset( $result['with_closed_on'] ) ? (int) $result['with_closed_on'] : 0;
		$newly_marked    = isset( $result['newly_marked'] ) ? (int) $result['newly_marked'] : 0;
		$already_marked  = isset( $result['already_marked'] ) ? (int) $result['already_marked'] : 0;

		$message = sprintf(
			/* translators: %1$d: total scanned, %2$d: with closed_on, %3$d: newly marked, %4$d: already marked */
			esc_html__( 'Batch QA complete. Total scanned: %1$d | With closed_on: %2$d | Newly marked: %3$d | Already marked: %4$d', 'dqqb' ),
			$total_scanned,
			$with_closed_on,
			$newly_marked,
			$already_marked
		);

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Mark QA done for all workorders with a non-empty closed_on field.
	 *
	 * @return array Summary of the operation.
	 */
	public static function mark_qa_done_by_closed_on() {
		$result = array(
			'total_scanned'  => 0,
			'with_closed_on' => 0,
			'newly_marked'   => 0,
			'already_marked' => 0,
		);

		// Query all workorders regardless of status taxonomy.
		$args = array(
			'post_type'      => 'workorder',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);

		$query    = new WP_Query( $args );
		$post_ids = $query->posts;

		$result['total_scanned'] = count( $post_ids );

		foreach ( $post_ids as $post_id ) {
			$closed_on = self::get_closed_on_value( $post_id );

			// Skip if closed_on is empty.
			if ( empty( $closed_on ) ) {
				continue;
			}

			$result['with_closed_on']++;

			// Check if already marked as QA done.
			if ( self::is_quality_assurance_done( $post_id ) ) {
				$result['already_marked']++;
				continue;
			}

			// Mark as QA done.
			self::set_quality_assurance( $post_id, true );
			$result['newly_marked']++;
		}

		return $result;
	}

	/**
	 * Get the closed_on value for a workorder.
	 *
	 * @param int $post_id Post ID.
	 * @return string The closed_on value or empty string.
	 */
	private static function get_closed_on_value( $post_id ) {
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( 'closed_on', $post_id );
			return is_string( $value ) ? trim( $value ) : '';
		}
		$value = get_post_meta( $post_id, 'closed_on', true );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Check if quality_assurance is done.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if QA is done.
	 */
	private static function is_quality_assurance_done( $post_id ) {
		if ( function_exists( 'get_field' ) ) {
			$val = get_field( 'quality_assurance', $post_id );
			return ( 1 === $val || '1' === $val || true === $val );
		}
		$raw = get_post_meta( $post_id, 'quality_assurance', true );
		return ( '1' === $raw || 1 === $raw || true === $raw );
	}

	/**
	 * Set the quality_assurance field for a workorder.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $done    Whether QA is done.
	 * @return bool True on success.
	 */
	private static function set_quality_assurance( $post_id, $done ) {
		$value = $done ? 1 : 0;

		if ( function_exists( 'update_field' ) ) {
			return (bool) update_field( 'quality_assurance', $value, $post_id );
		}

		return (bool) update_post_meta( $post_id, 'quality_assurance', (string) $value );
	}

	/**
	 * WP-CLI command to mark QA done for workorders with closed_on.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dqqb mark-qa-by-closed-on
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public static function cli_mark_qa_by_closed_on( $args = array(), $assoc_args = array() ) {
		WP_CLI::log( 'Starting batch QA marking based on closed_on field...' );

		$result = self::mark_qa_done_by_closed_on();

		WP_CLI::success(
			sprintf(
				'Batch QA complete. Total scanned: %d | With closed_on: %d | Newly marked: %d | Already marked: %d',
				$result['total_scanned'],
				$result['with_closed_on'],
				$result['newly_marked'],
				$result['already_marked']
			)
		);
	}
}
