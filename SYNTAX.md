# MLC Syntax Reference

This page documents the `.mlc` language syntax only.

## 1. Key-Value Assignments

You can assign values using either `=` or whitespace:

```mlc
app_name = "My Application"
debug    true
port     8080
```

## 2. Sections (Nested Objects)

Use braces to create nested configuration blocks:

```mlc
database {
    host = localhost
    port = 3306

    credentials {
        user = app_user
        pass = ${DB_PASSWORD}
    }
}
```

## 3. Arrays and JSON Objects

Inline and multi-line arrays are supported. MLC supports both **Standard JSON** and **PHP-style** quoting:

```mlc
# Standard JSON arrays
allowed_ips = ["127.0.0.1", "10.0.0.1"]

# PHP-style arrays (single quotes)
public_paths = ['/api/v1', '/auth']

# Multi-line arrays are supported
features = [
    'caching',
    'validation',
    "security" # Mixed quoting is OK
]
```

JSON-style objects are also supported with flexible quoting:

```mlc
metadata = {"service": "api", 'version': 1}
```

## 4. Value Types

| MLC syntax            | Type   |
|-----------------------|--------|
| `true` / `false`      | bool   |
| `null`                | null   |
| `3306`                | int    |
| `3.14`                | float  |
| `"hello"` / `'hello'` | string |
| `[1, 2, 3]`           | array  |
| `{"a": 1}`            | array  |

## 5. Environment Variable Expansion

Use `${VAR}` to resolve environment variables:

```mlc
db_pass = ${DB_PASSWORD}
```

Use `:-` for fallback/default values:

```mlc
db_port = ${DB_PORT:-3306}
api_url = "https://${HOST:-localhost}:${PORT:-8080}/v1"
```

Supported fallback behavior:

- Strict typing for fallback literals (e.g. `${DEBUG:-false}` is boolean).
- Single-quoted and double-quoted fallback strings.
- Nested fallback expressions (e.g. `${PORT:-${DEFAULT_PORT:-8080}}`).
- Interpolation with surrounding literal text.

### Environment Functions (`env()`)

MLC also supports a functional style for environment variables:

```mlc
# Simple lookup (returns null if missing)
key = env(SECRET)

# Quoted keys are allowed
key = env("SECRET")

# With default values
port = env(PORT, 8080)
msg  = env(ERROR_MSG, 'Internal error')

# Used within strings
url = "http://env(HOST, localhost):env(PORT, 8080)"
```

## 6. Cross-Key References

Values can reference keys defined in the same file:

```mlc
base_url = "https://api.example.com"
health   = "${base_url}/health"
```

## 7. Includes

Use `@include` to load another `.mlc` file.  
At least one space is required between `@include` and the path.

| Syntax             | Example                         | Note                                                     |
|--------------------|---------------------------------|----------------------------------------------------------|
| Unquoted           | `@include base.mlc`             | Recommended for simple paths without spaces.             |
| Quoted             | `@include "extra settings.mlc"` | Use single or double quotes; required when path has spaces. |
| Angle brackets     | `@include <shared.mlc>`         | C-style include form.                                    |

Example:

```mlc
# app.mlc
app_name = "My Application"

@include database.mlc

network {
    @include "network_defaults.mlc"
    port = 8080
}
```
