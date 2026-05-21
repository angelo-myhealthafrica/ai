<?php

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\ObjectSchema;

test('nested objects include additional properties false', function () {
    $schema = new JsonSchemaTypeFactory;

    $objectSchema = new ObjectSchema([
        'name' => $schema->string()->required(),
        'address' => $schema->object([
            'street' => $schema->string()->required(),
            'city' => $schema->string()->required(),
        ])->required(),
    ]);

    $result = $objectSchema->toSchema();

    expect($result['additionalProperties'])->toBeFalse()
        ->and($result['properties']['address']['additionalProperties'])->toBeFalse();
});

test('objects nested in arrays include additional properties false', function () {
    $schema = new JsonSchemaTypeFactory;

    $objectSchema = new ObjectSchema([
        'items' => $schema->array()->items(
            $schema->object([
                'action' => $schema->string()->required(),
                'amount' => $schema->integer()->required(),
            ])
        )->required(),
    ]);

    $result = $objectSchema->toSchema();

    expect($result['additionalProperties'])->toBeFalse()
        ->and($result['properties']['items']['items']['additionalProperties'])->toBeFalse();
});

test('deeply nested objects include additional properties false', function () {
    $schema = new JsonSchemaTypeFactory;

    $objectSchema = new ObjectSchema([
        'user' => $schema->object([
            'name' => $schema->string()->required(),
            'contact' => $schema->object([
                'email' => $schema->string()->required(),
                'phone' => $schema->string()->required(),
            ])->required(),
        ])->required(),
    ]);

    $result = $objectSchema->toSchema();

    expect($result['additionalProperties'])->toBeFalse()
        ->and($result['properties']['user']['additionalProperties'])->toBeFalse()
        ->and($result['properties']['user']['properties']['contact']['additionalProperties'])->toBeFalse();
});

test('objects in nested arrays include additional properties false', function () {
    $schema = new JsonSchemaTypeFactory;

    $objectSchema = new ObjectSchema([
        'matrix' => $schema->array()->items(
            $schema->array()->items(
                $schema->object([
                    'value' => $schema->integer()->required(),
                ])
            )
        )->required(),
    ]);

    $result = $objectSchema->toSchema();

    expect($result['properties']['matrix']['items']['items']['additionalProperties'])->toBeFalse();
});

test('nullable nested objects include additional properties false', function () {
    $schema = new JsonSchemaTypeFactory;

    $objectSchema = new ObjectSchema([
        'name' => $schema->string()->required(),
        'address' => $schema->object([
            'street' => $schema->string()->required(),
            'city' => $schema->string()->required(),
        ])->nullable(),
    ]);

    $result = $objectSchema->toSchema();

    expect($result['additionalProperties'])->toBeFalse()
        ->and($result['properties']['address']['additionalProperties'])->toBeFalse();
});

test('nullable enum appends null to enum values', function () {
    $schema = new JsonSchemaTypeFactory;

    $objectSchema = new ObjectSchema([
        'country' => $schema->string()->enum(['Andorra', 'France'])->nullable()->required(),
    ]);

    $result = $objectSchema->toSchema();

    expect($result['properties']['country']['type'])->toBe(['string', 'null'])
        ->and($result['properties']['country']['enum'])->toBe(['Andorra', 'France', null]);
});

test('non-nullable enum is left untouched', function () {
    $schema = new JsonSchemaTypeFactory;

    $objectSchema = new ObjectSchema([
        'country' => $schema->string()->enum(['Andorra', 'France'])->required(),
    ]);

    $result = $objectSchema->toSchema();

    expect($result['properties']['country']['type'])->toBe('string')
        ->and($result['properties']['country']['enum'])->toBe(['Andorra', 'France']);
});

test('nullable enum already containing null is left untouched', function () {
    $schema = new JsonSchemaTypeFactory;

    $objectSchema = new ObjectSchema([
        'country' => $schema->string()->enum(['Andorra', null])->nullable()->required(),
    ]);

    $result = $objectSchema->toSchema();

    expect($result['properties']['country']['enum'])->toBe(['Andorra', null]);
});

test('nullable enum inside nested object and array items is normalized', function () {
    $schema = new JsonSchemaTypeFactory;

    $objectSchema = new ObjectSchema([
        'profile' => $schema->object([
            'country' => $schema->string()->enum(['Andorra'])->nullable()->required(),
        ])->required(),
        'history' => $schema->array()->items(
            $schema->string()->enum(['hit', 'miss'])->nullable()
        )->required(),
    ]);

    $result = $objectSchema->toSchema();

    expect($result['properties']['profile']['properties']['country']['enum'])->toBe(['Andorra', null])
        ->and($result['properties']['history']['items']['enum'])->toBe(['hit', 'miss', null]);
});
