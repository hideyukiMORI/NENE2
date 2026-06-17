<?php

declare(strict_types=1);

namespace Nene2\Tests\OpenApi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Structural contract test for the RFC 9457 Problem Details surface of the
 * OpenAPI document. Parses the YAML so that schema drift (e.g. dropping the
 * `code` field from a validation error, or wiring a 422 to an inline schema
 * instead of the shared response) fails the build instead of slipping through
 * a substring match.
 */
final class OpenApiProblemDetailsTest extends TestCase
{
    public function testProblemDetailsSchemaHasRfc9457Shape(): void
    {
        $schema = $this->schemas()['ProblemDetails'];

        self::assertSame('object', $schema['type']);
        self::assertSame(['type', 'title', 'status'], $schema['required']);
        self::assertSame(
            ['type', 'title', 'status', 'detail', 'instance'],
            array_keys($schema['properties']),
        );
        self::assertSame('string', $schema['properties']['type']['type']);
        self::assertSame('integer', $schema['properties']['status']['type']);
    }

    public function testValidationProblemDetailsExtendsProblemDetailsWithErrors(): void
    {
        $schema = $this->schemas()['ValidationProblemDetails'];

        self::assertArrayHasKey('allOf', $schema, 'ValidationProblemDetails should extend ProblemDetails via allOf');

        $base = array_column($schema['allOf'], '$ref');
        self::assertContains('#/components/schemas/ProblemDetails', $base);

        $extension = null;
        foreach ($schema['allOf'] as $member) {
            if (isset($member['properties']['errors'])) {
                $extension = $member;
                break;
            }
        }

        self::assertNotNull($extension, 'ValidationProblemDetails should add an errors property');
        self::assertContains('errors', $extension['required']);

        $errors = $extension['properties']['errors'];
        self::assertSame('array', $errors['type']);
        self::assertSame(1, $errors['minItems']);
        self::assertSame('#/components/schemas/ValidationError', $errors['items']['$ref']);
    }

    public function testValidationErrorRequiresFieldMessageAndCode(): void
    {
        $schema = $this->schemas()['ValidationError'];

        self::assertSame('object', $schema['type']);
        self::assertEqualsCanonicalizing(['field', 'message', 'code'], $schema['required']);
        self::assertEqualsCanonicalizing(['field', 'message', 'code'], array_keys($schema['properties']));
    }

    #[DataProvider('sharedProblemResponses')]
    public function testSharedProblemResponsesUseProblemJson(string $responseName, string $expectedSchemaRef): void
    {
        $responses = $this->openApi()['components']['responses'];

        self::assertArrayHasKey($responseName, $responses, "Missing shared response component: {$responseName}");

        $content = $responses[$responseName]['content'];
        self::assertArrayHasKey(
            'application/problem+json',
            $content,
            "{$responseName} must be served as application/problem+json",
        );
        self::assertSame(
            $expectedSchemaRef,
            $content['application/problem+json']['schema']['$ref'] ?? null,
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function sharedProblemResponses(): iterable
    {
        yield 'Unauthorized' => ['Unauthorized', '#/components/schemas/ProblemDetails'];
        yield 'NotFound' => ['NotFound', '#/components/schemas/ProblemDetails'];
        yield 'MethodNotAllowed' => ['MethodNotAllowed', '#/components/schemas/ProblemDetails'];
        yield 'PayloadTooLarge' => ['PayloadTooLarge', '#/components/schemas/ProblemDetails'];
        yield 'TooManyRequests' => ['TooManyRequests', '#/components/schemas/ProblemDetails'];
        yield 'InvalidJson' => ['InvalidJson', '#/components/schemas/ProblemDetails'];
        yield 'InternalServerError' => ['InternalServerError', '#/components/schemas/ProblemDetails'];
        yield 'ValidationFailed' => ['ValidationFailed', '#/components/schemas/ValidationProblemDetails'];
    }

    public function testEveryValidatingOperationReusesTheSharedValidationResponse(): void
    {
        $operationsWith422 = [];

        foreach ($this->openApi()['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (!is_array($operation) || !isset($operation['responses']['422'])) {
                    continue;
                }

                $label = strtoupper((string) $method) . ' ' . $path;
                $operationsWith422[$label] = $operation['responses']['422']['$ref'] ?? null;
            }
        }

        self::assertNotEmpty($operationsWith422, 'Expected at least one operation documenting a 422 response');

        foreach ($operationsWith422 as $label => $ref) {
            self::assertSame(
                '#/components/responses/ValidationFailed',
                $ref,
                "{$label} must reuse the shared ValidationFailed response instead of inlining a 422 schema",
            );
        }
    }

    public function testCanonicalProblemTypeExamplesAreDocumented(): void
    {
        $document = $this->rawDocument();

        foreach (
            [
                'https://nene2.dev/problems/not-found',
                'https://nene2.dev/problems/method-not-allowed',
                'https://nene2.dev/problems/validation-failed',
                'https://nene2.dev/problems/internal-server-error',
            ] as $problemType
        ) {
            self::assertStringContainsString($problemType, $document);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function openApi(): array
    {
        return Yaml::parse($this->rawDocument());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function schemas(): array
    {
        $schemas = $this->openApi()['components']['schemas'];

        foreach (['ProblemDetails', 'ValidationProblemDetails', 'ValidationError'] as $name) {
            self::assertArrayHasKey($name, $schemas, "Missing schema: {$name}");
        }

        return $schemas;
    }

    private function rawDocument(): string
    {
        $document = file_get_contents(dirname(__DIR__, 2) . '/docs/openapi/openapi.yaml');

        self::assertIsString($document);

        return $document;
    }
}
