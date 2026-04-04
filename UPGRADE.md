# Upgrading to MonkeysLegion MLC v3.0.0

MLC v3 is a major architectural update focusing on **zero-overhead performance**, **deep environment integration**, and **strict type safety**. This guide outlines the breaking changes and the necessary steps to upgrade from v2.x.

---

## 🚀 1. Constructor Signature Changes (High Impact)

To comply with the new decoupled architecture, constructors for `Loader`, `MlcParser`, and `PhpParser` have been significantly changed.

### Loader

The `Loader` now strictly requires a `ParserInterface` implementation and does not handle environment loading itself.

**Before (v2.x):**

```php
$loader = new Loader(new Parser(), $baseDir, $envDir, $cache, $autoLoadEnv);
```

**After (v3.0):**

```php
// Standard MLC usage
$loader = new Loader(new MlcParser($bootstrapper, $root), $baseDir, cache: $cache);
```

### MLC/PHP Parsers

Parsers now require an `EnvBootstrapperInterface` and the project root directory to handle environment resolution and file security correctly.

**After (v3.0):**

```php
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use MonkeysLegion\Env\Repositories\NativeEnvRepository;

$bootstrapper = new EnvManager(new DotenvLoader(), new NativeEnvRepository());
$parser = new MlcParser($bootstrapper, $rootPath);
```

***Note: The Parser class has been renamed to MlcParser; both MlcParser and PhpParser require an EnvBootstrapperInterface implementation, whereas YamlParser and JsonParser do not.***

---

## 🌍 2. Environment Management (High Impact)

MLC no longer bundles `vlucas/phpdotenv`. All environment resolution is now handled by the **MonkeysLegion-Env** package.

- **Removed**: `autoLoadEnv` and `envDir` parameters from `Loader`.
- **New**: Inject an `EnvBootstrapperInterface` into your parser. This allows you to decouple how `.env` files are loaded (or use existing global environment variables).

---

## 🧠 3. Config Immutability & Overrides (Medium Impact)

The original `set()` method and related mutation behaviors have been **REMOVED** in favor of the new **Dual-Layer Engine**.

- **Locked Base**: By default, the compiled base of a `Config` instance is immutable.
- **Runtime Overrides**: Use `override(path, value)` to apply non-destructive runtime changes.
- **Snapshots**: Use `snapshot()` to get a fresh, isolated `Config` instance containing all current overrides.

**Before (v2.x):**

```php
$config->set('app.debug', true); // Mutates current instance (REMOVED in v3)
```

**After (v3.0):**

```php
$config->override('app.debug', true); // Non-destructive override on top of base
$isolatedSnap = $config->snapshot(); // Isolated copy, overrides baked into base
```

---

## 🔒 4. Strict Security Mode (Medium Impact)

Security warnings regarding world-writable files in production have been upgraded to formal exceptions in strict mode.

- **Check**: Audit your configuration file permissions.
- **Upgrade**: Use `strictSecurity: true` in the `Loader` constructor to prevent booting if insecure files are detected.

---

## 🪝 5. Event Hooks (Low Impact)

If you were manually wrapping the `Loader` to track activity, you can now use the native hook system.

**After (v3.0):**

```php
$loader->onLoading(fn($names) => log("Loading: " . implode(',', $names)));
$loader->onLoaded(fn($config) => log("Config fully loaded"));
```

---

## 🛠️ Summary of Deprecations & Removals

- `vlucas/phpdotenv` dependency removed.
- `Loader->__construct` parameters `$envDir` and `$autoLoadEnv` removed.
- `Parser` (concrete class) renamed to `MlcParser`.
- Use `MlcParser` instead of generic `Parser` for native format.
- Use `CompositeParser` for multi-format (`.json`, `.yaml`) loading.

---

> **Tip**: Use the new `mlc-check` CLI tool to validate your configuration files for syntax and security issues after upgrading.
