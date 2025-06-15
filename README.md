# MonkeysLegion MLC Config Loader

[![Packagist Version](https://img.shields.io/packagist/v/monkeyscloud/monkeyslegion-mlc)](https://packagist.org/packages/monkeyscloud/monkeyslegion-mlc)
[![License](https://img.shields.io/packagist/l/monkeyscloud/monkeyslegion-mlc)](LICENSE)

`.mlc` is MonkeysLegion’s simple, dot-notation config format with first-class support for layered `.env` files.

---

## Features

- **Nested keys** via `.` (e.g. `db.host`, `cache.redis.timeout`)
- **Type-aware values**: strings, ints, floats, booleans, null
- **`env()` helper**—pull in environment variables with optional defaults
- **Merge multiple files** in load order, later files override earlier ones
- **Layered `.env` support**:
    1. `.env`
    2. `.env.local`
    3. `.env.{APP_ENV}`
    4. `.env.{APP_ENV}.local`
- **Zero-magic**: only requires PHP 8.4+ and `vlucas/phpdotenv`

---

## Installation

```bash
composer require monkeyscloud/monkeyslegion-mlc
```

## Quick Start
1.	Create your .mlc files in config/:
```php
# config/app.mlc
app.name         = "MyApp"
app.debug        = true
features.signup  = env("ENABLE_SIGNUP", true)
```
```php
# config/database.mlc
connections.mysql.dsn      = env("DB_DSN", "mysql:host=127.0.0.1;dbname=myapp")
connections.mysql.username = env("DB_USER", "root")
connections.mysql.password = env("DB_PASS", null)
```
2.	Bootstrap the loader (e.g. in your DI container):
```php
use MonkeysLegion\Mlc\Parser;
use MonkeysLegion\Mlc\Loader;

$parser = new Parser();
$loader = new Loader(
    $parser,
    base_path('config'),   // your config directory
    base_path()            // optional .env directory (defaults to config/)
);

// Load & merge `app.mlc` + `database.mlc`
$config = $loader->load(['app', 'database']);
```
3.	Retrieve values:
```php
$appName   = $config->get('app.name', 'Unknown');
$debugMode = $config->get('app.debug', false);
$dsn       = $config->get('connections.mysql.dsn');
```

## .mlc Syntax
Each line is a key/value pair. Keys use dot-notation for nesting. Values support:
- Strings: either unquoted (foo) or quoted ("foo", 'foo')
- Numbers: integers (42) or floats (3.14)
- Booleans: true / false
- Null: null
- Env helper: env("KEY") or env("KEY", "default")

Example:
```php
service.timeout     = 30
service.endpoint    = "https://api.example.com"
feature.enabled     = false
logging.level       = env("LOG_LEVEL", "info")
```

## Layered .env Loading
By default, Loader will call vlucas/phpdotenv to load:
1.	.env
2.	.env.local
3.	.env.{APP_ENV}  (where APP_ENV = $_ENV['APP_ENV'] ?? 'dev')
4.	.env.{APP_ENV}.local

Later files override earlier ones. This keeps 12-factor best practices while allowing per-environment overrides.

## Advanced Usage
- Custom .env directory: pass a different $envDir when constructing Loader.
- Manual parsing: use Parser directly to transform a single .mlc file into an array.
- Inspect all data:
```php
$all = $config->toArray();    // get the full merged config as an array
```
## Contributing
1.	Fork the repo
2.	Create your feature branch (git checkout -b feat/foo)
3.	Commit your changes (git commit -m 'feat: add foo')
4.	Push to the branch (git push origin feat/foo)
5.	Open a Pull Request

Please follow PSR-12 and include tests for any new features.

### License

Released under the MIT License.
See LICENSE for details.