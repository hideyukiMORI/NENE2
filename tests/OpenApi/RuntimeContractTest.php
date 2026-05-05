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
     * @param list<string> $requiredFields
     */
    #[DataProvider('successEndpointProvider')]
    public function testRuntimeSuccessResponsesMatchOpenApiExamples(
        string $method,
        string $path,
        int $expectedStatus,
        array $expectedPayload,
        array $requiredFields,
    ): void {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory))->create();

        $response = $application->handle($factory->createServerRequest($method, 'https://example.test' . $path));
        $payload = $this->decodeJson($response);

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame($expectedPayload, $payload);

        foreach ($requiredFields as $requiredField) {
            self::assertArrayHasKey($requiredField, $payload);
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: int, 3: array<string, mixed>, 4: list<string>}>
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
                    self::requiredFieldsForSchema($openApi, $schemaRef),
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
     * @return list<string>
     */
    private static function requiredFieldsForSchema(array $openApi, string $schemaRef): array
    {
        $schemaName = str_replace('#/components/schemas/', '', $schemaRef);
        $schema = $openApi['components']['schemas'][$schemaName] ?? null;

        self::assertIsArray($schema, sprintf('Schema "%s" must exist.', $schemaName));

        $required = $schema['required'] ?? [];

        self::assertIsArray($required, sprintf('Schema "%s" required fields must be a list.', $schemaName));

        return array_values(array_filter($required, static fn (mixed $field): bool => is_string($field)));
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
