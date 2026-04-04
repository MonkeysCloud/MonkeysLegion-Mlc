# MLC Multi-Format Support

**MonkeysLegion MLC** is no longer restricted to `.mlc` files. While the `.mlc` format remains the "first-class citizen" with full support for environment variable expansion, recursive includes, and advanced syntax, you can now load and merge configuration from **JSON**, **YAML**, and **PHP** files natively.

---

## 🏗️ Architecture: The Composite Parser

Multi-format support is achieved through the `CompositeParser`. Instead of a single parser, the `Loader` can be given a `CompositeParser` that delegates to specialized parsers based on file extensions.

### Basic Setup

To enable multi-format support, register the parsers you need:

```php
use MonkeysLegion\Mlc\Loader;
use MonkeysLegion\Mlc\Parsers\MlcParser;
use MonkeysLegion\Mlc\Parsers\JsonParser;
use MonkeysLegion\Mlc\Parsers\YamlParser;
use MonkeysLegion\Mlc\Parsers\PhpParser;
use MonkeysLegion\Mlc\Parsers\CompositeParser;
use MonkeysLegion\Env\EnvManager;
use MonkeysLegion\Env\Loaders\DotenvLoader;
use MonkeysLegion\Env\Repositories\NativeEnvRepository;

// 1. Create the base MLC parser with environment support
$env = new EnvManager(new DotenvLoader(), new NativeEnvRepository());
$mlcParser = new MlcParser($env, __DIR__ . '/config');

// 2. Wrap it in a CompositeParser and register extensions
$composite = new CompositeParser($mlcParser);
$composite->registerParser('json', new JsonParser());
$composite->registerParser('yaml', new YamlParser());
$composite->registerParser('yml',  new YamlParser());
$composite->registerParser('php',  new PhpParser());

// 3. Pass the composite to the Loader
$loader = new Loader($composite, __DIR__ . '/config');
```

---

## 📂 Supported Formats

### 1. `.mlc` (The Standard)
The native format. Optimized for human readability and flexibility.
- **Features**: Sections, Environment Variables (`${VAR}`), Cross-Key References, Recursive Includes (`@include`).
- **Best for**: Main application configuration, infrastructure settings.

### 2. `.json` (The Interoperable)
Standard JSON files parsed via PHP's native `json_decode`.
- **Details**: Must be a valid JSON object or array.
- **Note**: Does **not** support environment variable expansion or includes. It is treated as static data.
- **Best for**: Legacy configs, data exported from other tools.

### 3. `.php` (The Dynamic)
Standard PHP files that `return` an array.
- **Details**: The file is `included` by the parser. It must return a PHP `array`.
- **Best for**: Complex configuration logic that requires PHP code (loops, conditions).

### 4. `.yaml` / `.yml` (The Lightweight)
Native, lightweight YAML parsing without external dependencies like `symfony/yaml`.
- **Details**: Supports basic indentation-based key-value pairs and nesting.
- **Note**: This is a **subset** of YAML. For extremely complex YAML features (anchors, aliases), consider using a dedicated Symfony YAML bridge if you find the native parser too limited.
- **Best for**: Simple, clean configuration structures.

---

## 🔗 Cross-Format Inclusions

One of the most powerful features of the `CompositeParser` is that it enables **cross-format inclusions**. This allows a `.mlc` file to include other supported file formats like `.json`, `.yaml`, or `.php`.

### Example

```mlc
# app.mlc
app_name = "My Application"

# Native MLC include
@include database.mlc

# Cross-format includes (Requires CompositeParser)
@include extra_config.json
@include "legacy_settings.yaml"
```

### ⚠️ Edge Cases & Behavior

When using cross-format inclusions, keep these rules in mind:

1. **Hierarchy Only**: Only `.mlc` files support the `@include` keyword. You **cannot** include another file from within a `.json`, `.yaml`, or `.php` file.
2. **Static Loading**: When a `.json`, `.yaml`, or `.php` file is included, its content is loaded as static data. **Environment variable expansion (`${VAR}`) is NOT supported** inside these files.
3. **No Recursive Includes from Non-MLC**: Because non-MLC formats don't support `@include`, the inclusion chain ends there. 
   - ✅ `main.mlc` -> `database.mlc` -> `config.json`
   - ❌ `main.mlc` -> `config.json` -> `nested.mlc` (JSON parser ignores the keyword)
4. **Namespace Management**: Included data is merged into the current scope of the MLC parser. If you include a JSON object at the top level, its keys will be at the root of your configuration.

---

## 🔍 Extension Auto-Detection

When you call `$loader->load(['app'])`, the loader will check for files in the following priority order:

1. `app.mlc`
2. `app.json`
3. `app.yaml`
4. `app.yml`
5. `app.php`

The first file found wins. You can also bypass auto-detection by specifying the extension explicitly:

```php
$config = $loader->load(['database.json', 'services.yaml']);
```

---

## 🚀 Standardization

Regardless of the source format, all data is normalized into the same high-performance `Config` engine. This means:
- You can merge a `.mlc` file with a `.json` file.
- All values are accessible via the same dot-notation: `$config->get('database.host')`.
- All values can be pre-compiled into the **OPcache bytecode cache** for zero-overhead production performance.

---

## 🛡️ Security

Every native parser (`JsonParser`, `YamlParser`, `PhpParser`) inherits the same security hardening:
- **Path Traversal Protection**: Prevents loading files outside the intended scope.
- **Permission Auditing**: Detects and warns about world-writable configuration files.
- **Strict Mode**: Can be configured to throw exceptions on security risks instead of just warning.

---

## 🛠️ Validation Tool (`mlc-check`)

The `mlc-check` CLI tool automatically detects and validates all supported formats.

```bash
# Validates all .mlc, .json, .yaml, and .php files in the directory
php bin/mlc-check ./config
```
