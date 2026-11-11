<?php
/**
 * Connection settings screen.
 *
 * @package ForestValley\RedirectHelper
 */

declare( strict_types=1 );

namespace ForestValley\RedirectHelper\Admin;

use ForestValley\RedirectHelper\Api\BackendClient;
use ForestValley\RedirectHelper\Api\EcwidCatalogClient;
use ForestValley\RedirectHelper\Connection\ConnectionState;
use ForestValley\RedirectHelper\Connection\ConnectionVerifier;
use ForestValley\RedirectHelper\Connection\EcwidPluginDiscovery;
use ForestValley\RedirectHelper\Upsell\AppButton;
use ForestValley\RedirectHelper\Upsell\DeepLink;

defined( 'ABSPATH' ) || exit;

/**
 * The Redirect & 404 → Settings screen and its connect/refresh/disconnect
 * actions. Menu placement is owned by {@see Menu}.
 *
 * Credentials are discovered live from the Ecwid plugin every request; this
 * screen only lets the merchant opt in (Connect), re-verify + refresh the cached
 * ruleset (Refresh), or opt out (Disconnect). Every action is capability- and
 * nonce-guarded and follows the post/redirect/get pattern so a refresh of the
 * page never re-fires it.
 */
final class SettingsPage {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const PAGE_SLUG = 'fvrh404-settings';

	/**
	 * Required capability for the page and all of its actions.
	 *
	 * @var string
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * The admin-post action that verifies and connects.
	 *
	 * @var string
	 */
	private const ACTION_CONNECT = 'fvrh404_connect';

	/**
	 * The admin-post action that re-verifies and refreshes the cached ruleset.
	 *
	 * @var string
	 */
	private const ACTION_REFRESH = 'fvrh404_refresh';

	/**
	 * The admin-post action that disconnects.
	 *
	 * @var string
	 */
	private const ACTION_DISCONNECT = 'fvrh404_disconnect';

	/**
	 * Persisted connect/disconnect state.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $state;

	/**
	 * Constructor.
	 *
	 * @param ConnectionState|null $state Connection state (injectable for tests).
	 */
	public function __construct( ?ConnectionState $state = null ) {
		$this->state = $state ?? new ConnectionState();
	}

	/**
	 * Register the action handlers (menu placement is owned by {@see Menu}).
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( $this, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_REFRESH, array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'forest-valley-redirect-404-helper-for-ecwid' ) );
		}

		$discovery = EcwidPluginDiscovery::discover();
		$connected = $this->state->is_connected();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Forest Valley Redirect & 404 Helper for Ecwid', 'forest-valley-redirect-404-helper-for-ecwid' ) . '</h1>';

		$this->render_notice();

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_row(
			__( 'Ecwid plugin', 'forest-valley-redirect-404-helper-for-ecwid' ),
			$discovery->plugin_active()
				? __( 'Detected', 'forest-valley-redirect-404-helper-for-ecwid' )
				: __( 'Not detected', 'forest-valley-redirect-404-helper-for-ecwid' )
		);
		$this->render_row(
			__( 'Store ID', 'forest-valley-redirect-404-helper-for-ecwid' ),
			$discovery->has_store_id()
				? (string) $discovery->store_id()
				: __( '—', 'forest-valley-redirect-404-helper-for-ecwid' )
		);
		$this->render_row(
			__( 'Storefront token', 'forest-valley-redirect-404-helper-for-ecwid' ),
			$discovery->has_public_token()
				? __( 'Available', 'forest-valley-redirect-404-helper-for-ecwid' )
				: __( 'Not available', 'forest-valley-redirect-404-helper-for-ecwid' )
		);
		$this->render_row(
			__( 'Connection', 'forest-valley-redirect-404-helper-for-ecwid' ),
			$connected
				? __( 'Connected', 'forest-valley-redirect-404-helper-for-ecwid' )
				: __( 'Not connected', 'forest-valley-redirect-404-helper-for-ecwid' )
		);

		$verified_at = $this->state->verified_at();
		if ( $verified_at > 0 ) {
			$this->render_row(
				__( 'Last verified', 'forest-valley-redirect-404-helper-for-ecwid' ),
				$this->format_timestamp( $verified_at )
			);
		}
		echo '</tbody></table>';

		$this->render_guidance( $discovery, $connected );
		$this->render_actions( $discovery, $connected );

		echo '</div>';
	}

	/**
	 * Handle the Connect action: verify the discovered connection, then store it.
	 *
	 * @return void
	 */
	public function handle_connect(): void {
		$this->guard( self::ACTION_CONNECT );
		$this->verify_and_connect( 'connected' );
	}

	/**
	 * Handle the Refresh action: re-verify and refresh the cached ruleset.
	 *
	 * @return void
	 */
	public function handle_refresh(): void {
		$this->guard( self::ACTION_REFRESH );
		$this->verify_and_connect( 'refreshed' );
	}

	/**
	 * Shared body of the Connect and Refresh actions: verify the discovered
	 * connection, persist it, and redirect back with the outcome.
	 *
	 * The backend half of the verification already primes the cached ruleset on
	 * success ({@see \ForestValley\RedirectHelper\Api\BackendClient::ping()}), so no
	 * separate rules fetch is needed here.
	 *
	 * @param string $success_notice Notice code to redirect with on success.
	 * @return void
	 */
	private function verify_and_connect( string $success_notice ): void {
		$discovery = EcwidPluginDiscovery::discover();
		if ( ! $discovery->is_ready() ) {
			$this->redirect_back( 'not-ready' );
		}

		$result = $this->verifier_for( $discovery )->verify();

		if ( ConnectionVerifier::OK !== $result ) {
			$this->redirect_back( 'verify-' . $result );
		}

		$this->state->mark_connected( $discovery->store_id(), time() );

		$this->redirect_back( $success_notice );
	}

	/**
	 * Handle the Disconnect action: opt out and drop the cached ruleset.
	 *
	 * @return void
	 */
	public function handle_disconnect(): void {
		$this->guard( self::ACTION_DISCONNECT );

		$store_id = $this->state->store_id();
		if ( $store_id > 0 ) {
			$this->backend_for( $store_id )->clear_rules_cache();
		}

		$this->state->mark_disconnected();

		$this->redirect_back( 'disconnected' );
	}

	/**
	 * Capability + nonce guard shared by every action handler.
	 *
	 * @param string $action The action/nonce name.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'forest-valley-redirect-404-helper-for-ecwid' ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the settings page with a notice code, then stop.
	 *
	 * @param string $notice Notice code understood by {@see self::notice_message()}.
	 * @return void
	 */
	private function redirect_back( string $notice ): void {
		wp_safe_redirect( add_query_arg( 'fvrh404_notice', $notice, $this->page_url() ) );
		exit;
	}

	/**
	 * Build a backend client for a store id.
	 *
	 * @param int $store_id Ecwid store id.
	 * @return BackendClient
	 */
	private function backend_for( int $store_id ): BackendClient {
		return BackendClient::for_store( $store_id );
	}

	/**
	 * Build a verifier for a discovered, ready connection.
	 *
	 * @param EcwidPluginDiscovery $discovery Discovered connection details.
	 * @return ConnectionVerifier
	 */
	private function verifier_for( EcwidPluginDiscovery $discovery ): ConnectionVerifier {
		return new ConnectionVerifier(
			$this->backend_for( $discovery->store_id() ),
			new EcwidCatalogClient( $discovery->store_id(), $discovery->public_token() )
		);
	}

	/**
	 * The settings page URL.
	 *
	 * @return string
	 */
	private function page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Render a single label/value row of the status table.
	 *
	 * Both arguments are raw text and are escaped here.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 * @return void
	 */
	private function render_row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	/**
	 * Render context-specific guidance for the current discovery state.
	 *
	 * @param EcwidPluginDiscovery $discovery Discovered connection details.
	 * @param bool                 $connected Whether we are currently connected.
	 * @return void
	 */
	private function render_guidance( EcwidPluginDiscovery $discovery, bool $connected ): void {
		// The READY state is the funnel surface, branded with the app logo: connected
		// gets a prominent banner + a live dashboard deep-link; not-connected gets a
		// light branded note (logo, no CTA) so the Connect button below stays the only
		// action. The pre-ready setup states stay plain — no app push before the
		// merchant can even connect.
		if ( EcwidPluginDiscovery::STATUS_READY === $discovery->status() ) {
			if ( $connected ) {
				printf(
					'<div class="fvrh404-layer-banner">'
						. '<img class="fvrh404-layer-banner__logo" src="%1$s" alt="" width="40" height="20" />'
						. '<p class="fvrh404-layer-banner__text">%2$s '
						. '<a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s <span aria-hidden="true">↗</span></a>'
						. '</p></div>',
					esc_url( AppButton::logo_url() ),
					esc_html__( 'Your store is connected. Your Ecwid 404s and WordPress redirect hits flow to the Redirect & 404 Manager dashboard — your unified home for every fix. Use Refresh to re-verify and reload redirect rules.', 'forest-valley-redirect-404-helper-for-ecwid' ),
					esc_url( DeepLink::from_environment()->url_for( DeepLink::TARGET_HOME ) ),
					esc_html__( 'Open the dashboard', 'forest-valley-redirect-404-helper-for-ecwid' )
				);
			} else {
				printf(
					'<p><img class="fvrh404-inline-logo" src="%1$s" alt="" width="20" height="10" /> %2$s</p>',
					esc_url( AppButton::logo_url() ),
					esc_html__( 'Your Ecwid store was detected. Click Connect to verify it and start surfacing your Ecwid 404s — once connected, those 404s and your WordPress redirect hits flow to the Redirect & 404 Manager dashboard, the unified home where storefront-layer fixes live.', 'forest-valley-redirect-404-helper-for-ecwid' )
				);
			}

			return;
		}

		$message = '';

		switch ( $discovery->status() ) {
			case EcwidPluginDiscovery::STATUS_PLUGIN_MISSING:
				$message = __( 'Install and activate the official Ecwid Shopping Cart plugin, then configure your store to connect.', 'forest-valley-redirect-404-helper-for-ecwid' );
				break;
			case EcwidPluginDiscovery::STATUS_NOT_CONFIGURED:
				$message = __( 'The Ecwid plugin is active but no store is configured yet. Connect your Ecwid store in its settings first.', 'forest-valley-redirect-404-helper-for-ecwid' );
				break;
			case EcwidPluginDiscovery::STATUS_NO_TOKEN:
				$message = __( 'A store ID was found but no storefront token is available yet. Reconnect your store in the Ecwid plugin to issue one.', 'forest-valley-redirect-404-helper-for-ecwid' );
				break;
		}

		if ( '' !== $message ) {
			echo '<p>' . esc_html( $message ) . '</p>';
		}
	}

	/**
	 * Render the action buttons appropriate to the current state.
	 *
	 * @param EcwidPluginDiscovery $discovery Discovered connection details.
	 * @param bool                 $connected Whether we are currently connected.
	 * @return void
	 */
	private function render_actions( EcwidPluginDiscovery $discovery, bool $connected ): void {
		if ( $discovery->is_ready() && ! $connected ) {
			$this->render_action_form( self::ACTION_CONNECT, __( 'Connect', 'forest-valley-redirect-404-helper-for-ecwid' ), 'primary' );
		}

		if ( $connected ) {
			$this->render_action_form( self::ACTION_REFRESH, __( 'Refresh', 'forest-valley-redirect-404-helper-for-ecwid' ), 'primary' );
			$this->render_action_form( self::ACTION_DISCONNECT, __( 'Disconnect', 'forest-valley-redirect-404-helper-for-ecwid' ), 'secondary' );
		}
	}

	/**
	 * Render a single-button form that posts an action to admin-post.php.
	 *
	 * @param string $action  The admin-post action/nonce name.
	 * @param string $label   Button label (unescaped; escaped here).
	 * @param string $variant Button variant: 'primary' or 'secondary'.
	 * @return void
	 */
	private function render_action_form( string $action, string $label, string $variant ): void {
		$class = 'primary' === $variant ? 'button button-primary' : 'button';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	/**
	 * Render the notice for the current redirect, if any.
	 *
	 * @return void
	 */
	private function render_notice(): void {
		// Display-only read of our own post/redirect/get marker; the originating
		// action was already nonce-verified before this redirect was issued.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['fvrh404_notice'] ) ? sanitize_key( wp_unslash( $_GET['fvrh404_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}

		list( $type, $message ) = $this->notice_message( $code );
		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}

	/**
	 * Map a notice code to a [type, message] pair.
	 *
	 * @param string $code Notice code from the redirect.
	 * @return array{0:string,1:string} The notice type ('success'/'error'/'warning') and message.
	 */
	private function notice_message( string $code ): array {
		switch ( $code ) {
			case 'connected':
				return array( 'success', __( 'Connected to your Ecwid store.', 'forest-valley-redirect-404-helper-for-ecwid' ) );
			case 'refreshed':
				return array( 'success', __( 'Connection re-verified and redirect rules refreshed.', 'forest-valley-redirect-404-helper-for-ecwid' ) );
			case 'disconnected':
				return array( 'success', __( 'Disconnected from your Ecwid store.', 'forest-valley-redirect-404-helper-for-ecwid' ) );
			case 'not-ready':
				return array( 'warning', __( 'Your Ecwid store is not fully configured yet.', 'forest-valley-redirect-404-helper-for-ecwid' ) );
			case 'verify-' . ConnectionVerifier::BACKEND_UNREACHABLE:
				return array( 'error', __( 'The redirect service could not be reached. Try again shortly.', 'forest-valley-redirect-404-helper-for-ecwid' ) );
			case 'verify-' . ConnectionVerifier::AUTH_FAILED:
				return array( 'error', __( 'The Ecwid storefront token was rejected. Reconnect your store in the Ecwid plugin.', 'forest-valley-redirect-404-helper-for-ecwid' ) );
			case 'verify-' . ConnectionVerifier::CATALOG_UNREACHABLE:
				return array( 'error', __( 'The Ecwid catalog could not be reached to confirm the connection. Try again shortly.', 'forest-valley-redirect-404-helper-for-ecwid' ) );
			default:
				return array( '', '' );
		}
	}

	/**
	 * Format a Unix timestamp using the site's date/time settings.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function format_timestamp( int $timestamp ): string {
		return (string) wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}
}
