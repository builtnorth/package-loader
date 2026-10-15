<?php

declare(strict_types=1);

namespace Siratik\PackageLoader\Tests;

use Siratik\PackageLoader\Registry;
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
	 * register.php runs when the test autoloader loads, so without this a
	 * test would see the monorepo's real packages alongside its fixtures.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$registry   = Registry::instance();
		$reflection = new \ReflectionClass($registry);

		foreach (['candidates', 'pinned', 'segments', 'prefixes'] as $property) {
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

		// The assertion above cannot fail on its own: PHP will not reload an
		// already-declared class, so Widget::which() reports 'old' whether or
		// not pinning works. OnlyInNew exists solely in the 2.0.0 fixture, so
		// resolving it is the only way to observe which copy the registry would
		// pick *now* — and under a correct pin it must refuse, rather than
		// quietly mixing a 2.0.0 class into a request already running 1.0.0.
		$this->assertFalse(
			class_exists(\Acme\Demo\OnlyInNew::class),
			'a pinned package must not resolve classes from a different copy'
		);
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

	public function test_short_and_v_prefixed_versions_compare_as_full_versions(): void
	{
		$this->register('old', '1.0');
		$this->register('new', 'v2');

		$this->assertSame([
			$this->fixtures() . '/old' => '1.0.0',
			$this->fixtures() . '/new' => '2.0.0',
		], Registry::instance()->candidates('acme/demo'));
		$this->assertSame('new', \Acme\Demo\Widget::which());
	}

	public function test_class_loads_never_call_did_action(): void
	{
		// A WP_Mock suite stubbing did_action() for its own hooks throws on
		// any other call, so the registry reads $wp_actions instead.
		eval('function did_action($hook) { throw new \\LogicException("did_action($hook) called"); }');

		$this->register('new', self::NEW);

		$this->assertSame('new', \Acme\Demo\Widget::which());
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

	public function test_class_name_with_path_characters_is_not_loaded(): void
	{
		$this->register('old', self::OLD);

		// Called directly: PHP itself rejects most such names before any
		// autoloader runs, and the guard must not depend on that.
		Registry::instance()->autoload('Acme\\Demo\\../../../new/inc/classes/Widget');

		$this->assertFalse(class_exists('Acme\\Demo\\Widget', false));
	}

	/**
	 * Stand in for WordPress: hook globals, WP_DEBUG and _doing_it_wrong().
	 */
	private function fake_wordpress(bool $debug): void
	{
		$GLOBALS['wp_actions']      = [];
		$GLOBALS['fixture_notices'] = [];
		define('WP_DEBUG', $debug);
		eval('function _doing_it_wrong($f, $m, $v) { $GLOBALS["fixture_notices"][] = $m; }');
	}

	public function test_same_version_with_different_files_is_reported(): void
	{
		$this->fake_wordpress(true);

		$this->register('old', self::OLD);
		$this->register('new', self::OLD);

		$this->assertCount(1, $GLOBALS['fixture_notices']);
		$this->assertStringContainsString('different files', $GLOBALS['fixture_notices'][0]);
		// The first copy still wins.
		$this->assertSame('old', \Acme\Demo\Widget::which());
	}

	public function test_same_version_with_identical_files_is_not_reported(): void
	{
		$this->fake_wordpress(true);

		$this->register('old', self::OLD);
		$this->register('../fixtures/old', self::OLD);

		$this->assertSame([], $GLOBALS['fixture_notices']);
	}

	public function test_same_version_is_not_reported_without_wp_debug(): void
	{
		$this->fake_wordpress(false);

		$this->register('old', self::OLD);
		$this->register('new', self::OLD);

		$this->assertSame([], $GLOBALS['fixture_notices']);
	}

	public function test_prefix_claimed_by_another_package_is_reported(): void
	{
		$this->fake_wordpress(true);

		$this->register('old', self::OLD);
		$this->register('new', self::NEW, ['package' => 'acme/fork']);

		$this->assertCount(1, $GLOBALS['fixture_notices']);
		$this->assertStringContainsString('acme/fork', $GLOBALS['fixture_notices'][0]);
		$this->assertStringContainsString('acme/demo', $GLOBALS['fixture_notices'][0]);
		// Resolution is unchanged: the first package registered serves it.
		$this->assertSame('old', \Acme\Demo\Widget::which());
	}

	public function test_prefix_claimed_by_another_package_is_not_reported_without_wp_debug(): void
	{
		$this->fake_wordpress(false);

		$this->register('old', self::OLD);
		$this->register('new', self::NEW, ['package' => 'acme/fork']);

		$this->assertSame([], $GLOBALS['fixture_notices']);
	}
}
