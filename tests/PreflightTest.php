<?php

declare(strict_types=1);

namespace Siratik\PackageLoader\Tests;

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
		$dirs = siratik_package_loader_plugin_dirs(
			[
				'kitaris-security/kitaris-security.php',
				'kitaris-seo/kitaris-seo.php',
				'kitaris-security/kitaris-security.php',
				'hello.php',
				'../escape/x.php',
				'/abs/x.php',
				42,
				'',
			],
			'/srv/wp-content/plugins/'
		);

		$this->assertSame(
			['/srv/wp-content/plugins/kitaris-security', '/srv/wp-content/plugins/kitaris-seo'],
			$dirs
		);
	}

	public function test_newest_copy_picks_the_highest_version(): void
	{
		$this->dir = sys_get_temp_dir() . '/siratik-preflight-' . bin2hex(random_bytes(4));
		$own       = $this->copy('own', '0.1.0');
		$this->copy('plugin-a/vendor/siratik/package-loader', '0.3.0');
		$this->copy('plugin-b/vendor/siratik/package-loader', 'v0.2.0');
		mkdir($this->dir . '/plugin-c');

		$best = siratik_package_loader_newest_copy(
			[$this->dir . '/plugin-b', $this->dir . '/plugin-a', $this->dir . '/plugin-c'],
			$own
		);

		$this->assertSame($this->dir . '/plugin-a/vendor/siratik/package-loader', $best);
	}

	public function test_newest_copy_falls_back_to_the_callers_copy(): void
	{
		$this->dir = sys_get_temp_dir() . '/siratik-preflight-' . bin2hex(random_bytes(4));
		$own       = $this->copy('own', '0.2.0');
		$this->copy('plugin-a/vendor/siratik/package-loader', '0.1.0');

		$this->assertSame($own, siratik_package_loader_newest_copy([$this->dir . '/plugin-a', $this->dir . '/missing'], $own));
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
