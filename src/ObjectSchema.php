<?php

namespace Laravel\Ai;

use Illuminate\JsonSchema\Types\ObjectType;

class ObjectSchema extends Schema
{
    /**
     * Create a new output schema.
     */
    public function __construct(
        array $schema,
        string $name = 'schema_definition',
        bool $strict = false
    ) {
        parent::__construct(
            schema: (new ObjectType($schema))->withoutAdditionalProperties(),
            name: $name,
            strict: $strict
        );
    }

    /**
     * Get the array representation of the schema with additional properties disabled on all nested objects.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return static::normalize(parent::toSchema());
    }

    /**
     * Recursively normalize the schema for strict grammar compilers
     * (set additionalProperties:false on objects, and add null to enum values on nullable union types).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected static function normalize(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_array($type) && in_array('null', $type, true)
            && isset($schema['enum']) && is_array($schema['enum'])
            && ! in_array(null, $schema['enum'], true)) {
            $schema['enum'][] = null;
        }

        if ($type === 'object' || (is_array($type) && in_array('object', $type))) {
            $schema['additionalProperties'] = false;

            foreach ($schema['properties'] ?? [] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = static::normalize($property);
                }
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = static::normalize($schema['items']);
        }

        return $schema;
    }

    /**
     * Get the schema type.
     */
    public function schemaType(): string
    {
        return 'object';
    }
}
