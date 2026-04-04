<?php

declare(strict_types=1);

namespace MonkeysLegion\Mlc\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use MonkeysLegion\Mlc\Validator\SchemaValidator;

class SchemaValidatorTest extends TestCase
{
    #[Test]
    public function test_valid_configuration_should_return_no_errors(): void
    {
        $schema = [
            'database' => [
                'type' => 'array',
                'required' => true,
                'children' => [
                    'host' => ['type' => 'string', 'required' => true],
                    'port' => ['type' => 'int', 'required' => false],
                ]
            ],
        ];

        $validator = new SchemaValidator($schema);
        $config = [
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
            ],
            'extra_field_allowed_by_default' => true
        ];

        $errors = $validator->validate($config);
        $this->assertEmpty($errors);
    }

    #[Test]
    public function test_missing_required_field_should_return_error(): void
    {
        $schema = [
            'host' => ['required' => true]
        ];

        $validator = new SchemaValidator($schema);
        $errors = $validator->validate([]);

        $this->assertCount(1, $errors);
        $this->assertEquals("Required field 'host' is missing", $errors[0]);
    }

    #[Test]
    public function test_type_validation_all_types(): void
    {
        $schema = [
            'str' => ['type' => 'string'],
            'int' => ['type' => 'integer'],
            'flt' => ['type' => 'float'],
            'bln' => ['type' => 'boolean'],
            'arr' => ['type' => 'array'],
            'num' => ['type' => 'numeric'],
            'nul' => ['type' => 'null'],
            'unk' => ['type' => 'unknown_type'], // fallback true
        ];

        $validator = new SchemaValidator($schema);
        
        // Test Valid
        $config = [
            'str' => "hello",
            'int' => 42,
            'flt' => 3.14,
            'bln' => true,
            'arr' => [1, 2],
            'num' => "123.45",
            'nul' => null,
            'unk' => new \stdClass()
        ];
        $this->assertEmpty($validator->validate($config));

        // Test Invalid
        $invalidConfig = [
            'str' => 123,
            'int' => "42",
            'flt' => 42, // should technically be float
            'bln' => 1,
            'arr' => "array",
            'num' => "not_numeric",
            'nul' => false
        ];
        
        $errors = $validator->validate($invalidConfig);
        $this->assertCount(7, $errors);
        $this->assertStringContainsString("type 'string', got 'integer'", $errors[0]);
        $this->assertStringContainsString("type 'integer', got 'string'", $errors[1]);
        $this->assertStringContainsString("type 'float', got 'integer'", $errors[2]);
        $this->assertStringContainsString("type 'boolean', got 'integer'", $errors[3]);
        $this->assertStringContainsString("type 'array', got 'string'", $errors[4]);
        $this->assertStringContainsString("type 'numeric', got 'string'", $errors[5]);
        $this->assertStringContainsString("type 'null', got 'boolean'", $errors[6]);
    }

    #[Test]
    public function test_enum_validation(): void
    {
        $schema = [
            'role' => ['enum' => ['admin', 'user', 'guest']]
        ];

        $validator = new SchemaValidator($schema);
        
        $this->assertEmpty($validator->validate(['role' => 'admin']));
        
        $errors = $validator->validate(['role' => 'superuser']);
        $this->assertCount(1, $errors);
        $this->assertEquals("Field 'role' must be one of: admin, user, guest", $errors[0]);
    }

    #[Test]
    public function test_min_max_validation(): void
    {
        $schema = [
            'port' => ['min' => 1024, 'max' => 65535]
        ];

        $validator = new SchemaValidator($schema);
        
        // Valid
        $this->assertEmpty($validator->validate(['port' => 8080]));
        
        // Min Invalid
        $errors = $validator->validate(['port' => 80]);
        $this->assertCount(1, $errors);
        $this->assertEquals("Field 'port' must be >= 1024", $errors[0]);
        
        // Max Invalid
        $errors = $validator->validate(['port' => 70000]);
        $this->assertCount(1, $errors);
        $this->assertEquals("Field 'port' must be <= 65535", $errors[0]);
    }

    #[Test]
    public function test_pattern_validation(): void
    {
        $schema = [
            'version' => ['pattern' => '/^v\d+\.\d+\.\d+$/']
        ];

        $validator = new SchemaValidator($schema);
        
        $this->assertEmpty($validator->validate(['version' => 'v1.2.3']));
        
        $errors = $validator->validate(['version' => '1.2.3']);
        $this->assertCount(1, $errors);
        $this->assertEquals("Field 'version' does not match required pattern", $errors[0]);
    }

    #[Test]
    public function test_custom_validator(): void
    {
        $schema = [
            'custom' => [
                'validator' => function (mixed $value, string $path) {
                    if ($value !== 'expected') {
                        return "Value in {$path} is not expected.";
                    }
                    return true;
                }
            ]
        ];

        $validator = new SchemaValidator($schema);
        
        $this->assertEmpty($validator->validate(['custom' => 'expected']));
        
        $errors = $validator->validate(['custom' => 'unexpected']);
        $this->assertCount(1, $errors);
        $this->assertEquals("Value in custom is not expected.", $errors[0]);
    }

    #[Test]
    public function test_strict_mode_rejects_unexpected_fields(): void
    {
        $schema = [
            '_strict' => true,
            'allowed' => ['type' => 'string']
        ];

        $validator = new SchemaValidator($schema);
        
        $errors = $validator->validate(['allowed' => 'yes', 'unexpected' => 'no']);
        $this->assertCount(1, $errors);
        $this->assertEquals("Unexpected field 'unexpected'", $errors[0]);
    }

    #[Test]
    public function test_nested_validation_error_paths(): void
    {
        $schema = [
            'db' => [
                'children' => [
                    'host' => ['required' => true]
                ]
            ]
        ];

        $validator = new SchemaValidator($schema);
        $errors = $validator->validate(['db' => []]);
        
        $this->assertCount(1, $errors);
        $this->assertEquals("Required field 'db.host' is missing", $errors[0]);
    }

    #[Test]
    public function test_non_array_rules_are_ignored(): void
    {
        $schema = [
            'some_field' => 'this is not an array of rules'
        ];

        $validator = new SchemaValidator($schema);
        $errors = $validator->validate(['some_field' => 'value']);
        $this->assertEmpty($errors);
    }
}
