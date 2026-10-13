<?php

/**
 * Runs one load sequence in a fresh PHP process and prints the outcome as JSON.
 *
 * Usage: php run.php <workspace> <step>...
 * A step is a host directory name (its vendor/autoload.php is required, as a
 * plugin or theme main file would), `plugins_loaded` (WordPress fires that
 * action), `use` (code touches Acme\Widgets\Widget), or
 * `preflight:<host>,<host>` (an mu-plugin runs the first host's preflight.php
 * over those hosts' directories, before any of them loads), or
 * `preflight-registered` (preflight over no directories, after copies have
 * already registered — a project-wide autoloader loaded from wp-config.php).
 *
 * $wp_actions and _doing_it_wrong() stand in for WordPress's own.
 */

declare(strict_types=1);

$GLOBALS['wp_actions']      = [];
$GLOBALS['fixture_notices'] = [];

function _doing_it_wrong(string $function_name, string $message, string $version): void
{
	$GLOBALS['fixture_notices'][] = $function_name . ': ' . $message;
}

$workspace = $argv[1];

foreach (array_slice($argv, 2) as $step) {
	if ($step === 'plugins_loaded') {
		$GLOBALS['wp_actions']['plugins_loaded'] = 1;
		continue;
	}

	if ($step === 'use') {
		// Which copy (if any) the early use got, before later steps run.
		$GLOBALS['fixture_used'] = class_exists('Acme\Widgets\Widget')
			? constant('Acme\Widgets\Widget::VERSION')
			: null;
		continue;
	}

	if ($step === 'preflight-registered') {
		\Navas\PackageLoader\Registry::instance()->preflight([]);
		continue;
	}

	if (str_starts_with($step, 'preflight:')) {
		$hosts = explode(',', substr($step, strlen('preflight:')));
		require $workspace . '/' . $hosts[0] . '/vendor/navas/package-loader/preflight.php';
		$GLOBALS['fixture_preflight'] = navas_package_loader_preflight(
			array_map(static fn(string $host): string => $workspace . '/' . $host, $hosts)
		);
		continue;
	}

	require $workspace . '/' . $step . '/vendor/autoload.php';
}

$registry = \Navas\PackageLoader\Registry::instance();
$class    = new ReflectionClass('Acme\Widgets\Widget');

echo json_encode([
	'loaded'     => $class->getConstant('VERSION'),
	'loaded_by'  => basename(dirname($class->getFileName(), 6)),
	'version'    => $registry->version('acme/widgets'),
	'candidates' => array_values($registry->candidates('acme/widgets')),
	'notices'    => $GLOBALS['fixture_notices'],
	'preflight'  => $GLOBALS['fixture_preflight'] ?? null,
	'used'       => $GLOBALS['fixture_used'] ?? null,
]);
