<?php

use Laravel\Ai\Gateway\Anthropic\Concerns\NormalizesSchemas;

class AnthropicSchemaNormalizer
{
    use NormalizesSchemas {
        normalizeAnthropicSchema as public;
    }
}

test('nullable enum at top level is rewritten to anyOf', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => ['string', 'null'],
        'enum' => ['Andorra', 'France'],
    ]);

    expect($normalized)->toBe([
        'anyOf' => [
            ['type' => 'string', 'enum' => ['Andorra', 'France']],
            ['type' => 'null'],
        ],
    ]);
});

test('nullable enum preserves description outside the anyOf branches', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => ['string', 'null'],
        'enum' => ['Andorra'],
        'description' => 'Country code',
    ]);

    expect($normalized['description'])->toBe('Country code')
        ->and($normalized['anyOf'][0])->toBe(['type' => 'string', 'enum' => ['Andorra']]);
});

test('non-nullable enum is left untouched', function () {
    $schema = [
        'type' => 'string',
        'enum' => ['Andorra'],
    ];

    expect(AnthropicSchemaNormalizer::normalizeAnthropicSchema($schema))->toBe($schema);
});

test('nullable string without enum is left untouched', function () {
    $schema = [
        'type' => ['string', 'null'],
    ];

    expect(AnthropicSchemaNormalizer::normalizeAnthropicSchema($schema))->toBe($schema);
});

test('null is stripped from enum values when rewriting to anyOf', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => ['string', 'null'],
        'enum' => ['Andorra', null],
    ]);

    expect($normalized['anyOf'][0]['enum'])->toBe(['Andorra']);
});

test('nullable enum nested inside object is rewritten', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'object',
        'properties' => [
            'country' => [
                'type' => ['string', 'null'],
                'enum' => ['Andorra', 'France'],
            ],
        ],
        'additionalProperties' => false,
    ]);

    expect($normalized['properties']['country'])->toBe([
        'anyOf' => [
            ['type' => 'string', 'enum' => ['Andorra', 'France']],
            ['type' => 'null'],
        ],
    ]);
});

test('nullable enum inside array items is rewritten', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'array',
        'items' => [
            'type' => ['string', 'null'],
            'enum' => ['a', 'b'],
        ],
    ]);

    expect($normalized['items'])->toBe([
        'anyOf' => [
            ['type' => 'string', 'enum' => ['a', 'b']],
            ['type' => 'null'],
        ],
    ]);
});

test('nullable enum inside anyOf branches is rewritten recursively', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'anyOf' => [
            ['type' => ['string', 'null'], 'enum' => ['x']],
            ['type' => 'integer'],
        ],
    ]);

    expect($normalized['anyOf'][0])->toBe([
        'anyOf' => [
            ['type' => 'string', 'enum' => ['x']],
            ['type' => 'null'],
        ],
    ])->and($normalized['anyOf'][1])->toBe(['type' => 'integer']);
});

test('unsupported numerical constraints are stripped and noted in the description', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'integer',
        'minimum' => 1,
        'maximum' => 100,
        'multipleOf' => 5,
    ]);

    expect($normalized)->toHaveKey('type')
        ->and($normalized)->not->toHaveKey('minimum')
        ->and($normalized)->not->toHaveKey('maximum')
        ->and($normalized)->not->toHaveKey('multipleOf')
        ->and($normalized['description'])->toContain('Must be at least 1')
        ->and($normalized['description'])->toContain('Must be at most 100')
        ->and($normalized['description'])->toContain('Must be a multiple of 5');
});

test('unsupported string length constraints are stripped', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'string',
        'minLength' => 2,
        'maxLength' => 64,
    ]);

    expect($normalized)->not->toHaveKey('minLength')
        ->and($normalized)->not->toHaveKey('maxLength')
        ->and($normalized['description'])->toContain('Must be at least 2 characters')
        ->and($normalized['description'])->toContain('Must be at most 64 characters');
});

test('maxItems is stripped and minItems is capped at 1', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'array',
        'minItems' => 3,
        'maxItems' => 10,
        'items' => ['type' => 'string'],
    ]);

    expect($normalized)->not->toHaveKey('maxItems')
        ->and($normalized['minItems'])->toBe(1)
        ->and($normalized['description'])->toContain('Must have at most 10 items')
        ->and($normalized['description'])->toContain('Must have at least 3 items');
});

test('minItems of 0 or 1 is preserved as-is', function () {
    $zero = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'array',
        'minItems' => 0,
        'items' => ['type' => 'string'],
    ]);

    $one = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'array',
        'minItems' => 1,
        'items' => ['type' => 'string'],
    ]);

    expect($zero['minItems'])->toBe(0)
        ->and($zero)->not->toHaveKey('description')
        ->and($one['minItems'])->toBe(1)
        ->and($one)->not->toHaveKey('description');
});

test('unsupported format is stripped and supported formats are preserved', function () {
    $unsupported = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'string',
        'format' => 'phone',
    ]);

    $supported = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'string',
        'format' => 'email',
    ]);

    expect($unsupported)->not->toHaveKey('format')
        ->and($unsupported['description'])->toContain('Format: phone')
        ->and($supported['format'])->toBe('email')
        ->and($supported)->not->toHaveKey('description');
});

test('existing description is preserved when constraints are stripped', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'integer',
        'description' => 'The user age.',
        'minimum' => 0,
    ]);

    expect($normalized['description'])->toBe('The user age. (Must be at least 0)');
});

test('uniqueItems is stripped from arrays', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'array',
        'items' => ['type' => 'string'],
        'uniqueItems' => true,
    ]);

    expect($normalized)->not->toHaveKey('uniqueItems')
        ->and($normalized['description'])->toContain('Items must be unique');
});

test('constraints are stripped recursively from nested properties and items', function () {
    $normalized = AnthropicSchemaNormalizer::normalizeAnthropicSchema([
        'type' => 'object',
        'properties' => [
            'age' => ['type' => 'integer', 'minimum' => 0],
            'tags' => [
                'type' => 'array',
                'maxItems' => 5,
                'items' => ['type' => 'string', 'maxLength' => 20],
            ],
        ],
    ]);

    expect($normalized['properties']['age'])->not->toHaveKey('minimum')
        ->and($normalized['properties']['tags'])->not->toHaveKey('maxItems')
        ->and($normalized['properties']['tags']['items'])->not->toHaveKey('maxLength');
});
