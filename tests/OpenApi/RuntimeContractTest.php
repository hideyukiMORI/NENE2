<?php

declare(strict_types=1);

namespace Nene2\Tests\OpenApi;

use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Yaml\Yaml;

final class RuntimeContractTest extends TestCase
{
    /**
     * @param array<string, mixed> $expectedPayload
     * @param array<string, mixed> $schema
     */
    #[DataProvider('successEndpointProvider')]
    public function testRuntimeSuccessResponsesMatchOpenApiExamples(
        string $method,
        string $path,
        int $expectedStatus,
        array $expectedPayload,
        array $schema,
    ): void {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory))->create();

        $response = $application->handle($factory->createServerRequest($method, 'https://example.test' . $path));
        $payload = $this->decodeJson($response);

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame($expectedPayload, $payload);

        self::assertMatchesSchema($schema, $payload);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: array<string, mixed>, 4: array<string, mixed>}>
     */
    public static function successEndpointProvider(): iterable
    {
        $openApi = self::openApi();

        foreach ($openApi['paths'] as $path => $pathItem) {
            if (!is_string($path) || !is_array($pathItem)) {
                continue;
            }

            foreach ($pathItem as $method => $operation) {
                if (!is_string($method) || !is_array($operation)) {
                    continue;
                }

                $successResponse = $operation['responses']['200'] ?? null;

                if (!is_array($successResponse)) {
                    continue;
                }

                $jsonContent = $successResponse['content']['application/json'] ?? null;

                if (!is_array($jsonContent)) {
                    continue;
                }

                $example = $jsonContent['examples']['ok']['value'] ?? null;
                $schemaRef = $jsonContent['schema']['$ref'] ?? null;

                if (!is_array($example) || !is_string($schemaRef)) {
                    continue;
                }

                yield sprintf('%s %s', strtoupper($method), $path) => [
                    strtoupper($method),
                    $path,
                    200,
                    $example,
                    self::schemaForReference($openApi, $schemaRef),
                ];
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function openApi(): array
    {
        $openApi = Yaml::parseFile(dirname(__DIR__, 2) . '/docs/openapi/openapi.yaml');

        self::assertIsArray($openApi);

        return $openApi;
    }

    /**
     * @param array<string, mixed> $openApi
     * @return array<string, mixed>
     */
    private static function schemaForReference(array $openApi, string $schemaRef): array
    {
        $schemaName = str_replace('#/components/schemas/', '', $schemaRef);
        $schema = $openApi['components']['schemas'][$schemaName] ?? null;

        self::assertIsArray($schema, sprintf('Schema "%s" must exist.', $schemaName));

        return $schema;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $payload
     */
    private static function assertMatchesSchema(array $schema, array $payload): void
    {
        self::assertSame('object', $schema['type'] ?? null, 'Runtime contract success schemas must be objects.');

        $required = $schema['required'] ?? [];

        self::assertIsArray($required, 'Schema required fields must be a list.');

        foreach ($required as $requiredField) {
            self::assertIsString($requiredField);
            self::assertArrayHasKey($requiredField, $payload);
        }

        $properties = $schema['properties'] ?? [];

        self::assertIsArray($properties, 'Schema properties must be a map.');

        foreach ($payload as $field => $value) {
            if (!array_key_exists($field, $properties)) {
                self::assertNotFalse(
                    $schema['additionalProperties'] ?? true,
                    sprintf('Unexpected response field "%s".', $field),
                );

                continue;
            }

            $property = $properties[$field];

            self::assertIsArray($property, sprintf('Schema property "%s" must be a map.', $field));
            self::assertValueMatchesPropertySchema($field, $property, $value);
        }
    }

    /**
     * @param array<string, mixed> $property
     */
    private static function assertValueMatchesPropertySchema(string $field, array $property, mixed $value): void
    {
        $type = $property['type'] ?? null;

        if ($type === 'string') {
            self::assertIsString($value, sprintf('Field "%s" must be a string.', $field));
        } elseif ($type === 'integer') {
            self::assertIsInt($value, sprintf('Field "%s" must be an integer.', $field));
        } elseif ($type === 'number') {
            self::assertIsNumeric($value, sprintf('Field "%s" must be numeric.', $field));
        } elseif ($type === 'boolean') {
            self::assertIsBool($value, sprintf('Field "%s" must be a boolean.', $field));
        } elseif ($type === 'array') {
            self::assertIsArray($value, sprintf('Field "%s" must be an array.', $field));
        } elseif ($type !== null) {
            self::fail(sprintf('Unsupported schema type "%s" for field "%s".', (string) $type, $field));
        }

        $enum = $property['enum'] ?? null;

        if (is_array($enum)) {
            self::assertContains($value, $enum, sprintf('Field "%s" must match a documented enum value.', $field));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(ResponseInterface $response): array
    {
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($payload);

        return $payload;
    }
}
