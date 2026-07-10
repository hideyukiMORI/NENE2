<?php

declare(strict_types=1);

namespace Nene2\Demo;

use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\Http\SecureTokenHelper;
use Nene2\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * `GET /demo/{template}` — the product-independent orchestration of a disposable
 * demo start: gate → throttle/capacity → slug allocation with conflict retry →
 * provision → seed → seat.
 *
 * Everything product-specific is behind the injected interfaces: org creation
 * ({@see DisposableOrgProvisionerInterface}), seed content
 * ({@see DemoDataSeederInterface}), and the auth handoff + redirect
 * ({@see DemoSessionSeaterInterface}). The route is public by design (it creates
 * its own tenant); the only gates are {@see DemoConfig::$demoMode} — off means a
 * plain 404, indistinguishable from the route not existing — and the
 * {@see DemoCapacityGuardInterface} checks, which run before anything is created.
 *
 * Slug conflicts are retried with a fresh random slug up to
 * {@see DemoConfig::$slugAttempts} times; exhausting the attempts rethrows the
 * last conflict so the error-handling middleware reports it as a plain 500 (with
 * 4 random bytes per candidate this indicates a systemic problem, not bad luck).
 * That escape path is app-wide error handling and is not HTML-negotiated here.
 *
 * Because the route is opened by real people in browsers, error responses are
 * content-negotiated (ADR 0018): when the request's `Accept` header contains
 * `text/html`, the Problem Details 4xx/5xx is replaced by the page from the
 * injected {@see DemoErrorPageRendererInterface} (default:
 * {@see MinimalDemoErrorPageRenderer}). The handler enforces the transport
 * invariants regardless of the renderer — original status and `Retry-After`
 * are copied onto the page and `X-Robots-Tag: noindex` is added. Clients whose
 * `Accept` lacks `text/html` (API callers) and successful responses are
 * byte-for-byte unchanged.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class StartDisposableDemoHandler implements RequestHandlerInterface
{
    /** Route parameter holding the template key, as registered by {@see DemoRouteRegistrar}. */
    public const string TEMPLATE_PARAMETER = 'template';

    /**
     * @param class-string<DemoTemplateKeyInterface> $templateKeyClass The product's
     *        template enum/class; the raw route parameter is validated through its
     *        {@see DemoTemplateKeyInterface::tryFromValue()}.
     */
    public function __construct(
        private DemoConfig $config,
        private DemoCapacityGuardInterface $capacityGuard,
        private DisposableOrgProvisionerInterface $provisioner,
        private DemoDataSeederInterface $seeder,
        private DemoSessionSeaterInterface $seater,
        private ProblemDetailsResponseFactory $problemDetails,
        private string $templateKeyClass,
        private DemoErrorPageRendererInterface $errorPageRenderer = new MinimalDemoErrorPageRenderer(),
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->startDemo($request);

        if ($response->getStatusCode() < 400 || !str_contains($request->getHeaderLine('Accept'), 'text/html')) {
            return $response;
        }

        return $this->browserErrorPage($response);
    }

    private function startDemo(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->config->demoMode) {
            return $this->problemDetails->create($request, 'not-found', 'Not Found', 404, 'Demo mode is not enabled on this instance.');
        }

        $rawTemplate = Router::param($request, self::TEMPLATE_PARAMETER) ?? '';
        $template = ($this->templateKeyClass)::tryFromValue($rawTemplate);

        if ($template === null) {
            return $this->problemDetails->create($request, 'not-found', 'Not Found', 404, sprintf("Unknown demo template '%s'.", $rawTemplate));
        }

        try {
            $this->capacityGuard->assertNotThrottled($request);
        } catch (DemoThrottledException $exception) {
            return $this->problemDetails
                ->create($request, 'too-many-requests', 'Too Many Requests', 429, $exception->getMessage())
                ->withHeader('Retry-After', (string) $exception->retryAfterSeconds);
        }

        try {
            $this->capacityGuard->assertHasCapacity();
        } catch (DemoCapacityExceededException $exception) {
            return $this->problemDetails->create($request, 'demo-capacity-exceeded', 'Service Unavailable', 503, $exception->getMessage());
        }

        $org = $this->provisionWithRetry($template);
        $this->seeder->seed($org->orgId, $template);

        return $this->seater->seatAndRedirect($request, $org);
    }

    /**
     * Replaces a Problem Details error with the renderer's HTML page while
     * enforcing the invariants the framework guarantees no matter which
     * renderer is wired: the page carries the original error status, the
     * original `Retry-After` header (429), and `X-Robots-Tag: noindex`.
     */
    private function browserErrorPage(ResponseInterface $problem): ResponseInterface
    {
        $retryAfter = $problem->hasHeader('Retry-After')
            ? max(0, (int) $problem->getHeaderLine('Retry-After'))
            : null;

        $page = $this->errorPageRenderer
            ->render($problem->getStatusCode(), $retryAfter)
            ->withStatus($problem->getStatusCode())
            ->withHeader('X-Robots-Tag', 'noindex');

        if ($problem->hasHeader('Retry-After')) {
            $page = $page->withHeader('Retry-After', $problem->getHeaderLine('Retry-After'));
        }

        return $page;
    }

    /**
     * @throws SlugConflictException when every slug candidate collided.
     */
    private function provisionWithRetry(DemoTemplateKeyInterface $template): ProvisionedDemoOrg
    {
        $lastConflict = null;

        for ($attempt = 0; $attempt < $this->config->slugAttempts; $attempt++) {
            $slug = $this->config->slugPrefix . SecureTokenHelper::generate(4);

            try {
                return $this->provisioner->provision($slug, $template->value());
            } catch (SlugConflictException $exception) {
                $lastConflict = $exception;
            }
        }

        throw $lastConflict ?? new SlugConflictException('No slug attempt was made.');
    }
}
