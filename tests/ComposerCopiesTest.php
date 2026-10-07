<?php

declare(strict_types=1);

namespace Navas\PackageLoader\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Several real Composer installs bundling one package at different versions.
 *
 * RegistryTest calls register() directly, so it can't see what Composer does
 * between plugins: every copy of a package's register.php has the same
 * `files` id, and Composer skips an id it has already run. Each host here is
 * a real `composer install` of the fixture package plus this package (copied
 * from the working tree), and each scenario runs in a fresh PHP process via
 * fixtures/composer/run.php.
 */
final class ComposerCopiesTest extends TestCase
{
	private const HOSTS = [
		'plugin-a' => '1.0.0',
		'plugin-b' => '2.0.0',
		'plugin-c' => '1.0.0',
		'theme'    => '2.0.0',
	];

	private static string $workspace = '';

	public static function setUpBeforeClass(): void
	{
		self::$workspace = sys_get_temp_dir() . '/navas-package-loader-' . bin2hex(random_bytes(4));
		mkdir(self::$workspace);

		foreach (self::HOSTS as $host => $version) {
			self::install($host, $version);
		}
	}

	public static function tearDownAfterClass(): void
	{
		if (self::$workspace !== '' && is_dir(self::$workspace)) {
			exec('rm -rf ' . escapeshellarg(self::$workspace));
		}
	}

	public function test_newest_copy_wins_when_the_older_plugin_loads_first(): void
	{
		$result = $this->run_steps('plugin-a', 'plugin-b', 'plugins_loaded');

		$this->assertSame('2.0.0', $result['loaded']);
		$this->assertSame('plugin-b', $result['loaded_by']);
		$this->assertSame('2.0.0', $result['version']);
		$this->assertSame(['1.0.0', '2.0.0'], $result['candidates']);
		$this->assertSame([], $result['notices']);
	}

	public function test_newest_copy_wins_when_the_newer_plugin_loads_first(): void
	{
		$result = $this->run_steps('plugin-b', 'plugin-a', 'plugins_loaded');

		$this->assertSame('2.0.0', $result['loaded']);
		$this->assertSame('plugin-b', $result['loaded_by']);
		$this->assertSame('2.0.0', $result['version']);
	}

	public function test_use_before_plugins_loaded_pins_and_reports(): void
	{
		$result = $this->run_steps('plugin-a', 'use', 'plugin-b', 'plugins_loaded');

		$this->assertSame('1.0.0', $result['loaded']);
		$this->assertSame('1.0.0', $result['version']);
		$this->assertCount(1, $result['notices']);
		$this->assertStringContainsString('Acme\Widgets\Widget', $result['notices'][0]);
	}

	public function test_same_version_from_two_plugins_registers_once(): void
	{
		$result = $this->run_steps('plugin-a', 'plugin-c', 'plugins_loaded');

		$this->assertSame(['1.0.0'], $result['candidates']);
		$this->assertSame('1.0.0', $result['loaded']);
	}

	public function test_theme_copy_is_ignored_when_a_plugin_bundles_the_package(): void
	{
		$result = $this->run_steps('plugin-a', 'plugins_loaded', 'theme');

		$this->assertSame('1.0.0', $result['loaded']);
		$this->assertSame('plugin-a', $result['loaded_by']);
		$this->assertSame(['1.0.0'], $result['candidates']);
	}

	public function test_theme_copy_is_used_when_no_plugin_bundles_the_package(): void
	{
		$result = $this->run_steps('plugins_loaded', 'theme');

		$this->assertSame('2.0.0', $result['loaded']);
		$this->assertSame('theme', $result['loaded_by']);
		$this->assertSame([], $result['notices']);
	}

	/**
	 * @return array{loaded: string, loaded_by: string, version: ?string, candidates: list<string>, notices: list<string>}
	 */
	private function run_steps(string ...$steps): array
	{
		$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/composer/run.php')
			. ' ' . escapeshellarg(self::$workspace)
			. ' ' . implode(' ', array_map('escapeshellarg', $steps)) . ' 2>&1';

		exec($command, $output, $status);
		$json = implode("\n", $output);

		$this->assertSame(0, $status, $json);
		$result = json_decode($json, true);
		$this->assertIsArray($result, $json);

		return $result;
	}

	private static function install(string $host, string $version): void
	{
		$dir = self::$workspace . '/' . $host;
		mkdir($dir);

		$manifest = [
			'name'              => 'host/' . $host,
			'repositories'      => [
				[
					'type'    => 'path',
					'url'     => __DIR__ . '/fixtures/composer/widgets-' . $version,
					'options' => ['symlink' => false],
				],
				[
					'type'    => 'path',
					'url'     => dirname(__DIR__),
					'options' => ['symlink' => false, 'versions' => ['navas/package-loader' => '0.1.0']],
				],
				['packagist.org' => false],
			],
			'require'           => [
				'acme/widgets'         => $version,
				'navas/package-loader' => '0.1.0',
			],
			'minimum-stability' => 'dev',
		];
		file_put_contents($dir . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

		$command = self::composer() . ' install --no-interaction --no-progress --quiet --working-dir=' . escapeshellarg($dir) . ' 2>&1';
		exec($command, $output, $status);

		if ($status !== 0) {
			self::fail("composer install failed for {$host}:\n" . implode("\n", $output));
		}
	}

	/**
	 * Composer from COMPOSER_BINARY, this package's or the monorepo's dev
	 * install, or the one on PATH (CI runners).
	 */
	private static function composer(): string
	{
		$env = getenv('COMPOSER_BINARY');
		if (is_string($env) && $env !== '') {
			return escapeshellarg($env);
		}

		foreach ([dirname(__DIR__) . '/vendor/bin/composer', dirname(__DIR__, 3) . '/vendor/bin/composer'] as $path) {
			if (is_file($path)) {
				return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
			}
		}

		return 'composer';
	}
}
