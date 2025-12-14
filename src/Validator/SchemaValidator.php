<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Validator;

/**
 * Schema-based configuration validator.
 *
 * Validates configuration against a defined schema.
 *
 * Example schema:
 * [
 *     'database' => [
 *         'type' => 'array',
 *         'required' => true,
 *         'children' => [
 *             'host' => ['type' => 'string', 'required' => true],
 *             'port' => ['type' => 'int', 'required' => false],
 *         ],
 *     ],
 *     'app' => [
 *         'type' => 'array',
 *         'children' => [
 *             'debug' => ['type' => 'bool', 'required' => true],
 *         ],
 *     ],
 * ]
 */
final class SchemaValidator implements ConfigValidator
{
    /** @var array<int, string> */
    private array $errors = [];

    /** @param array<string, mixed> $schema */
    public function __construct(private array $schema)
    {
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    public function validate(array $config): array
    {
        $this->errors = [];
        $this->validateLevel($config, $this->schema);
        return $this->errors;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $schema
     */
    private function validateLevel(array $data, array $schema, string $path = ''): void
    {
        // Check required fields
        foreach ($schema as $key => $rules) {
            $currentPath = $path !== '' ? "{$path}.{$key}" : $key;

            if (!is_array($rules)) {
                continue;
            }

            $required = $rules['required'] ?? false;
            $exists = array_key_exists($key, $data);

            if ($required && !$exists) {
                $this->errors[] = "Required field '{$currentPath}' is missing";
                continue;
            }

            if (!$exists) {
                continue;
            }

            // Type validation
            if (isset($rules['type'])) {
                if (!$this->validateType($data[$key], $rules['type'], $currentPath)) {
                    continue;
                }
            }

            // Nested validation
            if (isset($rules['children']) && is_array($data[$key])) {
                $this->validateLevel($data[$key], $rules['children'], $currentPath);
            }

            // Custom validator
            if (isset($rules['validator']) && is_callable($rules['validator'])) {
                $result = $rules['validator']($data[$key], $currentPath);
                if ($result !== true && is_string($result)) {
                    $this->errors[] = $result;
                }
            }

            // Enum validation
            if (isset($rules['enum']) && is_array($rules['enum'])) {
                if (!in_array($data[$key], $rules['enum'], true)) {
                    $allowed = implode(', ', $rules['enum']);
                    $this->errors[] = "Field '{$currentPath}' must be one of: {$allowed}";
                }
            }

            // Min/Max for numbers
            if (is_numeric($data[$key])) {
                if (isset($rules['min']) && $data[$key] < $rules['min']) {
                    $this->errors[] = "Field '{$currentPath}' must be >= {$rules['min']}";
                }
                if (isset($rules['max']) && $data[$key] > $rules['max']) {
                    $this->errors[] = "Field '{$currentPath}' must be <= {$rules['max']}";
                }
            }

            // Pattern for strings
            if (isset($rules['pattern']) && is_string($data[$key])) {
                if (!preg_match($rules['pattern'], $data[$key])) {
                    $this->errors[] = "Field '{$currentPath}' does not match required pattern";
                }
            }
        }

        // Check for unexpected fields if strict mode
        if (isset($schema['_strict']) && $schema['_strict'] === true) {
            foreach (array_keys($data) as $key) {
                if (!isset($schema[$key])) {
                    $currentPath = $path !== '' ? "{$path}.{$key}" : $key;
                    $this->errors[] = "Unexpected field '{$currentPath}'";
                }
            }
        }
    }

    private function validateType(mixed $value, string $type, string $path): bool
    {
        $valid = match ($type) {
            'string' => is_string($value),
            'int', 'integer' => is_int($value),
            'float', 'double' => is_float($value),
            'bool', 'boolean' => is_bool($value),
            'array' => is_array($value),
            'numeric' => is_numeric($value),
            'null' => $value === null,
            default => true,
        };

        if (!$valid) {
            $actualType = gettype($value);
            $this->errors[] = "Field '{$path}' must be of type '{$type}', got '{$actualType}'";
        }

        return $valid;
    }
}
