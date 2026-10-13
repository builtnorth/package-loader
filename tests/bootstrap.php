<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap.
 *
 * The Registry is pure PHP and touches WordPress only through
 * function_exists()-guarded calls, so these tests need no WP_Mock and no core
 * stand-ins — just the class itself and PHPUnit.
 *
 * @package Siratik\PackageLoader
 */

// Falls back to the monorepo root's autoloader when this package has no
// standalone vendor/ install (the normal dev setup — see "Autoloader
// Architecture" in the root CLAUDE.md).
$autoload = __DIR__ . '/../vendor/autoload.php';
$using_root_autoloader = ! file_exists($autoload);
if ($using_root_autoloader) {
	$autoload = __DIR__ . '/../../../vendor/autoload.php';
}

if (! file_exists($autoload)) {
	fwrite(STDERR, "Run `composer install` before the test suite.\n");
	exit(1);
}

require $autoload;

// The root autoloader only carries this package's own runtime `autoload`
// mapping, never a dependency's `autoload-dev` — register the Tests namespace
// by hand when running under the root autoloader.
if ($using_root_autoloader) {
	spl_autoload_register(static function (string $class): void {
		$prefix = 'Siratik\\PackageLoader\\Tests\\';
		if (! str_starts_with($class, $prefix)) {
			return;
		}
		$relative = substr($class, strlen($prefix));
		$file     = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
		if (file_exists($file)) {
			require $file;
		}
	});
}

// This package ships as a composer `files` entry, so the root autoloader may
// not have picked it up yet on a fresh checkout. Requiring it directly is
// idempotent — that is the property the whole design rests on.
require_once __DIR__ . '/../package-loader.php';
