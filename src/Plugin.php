<?php
/**
 * Core plugin bootstrap.
 *
 * @package ForestValley\RedirectHelper
 */

declare( strict_types=1 );

namespace ForestValley\RedirectHelper;

use ForestValley\RedirectHelper\Admin\Assets;
use ForestValley\RedirectHelper\Admin\Menu;
use ForestValley\RedirectHelper\Capture\NotFoundCapture;
use ForestValley\RedirectHelper\Collision\CollisionNotice;
use ForestValley\RedirectHelper\Collision\CollisionScanner;
use ForestValley\RedirectHelper\Cron\Tasks;
use ForestValley\RedirectHelper\Log\Schema;
use ForestValley\RedirectHelper\Redirect\Redirector;
use ForestValley\RedirectHelper\Upsell\UpgradeCta;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin controller.
 *
 * Wires WordPress hooks: the admin pages (404 log dashboard, manual redirects,
 * connection settings) on admin requests; the manual-301 redirector and 404
 * capture on front-end requests.
 */
final class Plugin {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public const VERSION = '1.0.0';

	/**
	 * Shared singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Private constructor — use {@see Plugin::instance()}.
	 */
	private function __construct() {}

	/**
	 * Retrieve the shared plugin instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		// The cron callback must exist on every request type — WP-Cron requests
		// are neither admin nor ordinary front-end.
		( new Tasks() )->register();

		// The collision hooks are unconditional too: slug saves happen via
		// wp-admin AND the REST API (Gutenberg), and the deferred-scan event
		// fires on cron requests.
		( new CollisionScanner() )->register();

		if ( is_admin() ) {
			// Covers plugin updates, which do not fire the activation hook.
			// Costs one autoloaded-option compare on admin requests only.
			Schema::maybe_migrate();
			Tasks::ensure_scheduled();

			( new Menu() )->register();
			( new Assets() )->register();
			( new CollisionNotice() )->register();
			( new UpgradeCta() )->register();

			return;
		}

		// Redirector first (priority 5: ahead of core's canonical-redirect
		// guess at 10 and the capture at 20) so an explicit rule wins and a
		// matched 301 is never logged as a 404.
		( new Redirector() )->register();
		( new NotFoundCapture() )->register();
	}

	/**
	 * Activation callback: create/upgrade the plugin tables, schedule cron.
	 *
	 * @return void
	 */
	public static function activate(): void {
		Schema::migrate();
		Tasks::ensure_scheduled();
	}

	/**
	 * Deactivation callback: remove the scheduled cron events.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		Tasks::unschedule();
		CollisionScanner::unschedule_scan();
	}
}
