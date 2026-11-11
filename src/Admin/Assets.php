<?php
/**
 * Admin stylesheet for the plugin's own screens.
 *
 * @package ForestValley\RedirectHelper
 */

declare( strict_types=1 );

namespace ForestValley\RedirectHelper\Admin;

use ForestValley\RedirectHelper\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and enqueues the plugin's admin CSS through one handle.
 *
 * All of the plugin's styled chrome — the 404-log badges (type, layer, catalog
 * verdict), the ellipsized cells, the slug-collision panel, the contextual
 * upgrade CTAs, and the upgrade page grid — share this single registered
 * stylesheet, attached via `wp_add_inline_style()` rather than echoed inline
 * (which Plugin Check flags). The handle has no file source; the CSS travels
 * as the inline payload.
 *
 * Loaded only on the plugin's own admin screens (`page=fvrh404-*`). The one
 * styled element that can appear elsewhere — the site-wide slug-collision admin
 * notice — uses core's `.notice` classes only, so it needs no plugin CSS off
 * these screens; nothing else renders the `fvrh404-*` classes outside them.
 */
final class Assets {

	/**
	 * The shared style handle.
	 *
	 * @var string
	 */
	private const HANDLE = 'fvrh404-admin';

	/**
	 * Register the enqueue hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the stylesheet on the plugin's own admin screens.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! self::is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		// A source-less handle: the CSS is delivered entirely as the inline
		// payload below, which `wp_add_inline_style()` prints once the (empty)
		// handle is enqueued.
		wp_register_style( self::HANDLE, false, array(), Plugin::VERSION );
		wp_enqueue_style( self::HANDLE );
		wp_add_inline_style( self::HANDLE, self::css() );
	}

	/**
	 * Whether the current admin screen is one of the plugin's pages.
	 *
	 * Every plugin page slug begins `fvrh404-`, and the hook suffix carries the
	 * slug verbatim (`toplevel_page_fvrh404-404-log`, `..._page_fvrh404-redirects`,
	 * etc.), so a substring test catches them all.
	 *
	 * @param string $hook_suffix The admin page hook suffix.
	 * @return bool
	 */
	private static function is_plugin_screen( string $hook_suffix ): bool {
		return false !== strpos( $hook_suffix, 'fvrh404-' );
	}

	/**
	 * The plugin's admin CSS.
	 *
	 * @return string
	 */
	private static function css(): string {
		return '
			.fvrh404-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; line-height:1.6; color:#fff; }
			/* Badge fills map to Forest Valley brand tokens (Shared-assets-git/styles/shared.css).
			   Literal hexes (not var(--fv-*)) because the brand token stylesheet is not loaded
			   into wp-admin. Status verdicts use the fixed status palette; type/layer use the
			   neutral navy + gray scale, with green reserved for the primary commerce entity. */
			.fvrh404-badge--product { background:#27ae60; } /* --fv-green */
			.fvrh404-badge--category { background:#2c3e50; } /* --fv-navy */
			.fvrh404-badge--wp-page { background:#6c757d; } /* --fv-gray-500 */
			.fvrh404-badge--layer-wp { background:#34495e; } /* --fv-navy-light */
			.fvrh404-badge--layer-storefront { background:#3498db; } /* --fv-info */
			.fvrh404-badge--verdict-in-catalog { background:#27ae60; } /* --fv-green (healthy / still exists) */
			.fvrh404-badge--verdict-deleted { background:#e74c3c; } /* --fv-danger */
			.fvrh404-badge--verdict-never-existed { background:#f39c12; } /* --fv-warning */
			.fvrh404-badge--verdict-not-in-catalog { background:#6c757d; } /* --fv-gray-500 */
			.fvrh404-ellipsis { display:inline-block; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; vertical-align:bottom; }
			.fvrh404-collision-panel { background:#fff; border:1px solid #c3c4c7; border-left:4px solid #dba617; padding:1px 12px 12px; margin:12px 0; }
			.fvrh404-collision-table { max-width:760px; }
			.fvrh404-collision-allclear { color:#646970; }
			.fvrh404-cta { background:#fff; border:1px solid #c3c4c7; border-left:4px solid #27ae60; padding:4px 16px 12px; margin:12px 0; max-width:760px; }
			.fvrh404-cta__tag { display:inline-block; margin-top:12px; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.03em; color:#fff; background:#27ae60; }
			.fvrh404-cta__title { margin:8px 0 4px; font-size:14px; color:#2c3e50; }
			.fvrh404-cta__body { margin:0 0 12px; color:#2c3e50; }
			.fvrh404-cta__actions { margin:0; }
			.fvrh404-cta__dismiss { margin-left:8px; color:#646970; text-decoration:none; }
			.fvrh404-upgrade__lead { max-width:760px; font-size:14px; color:#2c3e50; }
			.fvrh404-upgrade__note { max-width:760px; color:#646970; }
			.fvrh404-upgrade__grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(340px, 1fr)); gap:16px; max-width:760px; margin-top:16px; }
			.fvrh404-upgrade__card { display:flex; flex-direction:column; background:#fff; border:1px solid #c3c4c7; border-top:3px solid #27ae60; padding:4px 16px 16px; }
			.fvrh404-upgrade__card h2 { font-size:15px; color:#2c3e50; }
			.fvrh404-upgrade__card p { color:#2c3e50; }
			/* Pin the CTA in each card to the bottom so the buttons line up across a
			   row of unequal-length cards; keep it natural-width, not full-width. */
			.fvrh404-upgrade__card .button { margin-top:auto; align-self:flex-start; }
			/* Paint the paid-app CTAs FV brand green instead of WP admin blue. */
			.fvrh404-upgrade__card .button-primary { background:#27ae60; border-color:#1e8e4f; color:#fff; }
			.fvrh404-upgrade__card .button-primary:hover, .fvrh404-upgrade__card .button-primary:focus { background:#1e8e4f; border-color:#1e8e4f; color:#fff; }
			.fvrh404-upgrade__card .button-primary:focus { box-shadow:0 0 0 1px #fff, 0 0 0 3px #27ae60; }
			/* Branded page header: app logo + title lockup (this IS the app product page). */
			.fvrh404-upgrade__header { display:flex; align-items:center; gap:12px; }
			.fvrh404-upgrade__logo { flex:0 0 auto; width:auto; height:44px; }
			/* Always-visible per-row primary action (unbranded): the storefront
			   "Fix in Ecwid" / WP "Create redirect" link, shown inline instead of
			   the hover-only .row-actions tray. */
			.fvrh404-row-cta { margin-top:4px; }
			.fvrh404-row-fix { font-weight:600; text-decoration:none; }
			/* The persistent, branded app button a screen header renders top-right
			   (the 404 Log today). The logo is an <img> that keeps its own colours,
			   so hover uses a soft tint, not a green fill that would swallow the
			   mark. The scoped clear stops the float bleeding past the header divider
			   (this stylesheet loads only on the plugin screens). */
			.fvrh404-app-btn { display:inline-flex; align-items:center; gap:6px; float:right; margin-top:-3px; padding:4px 12px; border:1px solid #27ae60; border-radius:4px; background:#fff; color:#27ae60; font-weight:600; line-height:1.8; text-decoration:none; }
			.fvrh404-app-btn:hover, .fvrh404-app-btn:focus { background:#f0faf4; border-color:#1e8e4f; color:#1e8e4f; }
			.fvrh404-app-btn__logo { display:block; width:20px; height:auto; }
			.wp-header-end { clear:both; }
			/* WP-vs-storefront layer banner (Redirects screen): a branded, always-on
			   orientation banner — app logo + the honest boundary + a deep-link to
			   manage storefront redirects. */
			.fvrh404-layer-banner { display:flex; align-items:center; gap:14px; max-width:760px; margin:12px 0; padding:12px 16px; background:#fff; border:1px solid #c3c4c7; border-left:4px solid #27ae60; }
			.fvrh404-layer-banner__logo { flex:0 0 auto; width:40px; height:auto; }
			.fvrh404-layer-banner__text { margin:0; color:#2c3e50; }
			/* Small inline app logo for the lighter "logo on the guidance" note
			   (Settings, ready-but-not-connected) — no banner box, no CTA. */
			.fvrh404-inline-logo { width:20px; height:auto; vertical-align:middle; margin-right:6px; }
		';
	}
}
