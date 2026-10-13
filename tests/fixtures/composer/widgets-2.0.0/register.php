<?php
// Same shape as every real package's register.php.
use Siratik\PackageLoader\Registry;

if (class_exists(Registry::class, false)) {
	Registry::instance()->register([
		'package' => 'acme/widgets',
		'version' => (string) require __DIR__ . '/version.php',
		'root'    => __DIR__,
		'psr4'    => ['Acme\\Widgets\\' => 'inc/classes/'],
	]);
}
