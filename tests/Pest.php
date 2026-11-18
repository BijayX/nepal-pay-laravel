<?php

declare(strict_types=1);

use BijayX\NepalPay\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');

/**
 * Load a JSON fixture shared with the Node.js package.
 *
 * @return array<string, mixed>
 */
function gatewayFixture(string $name): array
{
    $contents = file_get_contents(__DIR__."/Fixtures/{$name}.json");

    return json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Convert a camelCase fixture input (shared with the Node.js package) to the
 * snake_case array accepted by PaymentRequest::fromArray().
 *
 * @param  array<string, mixed>  $input
 * @return array<string, mixed>
 */
function snakeInput(array $input): array
{
    $output = [];

    foreach ($input as $key => $value) {
        $snake = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
        $output[$snake] = is_array($value) && $key !== 'metadata' ? snakeInput($value) : $value;
    }

    return $output;
}
