<?php

declare(strict_types=1);

namespace Nene2\Tests\OpenApi;

use PHPUnit\Framework\TestCase;

final class ProblemTypePagesTest extends TestCase
{
    public function testOpenApiProblemTypeExamplesHaveHumanReadablePages(): void
    {
        $openApi = file_get_contents(dirname(__DIR__, 2) . '/docs/openapi/openapi.yaml');

        self::assertIsString($openApi);
        preg_match_all('#https://nene2\.dev/problems/([a-z-]+)#', $openApi, $matches);

        $problemNames = array_unique($matches[1]);
        sort($problemNames);

        self::assertNotSame([], $problemNames);

        foreach ($problemNames as $problemName) {
            $path = dirname(__DIR__, 2) . sprintf('/public_html/problems/%s/index.html', $problemName);

            self::assertFileExists($path);
        }
    }
}
