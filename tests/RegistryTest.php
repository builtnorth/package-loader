<?php

declare(strict_types=1);

namespace Novalis\PackageLoader\Tests;

use Novalis\PackageLoader\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The registry is a request-scoped singleton coordinating real class loading,
 * so these run in separate processes: a class loaded by one test would
 * otherwise still be declared in the next, hiding resolution bugs.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class RegistryTest extends TestCase
{
	private const OLD = '1.0.0';
	private const NEW = '2.0.0';

	/**
	 * Drop anything the monorepo's own packages registered during bootstrap.
	 *
	 * The Registry is a process-wide singleton and every real package's
	 * register.php runs when the test autoloader loads, so boot_all() would
	 * otherwise try to boot the whole suite's packages — outside WordPress,
	 * where their boot callables have no add_action() to call.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$registry   = Registry::instance();
		$reflection = new \ReflectionClass($registry);

		foreach (['candidates', 'pinned', 'booted', 'segments', 'pending_wp'] as $property) {
			$prop = $reflection->getProperty($property);
			$prop->setAccessible(true);
			$prop->setValue($registry, []);
		}
	}

	private function fixtures(): string
	{
		return __DIR__ . '/fixtures';
	}

	/**
	 * @param array<string, mixed> $extra
	 */
	private function register(string $dir, string $version, array $extra = []): void
	{
		Registry::instance()->register(array_merge([
			'package' => 'acme/demo',
			'version' => $version,
			'root'    => $this->fixtures() . '/' . $dir,
			'psr4'    => ['Acme\\Demo\\' => 'inc/classes/'],
		], $extra));
	}

	public function test_newest_wins_when_old_registers_first(): void
	{
		$this->register('old', self::OLD);
		$this->register('new', self::NEW);

		$this->assertSame('new', \Acme\Demo\Widget::which());
	}

	public function test_newest_wins_when_new_registers_first(): void
	{
		$this->register('new', self::NEW);
		$this->register('old', self::OLD);

		$this->assertSame('new', \Acme\Demo\Widget::which());
	}

	public function test_single_candidate_resolves(): void
	{
		$this->register('old', self::OLD);

		$this->assertSame('old', \Acme\Demo\Widget::which());
	}

	public function test_class_only_present_in_newer_copy_resolves(): void
	{
		$this->register('old', self::OLD);
		$this->register('new', self::NEW);

		$this->assertTrue(\Acme\Demo\OnlyInNew::ok());
	}

	public function test_early_class_use_pins_version_against_later_newer_copy(): void
	{
		$this->register('old', self::OLD);

		// A consumer touching the class at plugin-file-load time, before every
		// bundler has registered, is the one path an older copy can win.
		$this->assertSame('old', \Acme\Demo\Widget::which());

		$this->register('new', self::NEW);

		$this->assertSame('old', \Acme\Demo\Widget::which());
		$this->assertSame(self::OLD, Registry::instance()->version('acme/demo'));
	}

	public function test_version_reports_winner_before_any_class_loads(): void
	{
		$this->register('old', self::OLD);
		$this->register('new', self::NEW);

		$this->assertSame(self::NEW, Registry::instance()->version('acme/demo'));
	}

	public function test_version_is_null_for_unregistered_package(): void
	{
		$this->assertNull(Registry::instance()->version('acme/nope'));
	}

	public function test_candidates_lists_every_registered_copy(): void
	{
		$this->register('old', self::OLD);
		$this->register('new', self::NEW);

		$this->assertSame([
			$this->fixtures() . '/old' => self::OLD,
			$this->fixtures() . '/new' => self::NEW,
		], Registry::instance()->candidates('acme/demo'));
	}

	public function test_unknown_namespace_is_left_to_other_autoloaders(): void
	{
		$this->register('new', self::NEW);

		$this->assertFalse(class_exists('Totally\\Unrelated\\Thing'));
	}

	public function test_registration_without_package_or_root_is_ignored(): void
	{
		Registry::instance()->register(['package' => '', 'version' => '1.0.0', 'root' => '/tmp']);
		Registry::instance()->register(['package' => 'acme/demo', 'version' => '1.0.0', 'root' => '']);

		$this->assertSame([], Registry::instance()->candidates('acme/demo'));
	}

	public function test_boot_runs_newest_callable_once(): void
	{
		$GLOBALS['novalis_package_loader_test_boots'] = [];

		$this->register('old', self::OLD, ['boot' => [BootSpy::class, 'old']]);
		$this->register('new', self::NEW, ['boot' => [BootSpy::class, 'new']]);

		Registry::instance()->boot_all();
		Registry::instance()->boot_all();

		$this->assertSame(['new'], $GLOBALS['novalis_package_loader_test_boots']);
	}

	public function test_package_without_boot_callable_is_skipped(): void
	{
		$this->register('new', self::NEW);

		Registry::instance()->boot_all();

		$this->assertSame(self::NEW, Registry::instance()->version('acme/demo'));
	}

	public function test_wordpress_ready_queues_callback_while_plugin_api_is_absent(): void
	{
		// These run in separate processes with no WordPress loaded, which is
		// exactly composer's autoload window: add_action() does not exist yet.
		$this->assertFalse(function_exists('add_action'), 'precondition: no plugin API');

		$ran = false;

		Registry::instance()->on_wordpress_ready(static function () use (&$ran): void {
			$ran = true;
		});

		$this->assertFalse($ran, 'callback must be queued, not run before WordPress exists');
	}

	public function test_wordpress_ready_runs_immediately_when_plugin_api_already_exists(): void
	{
		// The other branch: a package registering after WordPress is up — a
		// theme's vendor tree, or WP-CLI reaching a package post-boot.
		if (! function_exists('add_action')) {
			eval('function add_action($hook, $callback, $priority = 10, $args = 1) { return true; }');
		}

		$ran = false;

		Registry::instance()->on_wordpress_ready(static function () use (&$ran): void {
			$ran = true;
		});

		$this->assertTrue($ran, 'callback must run immediately, not wait for a drain that never comes');
	}

	public function test_wordpress_ready_callback_fires_once_the_plugin_api_appears(): void
	{
		$ran = false;

		Registry::instance()->on_wordpress_ready(static function () use (&$ran): void {
			$ran = true;
		});

		$this->assertFalse($ran, 'precondition: still queued');

		// WordPress defining its plugin API is what the shim is waiting for.
		if (! function_exists('add_action')) {
			eval('function add_action($hook, $callback, $priority = 10, $args = 1) { return true; }');
		}

		Registry::instance()->drain_pending_wp('Any\\Class\\Name');

		$this->assertTrue($ran, 'drain must flush queued callbacks once add_action exists');
	}

	public function test_longest_psr4_prefix_wins(): void
	{
		Registry::instance()->register([
			'package' => 'acme/demo',
			'version' => self::NEW,
			'root'    => $this->fixtures() . '/new',
			'psr4'    => [
				'Acme\\'       => 'nonexistent/',
				'Acme\\Demo\\' => 'inc/classes/',
			],
		]);

		$this->assertSame('new', \Acme\Demo\Widget::which());
	}
}

/**
 * Records which copy's boot callable ran. A named class, not a closure —
 * boot callables must survive being stored as [class, method] arrays.
 */
final class BootSpy
{
	public static function old(): void
	{
		$GLOBALS['novalis_package_loader_test_boots'][] = 'old';
	}

	public static function new(): void
	{
		$GLOBALS['novalis_package_loader_test_boots'][] = 'new';
	}
}
