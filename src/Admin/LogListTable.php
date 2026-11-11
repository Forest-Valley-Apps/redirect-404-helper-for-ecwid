<?php
/**
 * 404 log list table.
 *
 * @package ForestValley\RedirectHelper
 */

declare( strict_types=1 );

namespace ForestValley\RedirectHelper\Admin;

use ForestValley\RedirectHelper\Log\NotFoundLog;
use ForestValley\RedirectHelper\Upsell\DeepLink;
use ForestValley\RedirectHelper\Url\UrlClassifier;
use ForestValley\RedirectHelper\Verdict\VerdictChecker;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the captured 404s: classification badge, the fixable layer, hit
 * count, last seen, status, with search, classification/status filters,
 * sortable columns and a bulk Delete action. Reads via {@see NotFoundLog::query()};
 * never writes — the page controller owns every mutation.
 *
 * The per-row routing is the funnel made honest: a WordPress-layer row keeps the
 * free "Create redirect" 301; a storefront-layer row (an Ecwid product/category
 * route no WordPress plugin can 301) instead gets an always-visible "Fix in
 * Ecwid" deep-link, with the row's own path threaded through so the app can
 * pre-fill it. Both are shown inline, not as hover-only row actions; the layer is
 * derived ({@see RowLayer}), never stored.
 */
final class LogListTable extends \WP_List_Table {

	/**
	 * Rows per page.
	 *
	 * @var int
	 */
	private const PER_PAGE = 20;

	/**
	 * Log repository.
	 *
	 * @var NotFoundLog
	 */
	private NotFoundLog $log;

	/**
	 * The current request's filter/sort state ({@see LogPage::current_query_args()}).
	 *
	 * @var array<string,string>
	 */
	private array $query_args;

	/**
	 * Deep-link builder for storefront-layer "Fix in Ecwid" actions, or null until
	 * first needed (resolved from the environment only when a storefront row
	 * actually renders, so a WP-only log does no discovery).
	 *
	 * @var DeepLink|null
	 */
	private ?DeepLink $deep_link;

	/**
	 * Constructor.
	 *
	 * @param NotFoundLog          $log        Log repository.
	 * @param array<string,string> $query_args The request's filter/sort state.
	 * @param DeepLink|null        $deep_link  Deep-link builder (injectable for tests).
	 */
	public function __construct( NotFoundLog $log, array $query_args, ?DeepLink $deep_link = null ) {
		parent::__construct(
			array(
				'singular' => 'fvrh404-404',
				'plural'   => 'fvrh404-404s',
				'ajax'     => false,
			)
		);

		$this->log        = $log;
		$this->query_args = $query_args;
		$this->deep_link  = $deep_link;
	}

	/**
	 * The deep-link builder, resolved from the environment on first use.
	 *
	 * @return DeepLink
	 */
	private function deep_link(): DeepLink {
		if ( null === $this->deep_link ) {
			$this->deep_link = DeepLink::from_environment();
		}

		return $this->deep_link;
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string,string>
	 */
	public function get_columns(): array {
		return array(
			'cb'             => '<input type="checkbox" />',
			'url_path'       => __( 'URL', 'forest-valley-redirect-404-helper-for-ecwid' ),
			'classification' => __( 'Type', 'forest-valley-redirect-404-helper-for-ecwid' ),
			'layer'          => __( 'Fix at', 'forest-valley-redirect-404-helper-for-ecwid' ),
			'verdict'        => __( 'Catalog', 'forest-valley-redirect-404-helper-for-ecwid' ),
			'hit_count'      => __( 'Hits', 'forest-valley-redirect-404-helper-for-ecwid' ),
			'referrer'       => __( 'Last referrer', 'forest-valley-redirect-404-helper-for-ecwid' ),
			'status'         => __( 'Status', 'forest-valley-redirect-404-helper-for-ecwid' ),
			'last_seen'      => __( 'Last seen', 'forest-valley-redirect-404-helper-for-ecwid' ),
		);
	}

	/**
	 * Sortable columns (the repository whitelists the actual SQL column).
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'url_path'       => array( 'url_path', false ),
			'classification' => array( 'classification', false ),
			'hit_count'      => array( 'hit_count', true ),
			'last_seen'      => array( 'last_seen', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'forest-valley-redirect-404-helper-for-ecwid' ),
		);
	}

	/**
	 * Load the current page of rows.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$args = $this->query_args;

		$total = $this->log->count( $args );

		$args['per_page'] = self::PER_PAGE;
		$args['paged']    = $this->get_pagenum();

		$this->items = $this->log->query( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	/**
	 * Render the classification + status filter dropdowns.
	 *
	 * @param string $which 'top' or 'bottom'.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$args = $this->query_args;

		echo '<div class="alignleft actions">';

		echo '<select name="classification">';
		echo '<option value="">' . esc_html__( 'All types', 'forest-valley-redirect-404-helper-for-ecwid' ) . '</option>';
		foreach ( self::classification_labels() as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $args['classification'] ?? '', $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		echo '<select name="verdict">';
		echo '<option value="">' . esc_html__( 'All catalog verdicts', 'forest-valley-redirect-404-helper-for-ecwid' ) . '</option>';
		foreach ( self::verdict_labels() as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $args['verdict'] ?? '', $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		echo '<select name="status">';
		echo '<option value="">' . esc_html__( 'All statuses', 'forest-valley-redirect-404-helper-for-ecwid' ) . '</option>';
		foreach ( self::status_labels() as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $args['status'] ?? '', $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'forest-valley-redirect-404-helper-for-ecwid' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Message shown when the log is empty.
	 *
	 * @return void
	 */
	public function no_items(): void {
		esc_html_e( 'No 404s logged yet. They will appear here as visitors hit missing URLs.', 'forest-valley-redirect-404-helper-for-ecwid' );
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * URL column: the path, its always-visible primary fix link, and the
	 * hover-only Delete row action.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_url_path( $item ): string {
		$path = (string) $item['url_path'];
		$id   = (int) $item['id'];

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => LogPage::PAGE_SLUG,
					'action' => 'delete',
					'id'     => $id,
				),
				admin_url( 'admin.php' )
			),
			'fvrh404_log_delete_' . $id
		);

		// Layer-aware primary action, shown always (inline) rather than as a
		// hover-only row action: a WordPress-layer row can be fixed with the free
		// 301 right here ("Create redirect"); a storefront-layer row can only be
		// fixed in the app, so it deep-links to Ecwid ("Fix in Ecwid"), with this
		// path threaded through for prefill, instead of offering a 301 that could
		// never fire. Unbranded by design — the header's app button carries the brand.
		$layer = RowLayer::for_classification( (string) $item['classification'] );

		if ( RowLayer::LAYER_STOREFRONT === $layer ) {
			$target  = RowLayer::deep_link_target( (string) ( $item['verdict'] ?? '' ) );
			$app_url = $this->deep_link()->url_for( $target, $path );

			// The ↗ marks the new-tab hop out to the Ecwid control panel.
			$fix_link = sprintf(
				'<a class="fvrh404-row-fix" href="%s" target="_blank" rel="noopener noreferrer">%s <span aria-hidden="true">↗</span></a>',
				esc_url( $app_url ),
				esc_html__( 'Fix in Ecwid', 'forest-valley-redirect-404-helper-for-ecwid' )
			);
		} else {
			$create_url = add_query_arg(
				array(
					'page'   => RedirectsPage::PAGE_SLUG,
					'source' => rawurlencode( $path ),
				),
				admin_url( 'admin.php' )
			);

			$fix_link = sprintf(
				'<a class="fvrh404-row-fix" href="%s">%s</a>',
				esc_url( $create_url ),
				esc_html__( 'Create redirect', 'forest-valley-redirect-404-helper-for-ecwid' )
			);
		}

		// Delete stays a hover-only row action; the fix link above is always shown.
		$actions = array(
			'delete' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $delete_url ),
				esc_html__( 'Delete', 'forest-valley-redirect-404-helper-for-ecwid' )
			),
		);

		return '<strong>' . esc_html( $path ) . '</strong>'
			. '<div class="fvrh404-row-cta">' . $fix_link . '</div>'
			. $this->row_actions( $actions );
	}

	/**
	 * Layer column: which layer this 404 can be fixed at (derived, not stored).
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_layer( $item ): string {
		$layer = RowLayer::for_classification( (string) $item['classification'] );

		return sprintf(
			'<span class="fvrh404-badge fvrh404-badge--layer-%1$s">%2$s</span>',
			esc_attr( $layer ),
			esc_html( RowLayer::label( $layer ) )
		);
	}

	/**
	 * Classification column: a colored badge.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_classification( $item ): string {
		$classification = (string) $item['classification'];
		$labels         = self::classification_labels();

		return sprintf(
			'<span class="fvrh404-badge fvrh404-badge--%1$s">%2$s</span>',
			esc_attr( $classification ),
			esc_html( $labels[ $classification ] ?? $classification )
		);
	}

	/**
	 * Catalog-verdict column: a colored badge, or a dash when unchecked.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_verdict( $item ): string {
		$verdict = (string) ( $item['verdict'] ?? '' );

		if ( '' === $verdict ) {
			return '<span aria-hidden="true">&#8212;</span>';
		}

		$labels = self::verdict_labels();
		$titles = self::verdict_titles();

		return sprintf(
			'<span class="fvrh404-badge fvrh404-badge--verdict-%1$s" title="%2$s">%3$s</span>',
			esc_attr( $verdict ),
			esc_attr( $titles[ $verdict ] ?? '' ),
			esc_html( $labels[ $verdict ] ?? $verdict )
		);
	}

	/**
	 * Status column.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_status( $item ): string {
		$status = (string) $item['status'];
		$labels = self::status_labels();

		return esc_html( $labels[ $status ] ?? $status );
	}

	/**
	 * Referrer column, ellipsized by CSS.
	 *
	 * @param array $item Log row.
	 * @return string
	 */
	protected function column_referrer( $item ): string {
		$referrer = (string) $item['referrer'];

		if ( '' === $referrer ) {
			return '<span aria-hidden="true">&#8212;</span>';
		}

		return '<span class="fvrh404-ellipsis" title="' . esc_attr( $referrer ) . '">' . esc_html( $referrer ) . '</span>';
	}

	/**
	 * Default column renderer.
	 *
	 * @param array  $item        Log row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * Human labels for the classification values.
	 *
	 * @return array<string,string>
	 */
	public static function classification_labels(): array {
		return array(
			UrlClassifier::TYPE_PRODUCT  => __( 'Ecwid product', 'forest-valley-redirect-404-helper-for-ecwid' ),
			UrlClassifier::TYPE_CATEGORY => __( 'Ecwid category', 'forest-valley-redirect-404-helper-for-ecwid' ),
			UrlClassifier::TYPE_WP_PAGE  => __( 'WP page', 'forest-valley-redirect-404-helper-for-ecwid' ),
		);
	}

	/**
	 * Human labels for the status values.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			NotFoundLog::STATUS_NEW        => __( 'New', 'forest-valley-redirect-404-helper-for-ecwid' ),
			NotFoundLog::STATUS_REDIRECTED => __( 'Redirected', 'forest-valley-redirect-404-helper-for-ecwid' ),
		);
	}

	/**
	 * Human labels for the catalog-verdict values.
	 *
	 * @return array<string,string>
	 */
	public static function verdict_labels(): array {
		return array(
			VerdictChecker::VERDICT_IN_CATALOG     => __( 'In catalog — broken link', 'forest-valley-redirect-404-helper-for-ecwid' ),
			VerdictChecker::VERDICT_DELETED        => __( 'Deleted', 'forest-valley-redirect-404-helper-for-ecwid' ),
			VerdictChecker::VERDICT_NEVER_EXISTED  => __( 'Never existed', 'forest-valley-redirect-404-helper-for-ecwid' ),
			VerdictChecker::VERDICT_NOT_IN_CATALOG => __( 'Not in catalog', 'forest-valley-redirect-404-helper-for-ecwid' ),
		);
	}

	/**
	 * Hover explanations for the catalog-verdict badges.
	 *
	 * @return array<string,string>
	 */
	public static function verdict_titles(): array {
		return array(
			VerdictChecker::VERDICT_IN_CATALOG     => __( 'This product/category is live in your Ecwid catalog — the URL or link pointing here is what is broken.', 'forest-valley-redirect-404-helper-for-ecwid' ),
			VerdictChecker::VERDICT_DELETED        => __( 'This item was deleted from your Ecwid catalog (deletion on record in Redirect & 404 Manager).', 'forest-valley-redirect-404-helper-for-ecwid' ),
			VerdictChecker::VERDICT_NEVER_EXISTED  => __( 'No item with this id is on record — most likely a mistyped or fabricated link. (Items deleted before Redirect & 404 Manager was installed also show here.)', 'forest-valley-redirect-404-helper-for-ecwid' ),
			VerdictChecker::VERDICT_NOT_IN_CATALOG => __( 'Not in your Ecwid catalog. Install the Redirect & 404 Manager app to tell deleted items apart from mistyped links.', 'forest-valley-redirect-404-helper-for-ecwid' ),
		);
	}
}
