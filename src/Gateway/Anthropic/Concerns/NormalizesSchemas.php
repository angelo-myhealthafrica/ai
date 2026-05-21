<?php

namespace Laravel\Ai\Gateway\Anthropic\Concerns;

trait NormalizesSchemas
{
    /**
     * JSON Schema constraint keywords Anthropic's strict compiler does not support.
     * Stripped from the schema; the constraint is preserved in the description so
     * the model still sees it.
     *
     * @var array<string, string>
     */
    private static array $unsupportedConstraints = [
        'minimum' => 'Must be at least %s',
        'maximum' => 'Must be at most %s',
        'exclusiveMinimum' => 'Must be greater than %s',
        'exclusiveMaximum' => 'Must be less than %s',
        'multipleOf' => 'Must be a multiple of %s',
        'minLength' => 'Must be at least %s characters',
        'maxLength' => 'Must be at most %s characters',
        'maxItems' => 'Must have at most %s items',
        'uniqueItems' => 'Items must be unique',
    ];

    /**
     * String formats supported by Anthropic's strict compiler.
     * Other formats are stripped (kept in description).
     *
     * @var array<int, string>
     */
    private static array $supportedFormats = [
        'date-time', 'time', 'date', 'duration',
        'email', 'hostname', 'uri', 'ipv4', 'ipv6', 'uuid',
    ];

    /**
     * Recursively normalize a schema for Anthropic's strict grammar compiler:
     * - Rewrite nullable-enum union types into anyOf branches.
     * - Strip unsupported constraints (minimum, maxLength, maxItems, etc.),
     *   surfacing the constraint in the description so the model still sees it.
     * - Filter `format` to the supported list.
     * - Cap minItems at 1 (only 0 and 1 are supported).
     *
     * Mirrors the schema transformation Anthropic's official SDKs perform.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected static function normalizeAnthropicSchema(array $schema): array
    {
        $schema = static::stripUnsupportedConstraints($schema);

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

    /**
     * Strip unsupported constraints from a single schema node, preserving the
     * constraint in the description so the model still sees it.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private static function stripUnsupportedConstraints(array $schema): array
    {
        $notes = [];

        foreach (static::$unsupportedConstraints as $key => $template) {
            if (! array_key_exists($key, $schema)) {
                continue;
            }

            $value = $schema[$key];
            $formatted = is_scalar($value) ? (string) $value : json_encode($value);
            $notes[] = str_contains($template, '%s') ? sprintf($template, $formatted) : $template;
            unset($schema[$key]);
        }

        if (isset($schema['minItems']) && is_int($schema['minItems']) && $schema['minItems'] > 1) {
            $notes[] = sprintf('Must have at least %d items', $schema['minItems']);
            $schema['minItems'] = 1;
        }

        if (isset($schema['format']) && is_string($schema['format'])
            && ! in_array($schema['format'], static::$supportedFormats, true)) {
            $notes[] = sprintf('Format: %s', $schema['format']);
            unset($schema['format']);
        }

        if ($notes !== []) {
            $existing = isset($schema['description']) ? rtrim((string) $schema['description']) : '';
            $appendix = '('.implode('; ', $notes).')';
            $schema['description'] = $existing === '' ? $appendix : $existing.' '.$appendix;
        }

        return $schema;
    }
}
