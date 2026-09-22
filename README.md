# Novalis Package Loader

Version coordinator for WordPress packages that get bundled by more than one plugin.

## The problem

WordPress plugins each ship their own `vendor/` directory. When two plugins bundle the same
package at different versions, PHP can only ever have one copy of a given class in a request —
and Composer's autoloader is not version-aware. Whichever copy resolves first wins, which in
practice comes down to plugin load order. An older copy silently winning means missing fixes,
missing methods, and bugs that reproduce even after an update.

Plugin authors cannot coordinate with each other, and they shouldn't have to.

## What this does

Packages that depend on `novalis/package-loader` get a shared registry. Each bundled copy
registers itself with its version; the registry puts its own autoloader at the front of the SPL
chain and resolves classes from the **newest registered copy**, regardless of load order.

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
// bootstrap/register.php — listed under autoload.files in composer.json

use Novalis\PackageLoader\Registry;

if (class_exists(Registry::class, false)) {
	Registry::instance()->register([
		'package' => 'acme/widgets',
		'version' => (string) require dirname(__DIR__) . '/version.php',
		'root'    => dirname(__DIR__),
		'psr4'    => ['Acme\\Widgets\\' => 'inc/classes/'],
	]);
}
```

Keep this file **outside your PSR-4 root**. A registration file reachable through your own
package's namespace prefix makes the coordinator resolve a class name back onto it.

### Booting is not this package's job

There is deliberately no boot or init callback. Your package is started by whoever uses it, and
that already gets the right version: when a consumer calls `\Acme\Widgets\App::init()`, PHP
resolves that class name through this autoloader, which hands back the newest registered copy.
Controlling resolution *is* controlling the boot.

The one thing to avoid is touching package classes at plugin-file-load time, before every
bundling plugin has registered — the first copy to resolve is pinned for the request. Use any
hook (`plugins_loaded`, `init`, anything later) and all copies will have registered by then.

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
$registry = \Novalis\PackageLoader\Registry::instance();

$registry->version('acme/widgets');     // version that won this request
$registry->candidates('acme/widgets');  // every registered copy: root path => version
```

## Caveats

**A class loaded before every bundler has registered pins that version for the request.** Mixing
two copies' classes is worse than consistently running an older one, so the pin holds even if a
newer copy registers later. Don't touch package classes at plugin-file-load time; wait for
`plugins_loaded`.

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

## License

MIT
