# MonkeysLegion MLC - Development Roadmap (v3.0)

This roadmap outlines the evolution of the MonkeysLegion MLC configuration library, transitioning from a stable PSR-16 cached loader to an "Extreme Performance" and "Security-First" configuration engine.

---

## 🎯 Primary Goals for v3.0

- **Zero-Overhead Production Mode**: Compile MLC to static PHP arrays for OPcache optimization.
- **Universal Variable Support**: Native environment variable expansion with default values.
- **Architectural Flexibility**: Decoupled components via interfaces.
- **Enhanced Security**: Hardened file access and circular reference detection.

---

## 🛠️ Phase 1: Security & Core Hardening ✅

*Focus: Addressing critical gaps identified in the v2.x security audit.*

### 1.1 Robust Environment Variable Expansion

- [x] **Task**: Implement `${VAR}` and `${VAR:-default}` syntax parsing in `Parser.php`.
- **Technical Detail**:
  - Update `parseValue()` to detect `${}` patterns.
  - Use `env()` helper function for lookup.
  - Support fallback values using the `:-` separator.
- **Priority**: High (Critical)

### 1.2 "True" Circular Reference Detection

- [x] **Task**: Replace depth-limiting with a reference-tracking algorithm.
- **Technical Detail**:
  - Maintain a stack of "currently resolving keys" during parsing.
  - If a key references another key already in the stack, throw a `CircularDependencyException`.
- **Priority**: Medium

### 1.3 Strict Permission Auditing

- [x] **Task**: Upgrade `validateFileAccess` warnings to exceptions for production.
- **Technical Detail**:
  - Add a `strict_security` flag to `Parser` and `Loader`.
  - Throw `SecurityException` if a file is world-writable and `strict_security` is true.
- **Priority**: Medium

---

## 🚀 Phase 2: Extreme Performance (v3 Core) ✅

*Focus: Moving beyond serialized caching to bytecode-optimized execution.*

### 2.1 Configuration Pre-compilation

- [x] **Task**: Create a "Compiler" that exports MLC structure to a static PHP file.
- **Technical Detail**:
  - `Mlc\Cache\CompiledPhpCache` implements PSR-16 with OPcache as the backend.
  - Exports parsed arrays using `var_export($data, true)`.
  - Generated file format: `<?php return [...];` with a debug timestamp header.
  - `Loader` detects `CompiledPhpCache` and uses a fast raw-array path (no metadata envelope).
  - `Loader::compile(array $names)` triggers a fresh parse + write to compiled cache.
  - OPcache invalidation is called before each write to prevent stale bytecode reads.
  - TTL is deliberately ignored — compiled cache is immutable until explicitly evicted.
- **Priority**: High

### 2.2 Layered Runtime Mutability (Dual-Layer Engine)

*Absorbed from original Phase 6 — an integral part of the OPcache strategy.*

#### 2.2.1 Dual-Layer Data Engine

- [x] **Task**: Implement a non-destructive runtime-override layer on top of the compiled base.
- **Technical Detail**:
  - `Config` holds a separate `$runtimeOverrides` array (dot-notation key → value).
  - `get()` checks the override layer before the primary compiled data layer.
  - `override(string $path, mixed $value)` writes to the override layer without touching the compiled array.
  - The OPcache-backed base is never mutated — zero impact on shared memory.
- **Priority**: High

#### 2.2.2 Atomic Snapshotting

- [x] **Task**: Provide a way to "flatten" runtime changes into a new isolated `Config` instance.
- **Technical Detail**:
  - `Config->snapshot()` merges the current compiled data with runtime overrides into a fresh instance.
  - Essential for long-running processes (RoadRunner, Swoole) requiring per-request state isolation.
  - The original `Config` instance (and its OPcache base) is unaffected.
- **Priority**: Medium

#### 2.2.3 Mutability Locks

- [x] **Task**: Fine-grained control over what layers can be mutated.
- **Technical Detail**:
  - `Config->lockBase()`: Seals the compiled base layer; runtime `override()` calls are still allowed.
  - `Config->lockAll()`: Fully immutable — throws `FrozenConfigException` on any further mutation.
  - `freeze()` (existing) seals `set()` access to the base but leaves the override layer open.
- **Priority**: Medium

### 2.3 Recursive Includes

- [x] **Task**: Support `include "other.mlc"` syntax.
- **Technical Detail**:
  - Update the parser regex to detect `include` statements.
  - Recursively call `parseFile()` and merge results.
  - Must include logic to prevent inclusion loops.
- **Priority**: Medium

---

## 🏗️ Phase 3: Architectural Refactoring

*Focus: Decoupling and extensibility.*

### 3.1 Component Interfacing

- [x] **Task**: Define `ParserInterface`, `LoaderInterface`, and `CacheInterface`.
- **Technical Detail**:
  - Extract methods from current concrete classes.
  - Allow dependency injection of custom parsers (e.g., for JSON/YAML).
- **Priority**: Medium

### 3.2 Decouple `phpdotenv`

- [x] **Task**: Abstract environment loading.
- **Technical Detail**:
  - Move `Dotenv` interaction to a separate `EnvBridge`.
  - Allow MLC to function without `vlucas/phpdotenv` if the environment is already populated.
- **Priority**: Medium

---

## 🔧 Phase 4: Developer Tooling & DX

*Focus: Making MLC a joy to work with.*

### 4.1 Native CLI Tool (`mlc-check`)

- [x] **Task**: Build a standalone binary for config validation.
- **Technical Detail**:
  - Build using native PHP `$argv`.
  - Provide clear exit codes (0 for success, 1 for errors).
  - Support recursive directory check.
- **Priority**: Medium

### 4.2 Multi-Format Bridge (JSON, PHP, YAML)

- [x] **Task**: Support loading non-MLC formats.
- **Technical Detail**:
  - `Loader` automatically detects format from extension.
  - JSON: Using `json_decode`.
  - PHP: Using `include` (returning arrays).
  - YAML: Native lightweight parser (no Symfony dependency).
  - Standardize all into the dot-notation Config engine.
- **Priority**: Medium

---

## 🌐 Phase 5: Deep Integrations

*Focus: Enterprise-grade connectivity.*

### 5.1 Secrets Management Drivers

- [ ] **Task**: Drivers for HashiCorp Vault, AWS, and GCP.
- **Technical Detail**:
  - Implement lazy-loading for secrets.
  - Syntax: `db_pass = vault("kv/db_password")`.
- **Note**: Skipped per current development focus.
- **Priority**: Low

### 5.2 Event / Middleware Hook System ✅

- [x] **Task**: Emitter implementation in `Loader`.
- **Technical Detail**:
  - Hooks: `onLoading`, `onLoaded`, `onValidationError`.
  - Type-safe enum registration support.
  - Proxy methods for improved DX.
- **Priority**: Medium
