<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

#[Strict]
class NullableEnumTool implements Tool
{
    public function description(): string
    {
        return 'A tool with a nullable enum parameter.';
    }

    public function handle(Request $request): string
    {
        return 'ok';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'country' => $schema->string()->enum(['Andorra', 'France'])->nullable()->required(),
        ];
    }
}
