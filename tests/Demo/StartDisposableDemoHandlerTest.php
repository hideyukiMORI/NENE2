<?php

declare(strict_types=1);

namespace Nene2\Tests\Demo;

use ArrayObject;
use Nene2\Demo\DemoCapacityExceededException;
use Nene2\Demo\DemoCapacityGuardInterface;
use Nene2\Demo\DemoConfig;
use Nene2\Demo\DemoDataSeederInterface;
use Nene2\Demo\DemoErrorPageRendererInterface;
use Nene2\Demo\DemoSessionSeaterInterface;
use Nene2\Demo\DemoTemplateKeyInterface;
use Nene2\Demo\DemoThrottledException;
use Nene2\Demo\DisposableOrgProvisionerInterface;
use Nene2\Demo\MinimalDemoErrorPageRenderer;
use Nene2\Demo\ProvisionedDemoOrg;
use Nene2\Demo\SlugConflictException;
use Nene2\Demo\StartDisposableDemoHandler;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Routing\Router;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class StartDisposableDemoHandlerTest extends TestCase
{
    /**
     * Shared collaborator call log: each stub appends "step:detail" entries.
     *
     * @var ArrayObject<int, string>
     */
    private ArrayObject $events;

    protected function setUp(): void
    {
        $this->events = new ArrayObject();
    }

    public function testDemoModeOffAnswers404WithoutTouchingCollaborators(): void
    {
        $handler = $this->makeHandler(new DemoConfig(demoMode: false));

        $response = $handler->handle($this->makeRequest('kensetsu'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        self::assertSame([], $this->events->getArrayCopy());
    }

    public function testUnknownTemplateAnswers404(): void
    {
        $handler = $this->makeHandler(new DemoConfig(demoMode: true));

        $response = $handler->handle($this->makeRequest('no-such-template'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('no-such-template', (string) $response->getBody());
        self::assertSame([], $this->events->getArrayCopy());
    }

    public function testMissingTemplateParameterAnswers404(): void
    {
        $handler = $this->makeHandler(new DemoConfig(demoMode: true));
        $request = (new Psr17Factory())->createServerRequest('GET', 'https://example.test/demo/');

        $response = $handler->handle($request);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testThrottledClientGets429WithRetryAfter(): void
    {
        $guard = new class () implements DemoCapacityGuardInterface {
            public function assertHasCapacity(): void
            {
            }

            public function assertNotThrottled(ServerRequestInterface $request): void
            {
                throw new DemoThrottledException('Slow down.', 42);
            }
        };
        $handler = $this->makeHandler(new DemoConfig(demoMode: true), guard: $guard);

        $response = $handler->handle($this->makeRequest('kensetsu'));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('42', $response->getHeaderLine('Retry-After'));
        self::assertStringContainsString('Slow down.', (string) $response->getBody());
        self::assertSame([], $this->events->getArrayCopy());
    }

    public function testCapacityExceededAnswers503BeforeProvisioning(): void
    {
        $guard = new class () implements DemoCapacityGuardInterface {
            public function assertHasCapacity(): void
            {
                throw new DemoCapacityExceededException('Demo organization ceiling reached (200 of 200).');
            }

            public function assertNotThrottled(ServerRequestInterface $request): void
            {
            }
        };
        $handler = $this->makeHandler(new DemoConfig(demoMode: true), guard: $guard);

        $response = $handler->handle($this->makeRequest('kensetsu'));

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('demo-capacity-exceeded', (string) $response->getBody());
        self::assertSame([], $this->events->getArrayCopy());
    }

    public function testSuccessRunsGateGuardProvisionSeedSeatInOrder(): void
    {
        $handler = $this->makeHandler(new DemoConfig(demoMode: true, slugPrefix: 'demo-'));

        $response = $handler->handle($this->makeRequest('kensetsu'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/demo-landing', $response->getHeaderLine('Location'));
        self::assertSame(
            [
                'throttle',
                'capacity',
                'provision:demo-:kensetsu',
                'seed:7:kensetsu',
                'seat:7:demo-fixed',
            ],
            $this->events->getArrayCopy(),
        );
    }

    public function testSlugConflictIsRetriedWithAFreshSlug(): void
    {
        $provisioner = new class () implements DisposableOrgProvisionerInterface {
            /** @var list<string> */
            public array $slugs = [];

            public function provision(string $slug, string $template): ProvisionedDemoOrg
            {
                $this->slugs[] = $slug;

                if (count($this->slugs) < 3) {
                    throw new SlugConflictException($slug);
                }

                return new ProvisionedDemoOrg(7, $slug, 9);
            }
        };
        $handler = $this->makeHandler(new DemoConfig(demoMode: true, slugAttempts: 5), provisioner: $provisioner);

        $response = $handler->handle($this->makeRequest('kensetsu'));

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(3, $provisioner->slugs);
        self::assertCount(3, array_unique($provisioner->slugs), 'each retry must use a fresh random slug');

        foreach ($provisioner->slugs as $slug) {
            self::assertStringStartsWith('demo-', $slug);
        }
    }

    public function testExhaustedSlugAttemptsRethrowTheConflict(): void
    {
        $provisioner = new class () implements DisposableOrgProvisionerInterface {
            public int $calls = 0;

            public function provision(string $slug, string $template): ProvisionedDemoOrg
            {
                $this->calls++;

                throw new SlugConflictException($slug);
            }
        };
        $handler = $this->makeHandler(new DemoConfig(demoMode: true, slugAttempts: 3), provisioner: $provisioner);

        try {
            $handler->handle($this->makeRequest('kensetsu'));
            self::fail('Expected SlugConflictException.');
        } catch (SlugConflictException) {
        }

        self::assertSame(3, $provisioner->calls);

        foreach ($this->events->getArrayCopy() as $event) {
            self::assertStringStartsNotWith('seed:', $event);
            self::assertStringStartsNotWith('seat:', $event);
        }
    }

    public function testBrowserGets429AsHtmlWithStatusRetryAfterAndNoindexPreserved(): void
    {
        $handler = $this->makeHandler(new DemoConfig(demoMode: true), guard: $this->throttledGuard(90));

        $response = $handler->handle($this->makeRequest('kensetsu', accept: 'text/html,application/xhtml+xml'));

        self::assertSame(429, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame('90', $response->getHeaderLine('Retry-After'), 'Retry-After must survive negotiation');
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('<html', $body);
        self::assertStringContainsString('about 2 minutes', $body, 'the bundled renderer turns Retry-After into a human hint');
        self::assertStringNotContainsString('Slow down.', $body, 'the Problem Details detail must not leak into the page');
    }

    public function testApiClientErrorStaysByteIdenticalProblemJson(): void
    {
        $handler = $this->makeHandler(new DemoConfig(demoMode: true), guard: $this->throttledGuard(42));

        $jsonAccept = $handler->handle($this->makeRequest('kensetsu', accept: 'application/json'));
        $noAccept = $handler->handle($this->makeRequest('kensetsu'));

        foreach ([$jsonAccept, $noAccept] as $response) {
            self::assertSame(429, $response->getStatusCode());
            self::assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
            self::assertSame('42', $response->getHeaderLine('Retry-After'));
            self::assertFalse($response->hasHeader('X-Robots-Tag'));
            self::assertStringContainsString('Slow down.', (string) $response->getBody());
        }

        self::assertSame((string) $jsonAccept->getBody(), (string) $noAccept->getBody());
        self::assertSame($jsonAccept->getHeaders(), $noAccept->getHeaders());
    }

    public function testSuccessResponseIsNotNegotiatedForBrowsers(): void
    {
        $handler = $this->makeHandler(new DemoConfig(demoMode: true));

        $response = $handler->handle($this->makeRequest('kensetsu', accept: 'text/html'));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/demo-landing', $response->getHeaderLine('Location'));
        self::assertFalse($response->hasHeader('X-Robots-Tag'));
        self::assertSame('', (string) $response->getBody(), 'the seater response must pass through untouched');
    }

    public function testHtmlErrorPageNeverEchoesTheRequestedTemplate(): void
    {
        $handler = $this->makeHandler(new DemoConfig(demoMode: true));
        $hostile = '<script>alert(1)</script>';

        $response = $handler->handle($this->makeRequest($hostile, accept: 'text/html'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringNotContainsString($hostile, $body);
        self::assertStringNotContainsString('alert(1)', $body);
        self::assertStringNotContainsString(htmlspecialchars($hostile, ENT_QUOTES, 'UTF-8'), $body, 'not even escaped input may appear');
    }

    public function testCustomRendererIsUsedButTransportInvariantsAreEnforced(): void
    {
        $renderer = new class () implements DemoErrorPageRendererInterface {
            public ?int $receivedRetryAfter = null;

            public function render(int $statusCode, ?int $retryAfterSeconds): ResponseInterface
            {
                $this->receivedRetryAfter = $retryAfterSeconds;

                // A deliberately misbehaving renderer: wrong status, no headers.
                $response = (new Psr17Factory())->createResponse(200);
                $response->getBody()->write('CUSTOM PAGE');

                return $response;
            }
        };
        $handler = $this->makeHandler(new DemoConfig(demoMode: true), guard: $this->throttledGuard(42), renderer: $renderer);

        $response = $handler->handle($this->makeRequest('kensetsu', accept: 'text/html'));

        self::assertSame('CUSTOM PAGE', (string) $response->getBody());
        self::assertSame(42, $renderer->receivedRetryAfter);
        self::assertSame(429, $response->getStatusCode(), 'the handler resets the original error status');
        self::assertSame('42', $response->getHeaderLine('Retry-After'), 'the handler re-applies Retry-After');
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'), 'the handler forces noindex');
    }

    private function throttledGuard(int $retryAfterSeconds): DemoCapacityGuardInterface
    {
        return new class ($retryAfterSeconds) implements DemoCapacityGuardInterface {
            public function __construct(private readonly int $retryAfterSeconds)
            {
            }

            public function assertHasCapacity(): void
            {
            }

            public function assertNotThrottled(ServerRequestInterface $request): void
            {
                throw new DemoThrottledException('Slow down.', $this->retryAfterSeconds);
            }
        };
    }

    private function makeRequest(string $template, ?string $accept = null): ServerRequestInterface
    {
        $request = (new Psr17Factory())
            ->createServerRequest('GET', 'https://example.test/demo/' . rawurlencode($template))
            ->withAttribute(Router::PARAMETERS_ATTRIBUTE, ['template' => $template]);

        return $accept === null ? $request : $request->withHeader('Accept', $accept);
    }

    private function makeHandler(
        DemoConfig $config,
        ?DemoCapacityGuardInterface $guard = null,
        ?DisposableOrgProvisionerInterface $provisioner = null,
        ?DemoErrorPageRendererInterface $renderer = null,
    ): StartDisposableDemoHandler {
        $factory = new Psr17Factory();
        $events = $this->events;

        $guard ??= new class ($events) implements DemoCapacityGuardInterface {
            /** @param ArrayObject<int, string> $events */
            public function __construct(private ArrayObject $events)
            {
            }

            public function assertHasCapacity(): void
            {
                $this->events[] = 'capacity';
            }

            public function assertNotThrottled(ServerRequestInterface $request): void
            {
                $this->events[] = 'throttle';
            }
        };

        $provisioner ??= new class ($events) implements DisposableOrgProvisionerInterface {
            /** @param ArrayObject<int, string> $events */
            public function __construct(private ArrayObject $events)
            {
            }

            public function provision(string $slug, string $template): ProvisionedDemoOrg
            {
                $this->events[] = 'provision:' . substr($slug, 0, 5) . ':' . $template;

                return new ProvisionedDemoOrg(7, 'demo-fixed', 9);
            }
        };

        $seeder = new class ($events) implements DemoDataSeederInterface {
            /** @param ArrayObject<int, string> $events */
            public function __construct(private ArrayObject $events)
            {
            }

            public function seed(int $orgId, DemoTemplateKeyInterface $template): void
            {
                $this->events[] = "seed:{$orgId}:{$template->value()}";
            }
        };

        $seater = new class ($events, $factory) implements DemoSessionSeaterInterface {
            /** @param ArrayObject<int, string> $events */
            public function __construct(private ArrayObject $events, private Psr17Factory $factory)
            {
            }

            public function seatAndRedirect(ServerRequestInterface $request, ProvisionedDemoOrg $org): ResponseInterface
            {
                $this->events[] = "seat:{$org->orgId}:{$org->slug}";

                return $this->factory->createResponse(302)->withHeader('Location', '/demo-landing');
            }
        };

        return new StartDisposableDemoHandler(
            $config,
            $guard,
            $provisioner,
            $seeder,
            $seater,
            new ProblemDetailsResponseFactory($factory, $factory),
            FakeDemoTemplate::class,
            $renderer ?? new MinimalDemoErrorPageRenderer(),
        );
    }
}
