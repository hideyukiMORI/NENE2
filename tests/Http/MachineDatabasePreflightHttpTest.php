<?php

declare(strict_types=1);

namespace Nene2\Tests\Http;

use Nene2\Database\DatabaseConnectionFactoryInterface;
use Nene2\Database\Preflight\CandidateProfile;
use Nene2\Database\Preflight\DefaultDatabaseCandidateInspector;
use Nene2\Http\RuntimeApplicationFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MachineDatabasePreflightHttpTest extends TestCase
{
    private const VERDICT_KEYS = [
        'reachable',
        'schema_recognized',
        'migration_state',
        'populated',
        'recommendation',
        'reason_codes',
        'app_identity',
        'tenant',
    ];

    public function testRequiresApiKey(): void
    {
        $response = $this->application()->handle(
            $this->postRequest(['candidate' => 'restore']),
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/problem+json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('https://nene2.dev/problems/unauthorized', $this->decodeJson($response)['type']);
    }

    public function testReturnsVerdictForKnownCandidate(): void
    {
        $response = $this->application()->handle(
            $this->postRequest(['candidate' => 'restore'])->withHeader('X-NENE2-API-Key', 'test-key'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));

        self::assertSame(self::VERDICT_KEYS, array_keys($payload));
        self::assertTrue($payload['reachable']);
        self::assertTrue($payload['schema_recognized']);
        self::assertSame('compatible', $payload['migration_state']);
        self::assertTrue($payload['populated']);
        self::assertSame('safe', $payload['recommendation']);
        self::assertSame(['compatible'], $payload['reason_codes']);
        self::assertSame('not_evaluated', $payload['app_identity']);
        self::assertSame('not_applicable', $payload['tenant']);
    }

    public function testUnknownCandidateIsValidationError(): void
    {
        $response = $this->application()->handle(
            $this->postRequest(['candidate' => 'ghost'])->withHeader('X-NENE2-API-Key', 'test-key'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/validation-failed', $payload['type']);
        self::assertSame('candidate', $payload['errors'][0]['field']);
        self::assertSame('unknown_candidate', $payload['errors'][0]['code']);
    }

    public function testMissingCandidateFieldIsValidationError(): void
    {
        // A JSON object without the candidate field — distinct from a malformed body (400).
        $response = $this->application()->handle(
            $this->postRequest(['unused' => 'value'])->withHeader('X-NENE2-API-Key', 'test-key'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('candidate', $payload['errors'][0]['field']);
        self::assertSame('required', $payload['errors'][0]['code']);
    }

    public function testInvalidJsonBodyIsBadRequest(): void
    {
        $request = $this->postRequest([])
            ->withHeader('X-NENE2-API-Key', 'test-key')
            ->withBody((new Psr17Factory())->createStream('not json at all'));

        $response = $this->application()->handle($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('https://nene2.dev/problems/invalid-json', $this->decodeJson($response)['type']);
    }

    public function testConnectionDetailsInBodyAreIgnored(): void
    {
        // A caller cannot point the application at an arbitrary host: extra body fields (host, dsn,
        // credentials) are ignored — only the candidate id is read and resolved from configuration.
        $response = $this->application()->handle(
            $this->postRequest([
                'candidate' => 'restore',
                'host' => 'attacker.example.com',
                'dsn' => 'mysql:host=attacker.example.com;dbname=loot',
                'password' => 'steal-me',
            ])->withHeader('X-NENE2-API-Key', 'test-key'),
        );
        $payload = $this->decodeJson($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::VERDICT_KEYS, array_keys($payload));
        self::assertSame('safe', $payload['recommendation']);
    }

    public function testEndpointIsAbsentWithoutInspector(): void
    {
        $factory = new Psr17Factory();
        $application = (new RuntimeApplicationFactory($factory, $factory, machineApiKey: 'test-key'))->create();

        $response = $application->handle(
            $this->postRequest(['candidate' => 'restore'])->withHeader('X-NENE2-API-Key', 'test-key'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    private function application(): \Psr\Http\Server\RequestHandlerInterface
    {
        $factory = new Psr17Factory();
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE phinx_log (version BIGINT PRIMARY KEY, migration_name VARCHAR(100))');
        $pdo->exec("INSERT INTO phinx_log (version, migration_name) VALUES (20260101, 'Init')");

        $connectionFactory = new class ($pdo) implements DatabaseConnectionFactoryInterface {
            public function __construct(private PDO $pdo)
            {
            }

            public function create(): PDO
            {
                return $this->pdo;
            }
        };

        return (new RuntimeApplicationFactory(
            $factory,
            $factory,
            machineApiKey: 'test-key',
            databaseCandidateInspector: new DefaultDatabaseCandidateInspector(['20260101']),
            databaseCandidateProfiles: ['restore' => new CandidateProfile('restore', $connectionFactory)],
        ))->create();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postRequest(array $body): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('POST', 'https://example.test/machine/database/preflight');

        return $request->withBody(
            $factory->createStream(json_encode($body, JSON_THROW_ON_ERROR)),
        );
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
