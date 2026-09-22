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
	 * register.php runs when the test autoloader loads, so without this a
	 * test would see the monorepo's real packages alongside its fixtures.
	 */
	protected function setUp(): void
	{
		parent::setUp();

		$registry   = Registry::instance();
		$reflection = new \ReflectionClass($registry);

		foreach (['candidates', 'pinned', 'segments'] as $property) {
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

