<?php

declare(strict_types=1);

namespace Nene2\Tests\OpenApi;

use PHPUnit\Framework\TestCase;

final class OpenApiProblemDetailsTest extends TestCase
{
    public function testProblemDetailsSchemasAreDocumented(): void
    {
        $openApi = $this->openApi();

        self::assertStringContainsString('ProblemDetails:', $openApi);
        self::assertStringContainsString('ValidationProblemDetails:', $openApi);
        self::assertStringContainsString('ValidationError:', $openApi);
        self::assertStringContainsString('application/problem+json:', $openApi);
    }

    public function testCanonicalProblemTypeExamplesAreDocumented(): void
    {
        $openApi = $this->openApi();

        foreach (
            [
                'https://nene2.dev/problems/not-found',
                'https://nene2.dev/problems/method-not-allowed',
                'https://nene2.dev/problems/validation-failed',
                'https://nene2.dev/problems/internal-server-error',
            ] as $problemType
        ) {
            self::assertStringContainsString($problemType, $openApi);
        }
    }

    private function openApi(): string
    {
        $openApi = file_get_contents(dirname(__DIR__, 2) . '/docs/openapi/openapi.yaml');

        self::assertIsString($openApi);

        return $openApi;
    }
}
