<?php

/**
 * Runs one load sequence in a fresh PHP process and prints the outcome as JSON.
 *
 * Usage: php run.php <workspace> <step>...
 * A step is a host directory name (its vendor/autoload.php is required, as a
 * plugin or theme main file would), `plugins_loaded` (WordPress fires that
 * action), or `use` (code touches Acme\Widgets\Widget).
 *
 * did_action() and _doing_it_wrong() stand in for WordPress's own.
 */

declare(strict_types=1);

$GLOBALS['fixture_fired']   = [];
$GLOBALS['fixture_notices'] = [];

function did_action(string $hook): int
{
	return $GLOBALS['fixture_fired'][$hook] ?? 0;
}

function _doing_it_wrong(string $function_name, string $message, string $version): void
{
	$GLOBALS['fixture_notices'][] = $function_name . ': ' . $message;
}

$workspace = $argv[1];

foreach (array_slice($argv, 2) as $step) {
	if ($step === 'plugins_loaded') {
		$GLOBALS['fixture_fired']['plugins_loaded'] = 1;
		continue;
	}

	if ($step === 'use') {
		class_exists('Acme\Widgets\Widget');
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
]);
