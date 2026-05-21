<?php

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Tools\FileSearch;
use Tests\Fixtures\Agents\NamedToolAgent;
use Tests\Fixtures\Agents\ToolUsingAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\NonStrictTool;
use Tests\Fixtures\Tools\RandomNumberGenerator;

use function Laravel\Ai\agent;

test('tool parameters are not wrapped in schema definition', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $tools = $request->data()['tools'] ?? [];

        foreach ($tools as $tool) {
            if ($tool['name'] === 'FixedNumberGenerator') {
                $properties = (array) ($tool['input_schema']['properties'] ?? []);

                return $tool['input_schema']['type'] === 'object'
                    && ! isset($properties['schema_definition']);
            }
        }

        return false;
    });
});

test('unsupported provider tool throws logic exception', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse(),
    ]);

    agent(
        'Test unsupported tool',
        tools: [new FileSearch(['store_1'])],
    )->prompt(
        'Search for something',
        provider: 'anthropic',
    );
})->throws(LogicException::class, 'is not supported by Anthropic');

test('tool with a name() method emits the declared name', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    (new NamedToolAgent('aliased_tool'))->prompt('Search', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $names = collect($request->data()['tools'] ?? [])->pluck('name')->all();

        return in_array('aliased_tool', $names, true);
    });
});

test('empty schema still includes input schema with type object', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
    ]);

    (new ToolUsingAgent(fixed: true))->prompt(
        'Generate a number',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $tools = $request->data()['tools'] ?? [];

        foreach ($tools as $tool) {
            if ($tool['name'] === 'FixedNumberGenerator') {
                return isset($tool['input_schema'])
                    && $tool['input_schema']['type'] === 'object'
                    && isset($tool['input_schema']['properties']);
            }
        }

        return false;
    });
});

test('tool with Strict attribute sends strict true to anthropic', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('42'),
    ]);

    agent(tools: [new RandomNumberGenerator])->prompt(
        'Give me a random number',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $tool = collect($request->data()['tools'] ?? [])
            ->firstWhere('name', 'RandomNumberGenerator');

        return $tool['strict'] === true
            && $tool['input_schema']['type'] === 'object'
            && $tool['input_schema']['additionalProperties'] === false
            && array_key_exists('min', (array) $tool['input_schema']['properties'])
            && array_key_exists('max', (array) $tool['input_schema']['properties'])
            && in_array('min', $tool['input_schema']['required'], true)
            && in_array('max', $tool['input_schema']['required'], true);
    });
});

test('tool without Strict attribute sends strict false and honors developer-declared required fields', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
    ]);

    agent(tools: [new NonStrictTool])->prompt('Hi', provider: 'anthropic');

    Http::assertSent(function ($request) {
        $tool = collect($request->data()['tools'] ?? [])
            ->firstWhere('name', 'NonStrictTool');

        return $tool['strict'] === false
            && $tool['input_schema']['required'] === ['query']
            && array_key_exists('limit', (array) $tool['input_schema']['properties']);
    });
});

test('tool with empty schema and Strict attribute still sends strict true', function () {
    Http::fake([
        'api.anthropic.com/*' => $this->fakeTextResponse('72019'),
    ]);

    agent(tools: [new FixedNumberGenerator])->prompt(
        'Give me a random number',
        provider: 'anthropic',
    );

    Http::assertSent(function ($request) {
        $tool = collect($request->data()['tools'] ?? [])
            ->firstWhere('name', 'FixedNumberGenerator');

        return $tool['strict'] === true
            && $tool['input_schema']['type'] === 'object'
            && $tool['input_schema']['additionalProperties'] === false
            && (array) $tool['input_schema']['properties'] === []
            && $tool['input_schema']['required'] === [];
    });
});
