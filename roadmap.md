# MonkeysLegion MLC - Development Roadmap (v3.0)

This roadmap outlines the evolution of the MonkeysLegion MLC configuration library, transitioning from a stable PSR-16 cached loader to an "Extreme Performance" and "Security-First" configuration engine.

---

## 🎯 Primary Goals for v3.0
- **Zero-Overhead Production Mode**: Compile MLC to static PHP arrays for OPcache optimization.
- **Universal Variable Support**: Native environment variable expansion with default values.
- **Architectural Flexibility**: Decoupled components via interfaces.
- **Enhanced Security**: Hardened file access and circular reference detection.

---

## 🛠️ Phase 1: Security & Core Hardening
*Focus: Addressing critical gaps identified in the v2.x security audit.*

### 1.1 Robust Environment Variable expansion
- **Task**: Implement `${VAR}` and `${VAR:-default}` syntax parsing in `Parser.php`.
- **Technical Detail**: 
    - Update `parseValue()` to detect `${}` patterns.
    - Use `getenv()` or standard `$_ENV` lookup.
    - Support fallback values using the `:-` separator.
- **Priority**: High (Critical)

### 1.2 "True" Circular Reference Detection
- **Task**: Replace depth-limiting with a reference-tracking algorithm.
- **Technical Detail**: 
    - Maintain a stack of "currently resolving keys" during parsing.
    - If a key references another key (planned feature 3.1) that is already in the stack, throw a `CircularReferenceException`.
- **Priority**: Medium

### 1.3 Strict Permission Auditing
- **Task**: Upgrade `validateFileAccess` warnings to exceptions for production.
- **Technical Detail**: 
    - Add a `strict_security` flag to `Parser` and `Loader`.
    - Throw `SecurityException` if a file is world-writable and `strict_security` is true.
- **Priority**: Medium

---

## 🚀 Phase 2: Extreme Performance (v3 Core)
*Focus: Moving beyond serialized caching to bytecode-optimized execution.*

### 2.1 Configuration Pre-compilation
- **Task**: Create a "Compiler" that exports MLC structure to a static PHP file.
- **Technical Detail**: 
    - Implement `Mlc\Compiler\PhpCompiler`.
    - Export parsed arrays using `var_export($data, true)`.
    - Generate a file: `<?php return [...];`.
    - Loader should prioritize loading `.php` compiled files if present.
- **Priority**: High

### 2.2 Recursive Includes
- **Task**: Support `include "other.mlc"` syntax.
- **Technical Detail**: 
    - Update the parser regex to detect `include` statements.
    - Recursively call `parseFile()` and merge results.
    - Must include logic to prevent inclusion loops.
- **Priority**: Medium

---

## 🏗️ Phase 3: Architectural Refactoring
*Focus: Decoupling and extensibility.*

### 3.1 Component Interfacing
- **Task**: Define `ParserInterface`, `LoaderInterface`, and `CacheInterface`.
- **Technical Detail**: 
    - Extract methods from current concrete classes.
    - Allow dependency injection of custom parsers (e.g., for JSON/YAML).
- **Priority**: Medium

### 3.2 Decouple `phpdotenv`
- **Task**: Abstract environment loading.
- **Technical Detail**: 
    - Move `Dotenv` interaction to a separate `EnvBridge`.
    - Allow MLC to function without `vlucas/phpdotenv` if the environment is already populated.
- **Priority**: Medium

---

## 🔧 Phase 4: Developer Tooling & DX
*Focus: Making MLC a joy to work with.*

### 4.1 CLI Validation Tool (`mlc-check`)
- **Task**: Build a standalone binary for CI/CD pipelines.
- **Technical Detail**: 
    - Use `symfony/console` or a lightweight alternative.
    - Support `--schema` validation using `MonkeysLegion\Validator`.
- **Priority**: Low

### 4.2 Multi-Format Support
- **Task**: Build bridges for JSON, YAML, and PHP arrays.
- **Technical Detail**: 
    - Enable `Loader->load(['config.mlc', 'legacy.json'])`.
    - Standardize all formats into the same dot-notation access.
- **Priority**: Low

---

## 🌐 Phase 5: Deep Integrations
*Focus: Enterprise-grade connectivity.*

### 5.1 Secrets Management Drivers
- **Task**: Drivers for HashiCorp Vault, AWS, and GCP.
- **Technical Detail**: 
    - Implement lazy-loading for secrets.
    - Syntax: `db_pass = vault("kv/db_password")`.
- **Priority**: Low

### 5.2 Event / Middleware Hook System
- **Task**: Emitter implementation in `Loader`.
- **Technical Detail**: 
    - Hooks: `onLoading`, `onLoaded`, `onValidationError`.
- **Priority**: Medium

---

## ⚡ Phase 6: Layered Runtime Mutability (Hybrid Engine)
*Focus: Allowing flexibility without compromising OPcache performance.*

### 6.1 "Dual-Layer" Data Engine
- **Task**: Implement a non-destructive runtime-override layer.
- **Technical Detail**: 
    - Maintain a separate `$runtimeOverrides` array inside `Config`.
    - Update `get()` to check the override layer before the primary data layer.
    - This allows modifying values in production without duplicating the large static compiled array.
- **Priority**: High

### 6.2 Atomic Snapshotting
- **Task**: Provide a way to "flatten" runtime changes into a new Config instance.
- **Technical Detail**: 
    - Method `Config->snapshot()`: Merges the current static data with runtime overrides into a fresh instance.
    - Useful for long-running processes (e.g. RoadRunner, Swoole) where state isolation is required.
- **Priority**: Medium

### 6.3 Mutability Toggles
- **Task**: Fine-grained control over "Switchable" states.
- **Technical Detail**: 
    - `Config->lockBase()`: Permanently prevent changes to the pre-compiled layer while allowing runtime overrides.
    - `Config->lockAll()`: Prevent any further overrides.
- **Priority**: Medium
