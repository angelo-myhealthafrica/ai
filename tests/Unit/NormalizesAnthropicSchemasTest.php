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
