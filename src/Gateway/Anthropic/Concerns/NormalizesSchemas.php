<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

trait NormalizesSchemas
{
    /**
     * JSON Schema keywords Anthropic's strict grammar compiler accepts.
     * Anything else is stripped.
     *
     * @var array<int, string>
     */
    private static array $allowedKeywords = [
        'type', 'properties', 'required', 'additionalProperties',
        'enum', 'const', 'default', 'description', 'title',
        'items', 'minItems',
        'format', 'pattern',
        'anyOf', 'allOf',
        '$ref', '$defs', 'definitions',
    ];

    /**
     * String formats Anthropic's strict compiler accepts.
     *
     * @var array<int, string>
     */
    private static array $supportedFormats = [
        'date-time', 'time', 'date', 'duration',
        'email', 'hostname', 'uri', 'ipv4', 'ipv6', 'uuid',
    ];

    /**
     * Templates used to surface stripped constraints in the description so
     * the model still sees the intent. Keyed by JSON Schema keyword.
     *
     * @var array<string, string>
     */
    private static array $constraintMessages = [
        'minimum' => 'Must be at least %s',
        'maximum' => 'Must be at most %s',
        'exclusiveMinimum' => 'Must be greater than %s',
        'exclusiveMaximum' => 'Must be less than %s',
        'multipleOf' => 'Must be a multiple of %s',
        'minLength' => 'Must be at least %s characters',
        'maxLength' => 'Must be at most %s characters',
        'maxItems' => 'Must have at most %s items',
        'uniqueItems' => 'Items must be unique',
        'not' => 'Must not match a forbidden schema',
        'oneOf' => 'Must match exactly one of the listed schemas',
    ];

    /**
     * Recursively normalize a schema for Anthropic's strict grammar compiler.
     *
     *  - Allow-lists JSON Schema keywords; strips everything else.
     *  - Rewrites nullable-enum union types into anyOf branches.
     *  - Filters `format` to the supported list.
     *  - Caps `minItems` at 1 (only 0 and 1 are supported).
     *  - Forces `additionalProperties: false` on objects.
     *  - Surfaces stripped constraints in the node's description so the model
     *    still sees the intent (mirrors Anthropic SDKs' schema transform).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected static function normalizeAnthropicSchema(array $schema): array
    {
        [$schema, $notes] = static::pruneToAllowedKeywords($schema);

        if (isset($schema['format']) && ! in_array($schema['format'], static::$supportedFormats, true)) {
            $notes[] = sprintf('Format: %s', $schema['format']);
            unset($schema['format']);
        }

        if (isset($schema['minItems']) && is_int($schema['minItems']) && $schema['minItems'] > 1) {
            $notes[] = sprintf('Must have at least %d items', $schema['minItems']);
            $schema['minItems'] = 1;
        }

        if (array_key_exists('additionalProperties', $schema) && $schema['additionalProperties'] !== false) {
            $schema['additionalProperties'] = false;
        }

        $schema = static::appendNotesToDescription($schema, $notes);

        $type = $schema['type'] ?? null;

        if (is_array($type) && in_array('null', $type, true)
            && isset($schema['enum']) && is_array($schema['enum'])) {
            return static::rewriteNullableEnum($schema, $type);
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

        foreach (['anyOf', 'allOf'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key])) {
                $schema[$key] = array_map(
                    static fn ($branch) => is_array($branch) ? static::normalizeAnthropicSchema($branch) : $branch,
                    $schema[$key],
                );
            }
        }

        return $schema;
    }

    /**
     * Drop any keyword not in the Anthropic-supported set, returning the
     * pruned schema alongside the human-readable notes for each stripped key.
     *
     * @param  array<string, mixed>  $schema
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private static function pruneToAllowedKeywords(array $schema): array
    {
        $kept = [];
        $notes = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, static::$allowedKeywords, true)) {
                $kept[$key] = $value;

                continue;
            }

            if (isset(static::$constraintMessages[$key])) {
                $template = static::$constraintMessages[$key];
                $notes[] = str_contains($template, '%s')
                    ? sprintf($template, static::formatScalar($value))
                    : $template;
            }
        }

        return [$kept, $notes];
    }

    /**
     * Format a value for inclusion in a description note.
     */
    private static function formatScalar(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value) ?: '';
    }

    /**
     * Append notes about stripped constraints to the description.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $notes
     * @return array<string, mixed>
     */
    private static function appendNotesToDescription(array $schema, array $notes): array
    {
        if ($notes === []) {
            return $schema;
        }

        $existing = isset($schema['description']) ? rtrim((string) $schema['description']) : '';
        $appendix = '('.implode('; ', $notes).')';
        $schema['description'] = $existing === '' ? $appendix : $existing.' '.$appendix;

        return $schema;
    }

    /**
     * Rewrite a `{type: [..., 'null'], enum: [...]}` node into anyOf branches.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<int, string>  $type
     * @return array<string, mixed>
     */
    private static function rewriteNullableEnum(array $schema, array $type): array
    {
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
}
