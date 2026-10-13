<?php

declare(strict_types=1);

namespace Navas\PackageLoader\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The plain helpers in preflight.php. The preflight itself runs against real
 * Composer installs in ComposerCopiesTest.
 */
final class PreflightTest extends TestCase
{
	private string $dir = '';

	public static function setUpBeforeClass(): void
	{
		require_once dirname(__DIR__) . '/preflight.php';
	}

	protected function tearDown(): void
	{
		if ($this->dir !== '' && is_dir($this->dir)) {
			exec('rm -rf ' . escapeshellarg($this->dir));
		}

		parent::tearDown();
	}

	public function test_plugin_dirs_maps_basenames_and_skips_the_rest(): void
	{
		$dirs = navas_package_loader_plugin_dirs(
			[
				'polaris-security/polaris-security.php',
				'polaris-seo/polaris-seo.php',
				'polaris-security/polaris-security.php',
				'hello.php',
				'../escape/x.php',
				'/abs/x.php',
				42,
				'',
			],
			'/srv/wp-content/plugins/'
		);

		$this->assertSame(
			['/srv/wp-content/plugins/polaris-security', '/srv/wp-content/plugins/polaris-seo'],
			$dirs
		);
	}

	public function test_newest_copy_picks_the_highest_version(): void
	{
		$this->dir = sys_get_temp_dir() . '/navas-preflight-' . bin2hex(random_bytes(4));
		$own       = $this->copy('own', '0.1.0');
		$this->copy('plugin-a/vendor/navas/package-loader', '0.3.0');
		$this->copy('plugin-b/vendor/navas/package-loader', 'v0.2.0');
		mkdir($this->dir . '/plugin-c');

		$best = navas_package_loader_newest_copy(
			[$this->dir . '/plugin-b', $this->dir . '/plugin-a', $this->dir . '/plugin-c'],
			$own
		);

		$this->assertSame($this->dir . '/plugin-a/vendor/navas/package-loader', $best);
	}

	public function test_newest_copy_falls_back_to_the_callers_copy(): void
	{
		$this->dir = sys_get_temp_dir() . '/navas-preflight-' . bin2hex(random_bytes(4));
		$own       = $this->copy('own', '0.2.0');
		$this->copy('plugin-a/vendor/navas/package-loader', '0.1.0');

		$this->assertSame($own, navas_package_loader_newest_copy([$this->dir . '/plugin-a', $this->dir . '/missing'], $own));
	}

	private function copy(string $relative, string $version): string
	{
		$path = $this->dir . '/' . $relative;
		mkdir($path, 0777, true);
		file_put_contents($path . '/package-loader.php', '<?php');
		file_put_contents($path . '/version.php', '<?php return ' . var_export($version, true) . ';');

		return $path;
	}
}
