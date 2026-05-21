<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

trait NormalizesSchemas
{
    /**
     * Rewrite nullable-enum union types into anyOf branches.
     *
     * Anthropic's strict grammar compiler validates each enum value against the
     * declared type, and a value of type "string" does not match the literal
     * declaration ["string", "null"]. Splitting the union into anyOf branches
     * (one with the enum, one for null) bypasses the quirk.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected static function normalizeAnthropicSchema(array $schema): array
    {
        $type = $schema['type'] ?? null;

        if (is_array($type) && in_array('null', $type, true)
            && isset($schema['enum']) && is_array($schema['enum'])) {
            $nonNullTypes = array_values(array_filter($type, static fn ($t) => $t !== 'null'));
            $nonNullEnum = array_values(array_filter($schema['enum'], static fn ($v) => $v !== null));

            $branch = [
                'type' => count($nonNullTypes) === 1 ? $nonNullTypes[0] : $nonNullTypes,
                'enum' => $nonNullEnum,
            ];

            foreach ($schema as $key => $value) {
                if (! in_array($key, ['type', 'enum', 'description'], true)) {
                    $branch[$key] = $value;
                }
            }

            $rewritten = ['anyOf' => [$branch, ['type' => 'null']]];

            if (isset($schema['description'])) {
                $rewritten['description'] = $schema['description'];
            }

            return $rewritten;
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $key => $property) {
                if (is_array($property)) {
                    $schema['properties'][$key] = static::normalizeAnthropicSchema($property);
                }
            }
        }

        if (is_array($schema['items'] ?? null)) {
            $schema['items'] = static::normalizeAnthropicSchema($schema['items']);
        }

        foreach (['anyOf', 'allOf', 'oneOf'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                $schema[$key] = array_map(
                    static fn ($branch) => is_array($branch) ? static::normalizeAnthropicSchema($branch) : $branch,
                    $schema[$key],
                );
            }
        }

        return $schema;
    }
}
