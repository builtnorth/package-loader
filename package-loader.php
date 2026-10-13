<?php
/**
 * Siratik Package Loader — version coordinator for bundled packages.
 *
 * Loaded eagerly via composer's `files` autoload, so every plugin that vendors
 * a participating package runs this with no action on the plugin author's part.
 * The first copy to run declares the Registry and every later copy defers to it.
 *
 * Modelled on Action Scheduler's ActionScheduler_Versions: every bundled copy
 * registers while plugin files load, the newest wins once plugins_loaded
 * fires, and a theme's copy (themes load after plugins_loaded) is used only
 * when no plugin bundles that package.
 *
 * @package Siratik\PackageLoader
 */

declare(strict_types=1);

namespace Siratik\PackageLoader;

// Autoloading is deliberately disabled on this check. By the time a second
// copy's `files` entry runs, another copy's PSR-4 rules may already be
// registered, and an autoloading class_exists() would resolve Registry through
// them — declaring nothing here while leaving the winner ambiguous. The same
// form action-scheduler.php uses for ActionScheduler_Versions.
if (! class_exists('Siratik\PackageLoader\Registry', false)) {
	/**
	 * Resolves which bundled copy of a shared package a request should use.
	 *
	 * First copy loaded declares this class and owns coordination for the
	 * request. That makes the public surface permanent: it can never be
	 * replaced by a newer copy, because the newer copy's declaration is
	 * skipped. Treat every method below as frozen — additive changes only,
	 * and anything incompatible ships as a new class name.
	 */
	final class Registry
	{
		/**
		 * Public surface version, for packages that need to detect a Registry
		 * older than the features they rely on and fall back gracefully.
		 */
		public const API_VERSION = 2;

		private static ?Registry $instance = null;

		/**
		 * Registered candidates, keyed by package name.
		 *
		 * @var array<string, list<array{version: string, root: string, psr4: array<string, string>}>>
		 */
		private array $candidates = [];

		/**
		 * Package name => version string, once a class has actually loaded.
		 *
		 * @var array<string, string>
		 */
		private array $pinned = [];

		/**
		 * First namespace segment => package names declaring a prefix under it.
		 *
		 * Keeps autoload() to a single array lookup for the overwhelmingly
		 * common case of a class that belongs to nobody here.
		 *
		 * @var array<string, array<string, true>>
		 */
		private array $segments = [];

		/**
		 * Packages registered by preflight(), whose early use is expected.
		 *
		 * @var array<string, true>
		 */
		private array $preflighted = [];

		private function __construct()
		{
		}

		public static function instance(): Registry
		{
			return self::$instance ??= new self();
		}

		/**
		 * Register this copy of a package as a candidate.
		 *
		 * Called from each participating package's own `files` bootstrap, so it
		 * runs eagerly at vendor/autoload.php time with no hook involved.
		 *
		 * @param array{package: string, version: string, root: string, psr4?: array<string, string>} $args
		 */
		public function register(array $args): void
		{
			$package = $args['package'] ?? '';
			$root    = rtrim($args['root'] ?? '', '/\\');

			if ('' === $package || '' === $root) {
				return;
			}

			// Composer ids a `files` entry by md5("<package>:<path>"), which is
			// the same for every plugin's copy, and skips an id it has already
			// run. Clearing it lets the next bundler's register.php run too.
			// Requires `package` to be the Composer package name.
			unset($GLOBALS['__composer_autoload_files'][md5($package . ':register.php')]);

			// Composer prepends each plugin's ClassLoader as it loads, so a
			// bundler arriving after us would otherwise sit ahead in the SPL
			// chain and resolve our namespaces itself. Done even for a copy
			// ignored below: its ClassLoader is in the chain either way.
			$this->install();

			$version = self::normalize_version((string) ($args['version'] ?? ''));

			foreach ($this->candidates[$package] ?? [] as $candidate) {
				// The same copy twice, or another copy of the same version.
				if ($candidate['root'] === $root || $candidate['version'] === $version) {
					return;
				}
			}

			// Only themes register after plugins_loaded. Like Action
			// Scheduler, a theme's copy is used only when no plugin bundles
			// the package, never to outrank one.
			if (self::plugins_loaded() && isset($this->candidates[$package])) {
				return;
			}

			$this->candidates[$package][] = [
				'version' => $version,
				'root'    => $root,
				'psr4'    => $args['psr4'] ?? [],
			];

			foreach (array_keys($args['psr4'] ?? []) as $prefix) {
				$segment = strstr($prefix, '\\', true);
				if (false !== $segment) {
					$this->segments[$segment][$package] = true;
				}
			}
		}

		/**
		 * Register every bundled copy in the given plugin directories now,
		 * before those plugins load.
		 *
		 * For code that must run before plugins_loaded (an mu-plugin): with
		 * every active plugin's copy registered up front, the newest copy
		 * wins exactly as it would once plugins_loaded fires, so using a
		 * package early no longer locks in an older one. Reads each plugin's
		 * vendor/composer/installed.php (Composer's own data file) to find
		 * its packages and includes their register.php — the same file
		 * Composer runs when the plugin loads — so no plugin code runs.
		 * When the plugins load later, register() ignores the roots it has
		 * already seen.
		 *
		 * Every package registered by the time it returns counts as
		 * preflighted, including copies registered before the call (a
		 * project-wide autoloader loaded from wp-config.php registers every
		 * copy up front; pass no directories in that case). Early use of a
		 * preflighted package raises no _doing_it_wrong().
		 *
		 * Added in API_VERSION 2.
		 *
		 * @param list<string> $plugin_dirs Absolute plugin directories.
		 */
		public function preflight(array $plugin_dirs): void
		{
			foreach ($plugin_dirs as $dir) {
				if (! is_string($dir) || '' === $dir) {
					continue;
				}

				$installed_file = rtrim($dir, '/\\') . '/vendor/composer/installed.php';
				if (! is_file($installed_file)) {
					continue;
				}

				$installed = require $installed_file;
				$packages  = is_array($installed) && is_array($installed['versions'] ?? null)
					? $installed['versions']
					: [];

				foreach ($packages as $name => $info) {
					$path = is_array($info) && is_string($info['install_path'] ?? null) ? $info['install_path'] : '';
					if ('' === $path || ! is_file($path . '/register.php')) {
						continue;
					}

					self::include_registration($path . '/register.php');
				}
			}

			foreach (array_keys($this->candidates) as $package) {
				$this->preflighted[$package] = true;
			}
		}

		/**
		 * Run a package's register.php in its own scope.
		 */
		private static function include_registration(string $file): void
		{
			include $file;
		}

		/**
		 * Put this Registry's autoloader at the front of the SPL chain.
		 *
		 * Safe to call repeatedly — every register() does, because composer
		 * prepends each bundling plugin's own ClassLoader as it loads.
		 */
		public function install(): void
		{
			spl_autoload_unregister([$this, 'autoload']);
			spl_autoload_register([$this, 'autoload'], true, true);
		}

		/**
		 * Resolve a class from the newest registered copy that declares it.
		 */
		public function autoload(string $class): void
		{
			$segment = strstr($class, '\\', true);

			if (false === $segment || ! isset($this->segments[$segment])) {
				return;
			}

			foreach (array_keys($this->segments[$segment]) as $package) {
				$winner = $this->winner($package);

				if (null === $winner) {
					continue;
				}

				// Longest prefix wins, so a package declaring both Foo\ and
				// Foo\Bar\ maps Foo\Bar\Baz through the more specific one.
				$best_prefix = '';
				$best_dir    = '';

				foreach ($winner['psr4'] as $prefix => $dir) {
					if (
						strlen($prefix) > strlen($best_prefix)
						&& str_starts_with($class, $prefix)
					) {
						$best_prefix = $prefix;
						$best_dir    = $dir;
					}
				}

				if ('' === $best_prefix) {
					continue;
				}

				$relative = substr($class, strlen($best_prefix));
				$file     = $winner['root'] . '/' . trim($best_dir, '/\\') . '/'
					. str_replace('\\', '/', $relative) . '.php';

				if (is_file($file)) {
					if (
						! isset($this->pinned[$package])
						&& ! isset($this->preflighted[$package])
						&& ! self::plugins_loaded()
					) {
						self::report_early_use($class, $package, $winner['version']);
					}

					// Pin before the require: anything the loaded file pulls in
					// must resolve against this same copy.
					$this->pinned[$package] = $winner['version'];
					require $file;

					return;
				}
			}
		}

		/**
		 * Version of a package that actually won this request, or null if it
		 * has not been resolved yet.
		 */
		public function version(string $package): ?string
		{
			if (isset($this->pinned[$package])) {
				return $this->pinned[$package];
			}

			$winner = $this->winner($package);

			return $winner['version'] ?? null;
		}

		/**
		 * Every version registered for a package, for diagnostics.
		 *
		 * @return array<string, string> Root path => version.
		 */
		public function candidates(string $package): array
		{
			$out = [];

			foreach ($this->candidates[$package] ?? [] as $candidate) {
				$out[$candidate['root']] = $candidate['version'];
			}

			return $out;
		}

		/**
		 * Highest registered version of a package — unless one is already
		 * pinned, in which case the pin holds for the rest of the request.
		 *
		 * @return array{version: string, root: string, psr4: array<string, string>}|null
		 */
		private function winner(string $package): ?array
		{
			$candidates = $this->candidates[$package] ?? [];

			if ([] === $candidates) {
				return null;
			}

			$pin   = $this->pinned[$package] ?? null;
			$best  = null;

			foreach ($candidates as $candidate) {
				// Mixing two copies' classes in one request is worse than
				// running an older copy consistently, so once a class has
				// loaded the version is fixed even if a newer copy registers
				// afterwards.
				if (null !== $pin) {
					if ($candidate['version'] === $pin) {
						return $candidate;
					}

					continue;
				}

				if (null === $best || version_compare($candidate['version'], $best['version'], '>')) {
					$best = $candidate;
				}
			}

			return $best;
		}

		/**
		 * Whether WordPress has fired plugins_loaded, i.e. every plugin's copy
		 * has registered. False outside WordPress (plain PHPUnit, wp-config).
		 *
		 * Reads did_action()'s own global rather than calling it: this runs on
		 * every class load, and a test suite stubbing did_action() for its own
		 * hooks would otherwise see an unexpected call.
		 */
		private static function plugins_loaded(): bool
		{
			return ! empty($GLOBALS['wp_actions']['plugins_loaded']);
		}

		/**
		 * Whether WordPress's hook API is loaded, so an early class load is a
		 * plugin file doing it rather than a unit test or wp-config.
		 */
		private static function wordpress_loading(): bool
		{
			return isset($GLOBALS['wp_actions']) && function_exists('_doing_it_wrong');
		}

		/**
		 * A class loaded while plugin files are still loading locks its package
		 * to the newest copy registered so far; a plugin loading later with a
		 * newer copy can't win. WordPress shows this only with WP_DEBUG on.
		 */
		private static function report_early_use(string $class, string $package, string $version): void
		{
			if (! self::wordpress_loading()) {
				return;
			}

			_doing_it_wrong(
				$class,
				sprintf(
					'%1$s was loaded before plugins_loaded, so %2$s is locked to %3$s for this request even if a plugin loaded later bundles a newer copy. Use a hook instead of running package code while plugin files load.',
					$class,
					$package,
					$version
				),
				'0.1.0'
			);
		}

		/**
		 * `v1.2` and `1.2.0` compare as equal; anything else is left to
		 * version_compare() as given.
		 */
		private static function normalize_version(string $version): string
		{
			$version = ltrim(trim($version), 'vV');

			if (1 === preg_match('/^(\d+)(?:\.(\d+))?$/', $version, $parts)) {
				return $parts[1] . '.' . ($parts[2] ?? '0') . '.0';
			}

			return '' === $version ? '0.0.0' : $version;
		}
	}

	Registry::instance()->install();
}
