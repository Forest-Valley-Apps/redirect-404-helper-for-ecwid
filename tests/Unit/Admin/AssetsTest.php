<?php
/**
 * Unit tests for the admin stylesheet enqueue.
 *
 * @package ForestValley\RedirectHelper
 */

declare( strict_types=1 );

namespace ForestValley\RedirectHelper\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ForestValley\RedirectHelper\Admin\Assets;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers screen gating: the shared style loads on the plugin's own pages and
 * nowhere else (so other admin screens carry no stray CSS, and Plugin Check sees
 * no echoed `<style>`).
 */
final class AssetsTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_enqueues_inline_style_on_a_plugin_screen(): void {
		Functions\expect( 'wp_register_style' )->once();
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'fvrh404-admin' );
		Functions\expect( 'wp_add_inline_style' )
			->once()
			->with( 'fvrh404-admin', Mockery::type( 'string' ) );

		( new Assets() )->enqueue( 'toplevel_page_fvrh404-404-log' );
	}

	public function test_enqueues_on_a_plugin_submenu_screen(): void {
		Functions\expect( 'wp_register_style' )->once();
		Functions\expect( 'wp_enqueue_style' )->once();
		Functions\expect( 'wp_add_inline_style' )->once();

		( new Assets() )->enqueue( 'redirect-404_page_fvrh404-redirects' );
	}

	public function test_does_nothing_off_plugin_screens(): void {
		Functions\expect( 'wp_register_style' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();
		Functions\expect( 'wp_add_inline_style' )->never();

		( new Assets() )->enqueue( 'index.php' );
		( new Assets() )->enqueue( 'edit.php' );
	}
}
