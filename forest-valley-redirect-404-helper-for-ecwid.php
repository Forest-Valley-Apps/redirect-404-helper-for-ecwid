<?php
/**
 * Plugin Name:       Forest Valley Redirect & 404 Helper for Ecwid
 * Plugin URI:        https://apps.fv.dev/redirect-404-manager/
 * Description:       Finds, classifies, and helps fix 404s on WordPress sites with an embedded Ecwid store, with WordPress-layer 301 redirects.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Forest Valley
 * Author URI:        https://apps.fv.dev/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       forest-valley-redirect-404-helper-for-ecwid
 * Domain Path:       /languages
 *
 * @package ForestValley\RedirectHelper
 */

declare( strict_types=1 );

namespace ForestValley\RedirectHelper;

defined( 'ABSPATH' ) || exit;

define( 'FVRH404_VERSION', '1.0.0' );
define( 'FVRH404_PLUGIN_FILE', __FILE__ );
define( 'FVRH404_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * PSR-4 autoloader for the plugin's own classes.
 *
 * The shipped plugin carries no Composer runtime dependency — Composer is used
 * only for development tooling (PHPCS, PHPUnit). This loader mirrors the PSR-4
 * mapping declared in composer.json so runtime needs no `vendor/` directory.
 *
 * @param string $class_name Fully-qualified class name being loaded.
 * @return void
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ForestValley\\RedirectHelper\\';
		$length = strlen( $prefix );

		if ( 0 !== strncmp( $prefix, $class_name, $length ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, $length ) );
		$file     = __DIR__ . '/src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->register();
	}
);
