<?php
/**
 * The send log as a core list table: paging, a status filter, search, and
 * delete. Everything here reads rows through Lean_SMTP_Logger — no SQL lives on
 * this side.
 *
 * WP_List_Table only exists inside wp-admin, so this file is required from the
 * log screen's own load- hook rather than from the plugin bootstrap: a
 * top-level `extends WP_List_Table` in an always-loaded file is a fatal error
 * on the front end, where mail is also sent from.
 *
 * @package lean-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Lean_SMTP_Log_Table extends WP_List_Table {

	/**
	 * The list's plural name. Core derives the bulk-action nonce from it, so the
	 * delete handler needs to name the same string — hence a constant.
	 */
	const PLURAL = 'lean-smtp-log-entries';

	public function __construct() {
		parent::__construct(
			[
				'singular' => 'lean-smtp-log-entry',
				'plural'   => self::PLURAL,
				'ajax'     => false,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Current request
	// -------------------------------------------------------------------------

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the list's own filter, paging and sort state; nothing here changes anything.

	/**
	 * The status being filtered on, '' for all. Anything unrecognised is dropped
	 * rather than passed to the query.
	 */
	public static function current_status(): string {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';

		return in_array( $status, array_keys( self::statuses() ), true ) ? $status : '';
	}

	public static function current_search(): string {
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
	}

	private static function current_order(): string {
		$order = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_GET['order'] ) ) ) : 'DESC';

		return 'ASC' === $order ? 'ASC' : 'DESC';
	}

	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	/**
	 * Status slug => label, in the order the filter links are shown.
	 *
	 * @return array<string, string>
	 */
	private static function statuses(): array {
		return [
			Lean_SMTP_Logger::STATUS_SENT    => __( 'Sent', 'lean-smtp' ),
			Lean_SMTP_Logger::STATUS_FAILED  => __( 'Failed', 'lean-smtp' ),
			Lean_SMTP_Logger::STATUS_OFFLINE => __( 'Offline', 'lean-smtp' ),
		];
	}

	// -------------------------------------------------------------------------
	// Table definition
	// -------------------------------------------------------------------------

	public function get_columns(): array {
		return [
			'cb'       => '<input type="checkbox" />',
			'sent_at'  => __( 'Sent', 'lean-smtp' ),
			'to_email' => __( 'To', 'lean-smtp' ),
			'subject'  => __( 'Subject', 'lean-smtp' ),
			'mailer'   => __( 'Mailer', 'lean-smtp' ),
			'status'   => __( 'Result', 'lean-smtp' ),
		];
	}

	/**
	 * Only the send time sorts, and it sorts by id: rows are appended and never
	 * updated, so id order is send order, and it is the primary key.
	 */
	protected function get_sortable_columns(): array {
		return [ 'sent_at' => [ 'sent_at', true ] ];
	}

	protected function get_default_primary_column_name(): string {
		return 'sent_at';
	}

	protected function get_bulk_actions(): array {
		return [ 'delete' => __( 'Delete', 'lean-smtp' ) ];
	}

	/**
	 * Without 'striped'. An entry occupies two rows here — itself and its detail
	 * row — and core's zebra stripes every <tr> alike, so the two halves of one
	 * entry come out in different colours and the pairing is lost.
	 */
	protected function get_table_classes(): array {
		return array_values( array_diff( parent::get_table_classes(), [ 'striped' ] ) );
	}

	protected function get_views(): array {
		$counts  = Lean_SMTP_Logger::counts_by_status();
		$current = self::current_status();
		$views   = [];

		$all  = [ '' => __( 'All', 'lean-smtp' ) ];
		$all += self::statuses();

		foreach ( $all as $slug => $label ) {
			$count = (int) ( $counts[ '' === $slug ? 'all' : $slug ] ?? 0 );

			$views[ '' === $slug ? 'all' : $slug ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( Lean_SMTP_Log_Page::url( '' === $slug ? [] : [ 'status' => $slug ] ) ),
				$current === $slug ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	public function no_items(): void {
		esc_html_e( 'No mail has been logged yet.', 'lean-smtp' );
	}

	public function prepare_items(): void {
		$per_page = (int) $this->get_items_per_page( Lean_SMTP_Log_Page::PER_PAGE_OPTION, Lean_SMTP_Log_Page::PER_PAGE );
		$paged    = max( 1, (int) $this->get_pagenum() );
		$filter   = [
			'status' => self::current_status(),
			'search' => self::current_search(),
		];

		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$total = Lean_SMTP_Logger::count( $filter );

		$this->items = Lean_SMTP_Logger::query(
			$filter + [
				'order'    => self::current_order(),
				'per_page' => $per_page,
				'offset'   => ( $paged - 1 ) * $per_page,
			]
		);

		$this->set_pagination_args(
			[
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Columns
	// -------------------------------------------------------------------------

	/**
	 * @param object $item A send-log row.
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="log[]" value="%1$d" /><span class="screen-reader-text">%2$s</span>',
			(int) $item->id,
			esc_html__( 'Select this log entry', 'lean-smtp' )
		);
	}

	/**
	 * @param object $item A send-log row.
	 */
	public function column_sent_at( $item ): string {
		return esc_html( (string) $item->sent_at ) . $this->row_actions( $this->entry_actions( $item ) );
	}

	/**
	 * @param object $item A send-log row.
	 */
	public function column_mailer( $item ): string {
		return esc_html( strtoupper( (string) $item->mailer ) );
	}

	/**
	 * @param object $item A send-log row.
	 */
	public function column_status( $item ): string {
		switch ( (string) $item->status ) {
			case Lean_SMTP_Logger::STATUS_SENT:
				return '<span class="lsmtp-badge sent">&#10004; ' . esc_html__( 'Sent', 'lean-smtp' ) . '</span>';

			case Lean_SMTP_Logger::STATUS_OFFLINE:
				return '<span class="lsmtp-badge offline">&#9679; ' . esc_html__( 'Offline', 'lean-smtp' ) . '</span>';

			default:
				return '<span class="lsmtp-badge failed">&#10008; ' . esc_html__( 'Failed', 'lean-smtp' ) . '</span>';
		}
	}

	/**
	 * @param object $item   A send-log row.
	 * @param string $column Column name.
	 */
	public function column_default( $item, $column ): string {
		return esc_html( (string) ( $item->{$column} ?? '' ) );
	}

	/**
	 * @param object $item A send-log row.
	 * @return array<string, string>
	 */
	private function entry_actions( $item ): array {
		$id  = (int) $item->id;
		$url = Lean_SMTP_Log_Page::url(
			[
				'lsmtp_action' => 'delete-entry',
				'log'          => $id,
			] + Lean_SMTP_Log_Page::state()
		);

		return [
			'delete' => sprintf(
				'<a href="%s" class="lsmtp-delete-entry">%s</a>',
				esc_url( wp_nonce_url( $url, Lean_SMTP_Log_Page::DELETE_NONCE . $id ) ),
				esc_html__( 'Delete', 'lean-smtp' )
			),
		];
	}

	// -------------------------------------------------------------------------
	// Rows
	// -------------------------------------------------------------------------

	/**
	 * The row itself, plus the expandable row holding whatever was recorded of
	 * the message. The second row is what makes the log usable for debugging,
	 * and core's single_row() has no notion of it.
	 *
	 * @param object $item A send-log row.
	 */
	public function single_row( $item ): void {
		echo '<tr>';
		$this->single_row_columns( $item );
		echo '</tr>';

		$this->render_detail( $item );
	}

	/**
	 * Rendered only when there is something to show — a log kept without the
	 * message-content settings has nothing here.
	 *
	 * @param object $item A send-log row.
	 */
	private function render_detail( $item ): void {
		$parts = [
			__( 'Error', 'lean-smtp' )       => (string) ( $item->error ?? '' ),
			__( 'Headers', 'lean-smtp' )     => (string) ( $item->headers ?? '' ),
			__( 'Attachments', 'lean-smtp' ) => (string) ( $item->attachments ?? '' ),
			__( 'Body', 'lean-smtp' )        => (string) ( $item->body ?? '' ),
		];
		$parts = array_filter( $parts, static fn( $value ) => '' !== trim( $value ) );

		if ( empty( $parts ) ) {
			return;
		}
		?>
		<tr class="lsmtp-log-detail">
			<td colspan="<?php echo esc_attr( (string) $this->get_column_count() ); ?>">
				<details>
					<summary><?php esc_html_e( 'Details', 'lean-smtp' ); ?></summary>
					<?php foreach ( $parts as $label => $value ) : ?>
						<h4><?php echo esc_html( $label ); ?></h4>
						<pre><?php echo esc_html( $value ); ?></pre>
					<?php endforeach; ?>
				</details>
			</td>
		</tr>
		<?php
	}
}
