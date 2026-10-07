# Navas Package Loader

Version coordinator for WordPress packages that get bundled by more than one plugin.

## The problem

WordPress plugins each ship their own `vendor/` directory. When two plugins bundle the same
package at different versions, PHP can only ever have one copy of a given class in a request —
and Composer's autoloader is not version-aware. Whichever copy resolves first wins, which in
practice comes down to plugin load order. An older copy silently winning means missing fixes,
missing methods, and bugs that reproduce even after an update.

Plugin authors cannot coordinate with each other, and they shouldn't have to.

## What this does

Packages that depend on `navas/package-loader` get a shared registry. Each bundled copy
registers itself with its version; the registry puts its own autoloader at the front of the SPL
chain and resolves classes from the **newest registered copy**, regardless of load order.

It follows Action Scheduler's model (`ActionScheduler_Versions`):

- **Every copy registers.** Composer runs a `files` entry once per id, and the id
  (`md5("<package>:register.php")`) is the same in every plugin's `vendor/`. The registry clears
  that id on each registration, so the next plugin's copy runs too.
- **The newest wins once `plugins_loaded` fires.** By then every plugin file, and so every
  bundled copy, has loaded. A class loaded earlier locks its package to the newest copy registered
  so far, and the registry reports it through `_doing_it_wrong()` (shown with `WP_DEBUG` on).
- **Themes never outrank plugins.** Themes load after `plugins_loaded`. A theme's copy is used
  only when no plugin bundles that package; otherwise it is ignored, even if newer.
- **Copies of the same version register once.** The first one wins.

There is nothing for a consuming plugin author to do. This package is a transitive dependency,
its bootstrap runs from Composer's `files` autoload, and registration happens automatically when
`vendor/autoload.php` is required.

## For package authors

Add the dependency and a `version.php` at your package root:

```php
<?php
// version.php
return '1.4.2';
```

Then register from a `files`-autoloaded bootstrap:

```php
<?php
// register.php — listed under autoload.files in composer.json

use Navas\PackageLoader\Registry;

if (class_exists(Registry::class, false)) {
	Registry::instance()->register([
		'package' => 'acme/widgets',
		'version' => (string) require __DIR__ . '/version.php',
		'root'    => __DIR__,
		'psr4'    => ['Acme\\Widgets\\' => 'inc/classes/'],
	]);
}
```

`package` must be the package's Composer name exactly; the registry uses it to find the
`files` id. Keep this file at your package root, **outside your PSR-4 root**. A registration file reachable through your own
package's namespace prefix makes the coordinator resolve a class name back onto it.

### Booting is not this package's job

There is deliberately no boot or init callback. Your package is started by whoever uses it, and
that already gets the right version: when a consumer calls `\Acme\Widgets\App::init()`, PHP
resolves that class name through this autoloader, which hands back the newest registered copy.
Controlling resolution *is* controlling the boot.

The one thing to avoid is touching package classes at plugin-file-load time, before every
bundling plugin has registered — the first copy to resolve is pinned for the request, and the
registry raises `_doing_it_wrong()`. Use any hook (`plugins_loaded`, `init`, anything later) and
all copies will have registered by then. A theme can use package classes as soon as its
`functions.php` runs, since `plugins_loaded` has already fired.

### Global functions

Composer's `files` autoload is eager, and function declarations are first-wins — the opposite of
the newest-wins resolution this package gives classes. Keep function bodies as one-line
forwarders to a class, so it never matters which copy declared them:

```php
if (! function_exists('acme_widgets_render')) {
	function acme_widgets_render(...$args) {
		return \Acme\Widgets\API::render(...$args);
	}
}
```

Always guard declarations with `function_exists`. An unguarded declaration is a fatal redeclare
error the moment a second plugin bundles your package.

## Diagnostics

```php
$registry = \Navas\PackageLoader\Registry::instance();

$registry->version('acme/widgets');     // version that won this request
$registry->candidates('acme/widgets');  // every registered copy: root path => version
```

## Caveats

**A class loaded before every bundler has registered pins that version for the request.** Mixing
two copies' classes is worse than consistently running an older one, so the pin holds even if a
newer copy registers later. Don't touch package classes at plugin-file-load time; wait for
`plugins_loaded`. The registry reports early loads through `_doing_it_wrong()`.

**A theme can't ship a newer copy than its plugins.** Like Action Scheduler, a theme's copy is
ignored whenever a plugin bundles the same package.

**Each package resolves on its own.** The newest copy is picked per package, not per plugin. If
plugin A bundles the newest `acme/widgets` and plugin B bundles the newest `acme/http`, the
request runs A's widgets against B's http. Declare real version constraints between packages
that depend on each other, so every combination a bundle can produce is one Composer allowed.

**Global functions come from the first copy.** `inc/public-api.php`-style `files` entries are
declared once, by whichever copy loads first; keep them as forwarders (above).

**Coordination only covers participating packages.** A third-party plugin bundling the same
package without this loader registers its own Composer autoloader, and resolution between the
two is load-order dependent again.

**The public surface is frozen.** The first copy loaded declares `Registry` and owns
coordination for the request, so a newer copy's declaration is skipped entirely. Methods are
additive-only; anything incompatible must ship under a new class name.

## Testing

```bash
composer test
```

`tests/ComposerCopiesTest.php` runs real `composer install`s of a fixture package bundled at two
versions, then loads them in each order in a fresh PHP process. It needs Composer: set
`COMPOSER_BINARY`, or have it in `vendor/bin` or on `PATH`.

## License

GPL-2.0-or-later
