<?php
/**
 * Preflight for code that must run before plugins_loaded (an mu-plugin).
 *
 * Not a Composer `files` entry: an early consumer requires it from its own
 * bundled copy and calls siratik_package_loader_preflight() once, before using
 * any participating package. Everything here is plain functions guarded by
 * function_exists(), and touches no package class.
 *
 * @package Siratik\PackageLoader
 */

declare(strict_types=1);

if (! function_exists('siratik_package_loader_preflight')) {
	/**
	 * Register every active plugin's bundled copies, so a package used before
	 * plugins_loaded resolves to the same newest copy it would afterwards.
	 *
	 * Loads the newest package-loader copy among the active plugins first, as
	 * the first copy declared owns coordination for the whole request.
	 *
	 * @param list<string>|null $plugin_dirs Absolute plugin directories; the
	 *                                       active plugins when null.
	 * @return bool Whether the preflight ran. False when an older Registry
	 *              without preflight() already owns this request, in which
	 *              case the caller must not use package classes yet.
	 */
	function siratik_package_loader_preflight(?array $plugin_dirs = null): bool
	{
		$plugin_dirs ??= siratik_package_loader_active_plugin_dirs();

		if (! class_exists('Siratik\PackageLoader\Registry', false)) {
			require_once siratik_package_loader_newest_copy($plugin_dirs, __DIR__) . '/package-loader.php';
		}

		$registry = \Siratik\PackageLoader\Registry::instance();
		if (! method_exists($registry, 'preflight')) {
			return false;
		}

		$registry->preflight($plugin_dirs);

		return true;
	}
}

if (! function_exists('siratik_package_loader_active_plugin_dirs')) {
	/**
	 * Directories of the active plugins, read from the options WordPress
	 * itself loads plugins from (both are available to mu-plugins).
	 *
	 * Plugins activated during this request join on the next one, and a
	 * plugin WordPress has paused in recovery mode is still listed — the same
	 * trade-offs Jetpack's autoloader makes.
	 *
	 * @return list<string>
	 */
	function siratik_package_loader_active_plugin_dirs(): array
	{
		if (! function_exists('get_option') || ! defined('WP_PLUGIN_DIR')) {
			return [];
		}

		$active   = get_option('active_plugins', []);
		$sitewide = function_exists('is_multisite') && is_multisite()
			? array_keys((array) get_site_option('active_sitewide_plugins', []))
			: [];

		return siratik_package_loader_plugin_dirs(
			array_merge(is_array($active) ? $active : [], $sitewide),
			WP_PLUGIN_DIR
		);
	}
}

if (! function_exists('siratik_package_loader_plugin_dirs')) {
	/**
	 * Map plugin basenames (folder/file.php) to absolute plugin directories.
	 *
	 * Single-file plugins have no vendor/ of their own and are skipped, as
	 * is anything that is not a plain relative basename.
	 *
	 * @param array<mixed> $plugins    Plugin basenames.
	 * @param string       $plugin_dir WP_PLUGIN_DIR.
	 * @return list<string>
	 */
	function siratik_package_loader_plugin_dirs(array $plugins, string $plugin_dir): array
	{
		$dirs = [];

		foreach ($plugins as $plugin) {
			if (! is_string($plugin) || '' === $plugin || str_contains($plugin, '..')) {
				continue;
			}

			$folder = dirname(str_replace('\\', '/', $plugin));
			if ('.' === $folder || '' === $folder || str_starts_with($folder, '/')) {
				continue;
			}

			$dirs[rtrim($plugin_dir, '/\\') . '/' . $folder] = true;
		}

		return array_keys($dirs);
	}
}

if (! function_exists('siratik_package_loader_newest_copy')) {
	/**
	 * Directory of the newest package-loader copy among the given plugins.
	 *
	 * @param list<string> $plugin_dirs Absolute plugin directories.
	 * @param string       $fallback    Copy to use when none is found (the caller's own).
	 */
	function siratik_package_loader_newest_copy(array $plugin_dirs, string $fallback): string
	{
		$best         = $fallback;
		$best_version = siratik_package_loader_copy_version($fallback);

		foreach ($plugin_dirs as $dir) {
			$copy    = rtrim((string) $dir, '/\\') . '/vendor/siratik/package-loader';
			$version = siratik_package_loader_copy_version($copy);

			if (null !== $version && (null === $best_version || version_compare($version, $best_version, '>'))) {
				$best         = $copy;
				$best_version = $version;
			}
		}

		return $best;
	}
}

if (! function_exists('siratik_package_loader_copy_version')) {
	/**
	 * Version of a package-loader copy, or null when the directory is not one.
	 */
	function siratik_package_loader_copy_version(string $copy): ?string
	{
		if (! is_file($copy . '/package-loader.php') || ! is_file($copy . '/version.php')) {
			return null;
		}

		$version = require $copy . '/version.php';

		return is_string($version) && '' !== $version ? ltrim($version, 'vV') : null;
	}
}
