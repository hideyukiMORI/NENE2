<?php

declare(strict_types=1);

namespace Nene2\Http;

use Nene2\Database\Preflight\CandidateProfile;
use Nene2\Database\Preflight\DatabaseCandidateInspector;
use Nene2\Error\DomainExceptionHandlerInterface;
use Nene2\Error\ErrorHandlerMiddleware;
use Nene2\Error\ProblemDetailsResponseFactory;
use Nene2\FrameworkInfo;
use Nene2\Log\RequestIdHolder;
use Nene2\Middleware\ApiKeyAuthenticationMiddleware;
use Nene2\Middleware\AuthorizationHeaderFallbackMiddleware;
use Nene2\Middleware\CorsMiddleware;
use Nene2\Middleware\MiddlewareDispatcher;
use Nene2\Middleware\RequestIdMiddleware;
use Nene2\Middleware\RequestLoggingMiddleware;
use Nene2\Middleware\RequestSizeLimitMiddleware;
use Nene2\Middleware\SecurityHeadersMiddleware;
use Nene2\Middleware\ThrottleMiddleware;
use Nene2\Routing\Router;
use Nene2\Validation\ValidationError;
use Nene2\Validation\ValidationException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Assembles the full PSR-15 middleware pipeline and {@see Router} for a NENE2 application.
 *
 * Construct this once at the application entry point, pass your route registrars, health
 * checks, and optional middleware, then call {@see create()} to obtain the request handler
 * that your front controller dispatches to.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class RuntimeApplicationFactory
{
    /**
     * @param list<DomainExceptionHandlerInterface> $domainExceptionHandlers
     * @param list<callable(Router): void> $routeRegistrars
     * @param MiddlewareInterface|list<MiddlewareInterface>|null $authMiddleware Authentication middleware injected
     *                                              into the pipeline after the request size limit. Accepts a single
     *                                              {@see MiddlewareInterface} or a list of middlewares that are
     *                                              pushed in order (first item runs first). Use a list to stack
     *                                              multiple middlewares — e.g. a tenant extractor followed by a JWT
     *                                              verifier — without wrapping them in a composite.
     * @param list<HealthCheckInterface> $healthChecks
     * @param list<string> $allowedOrigins CORS-allowed origins (e.g. `['https://app.example.com']`).
     *                                     An empty list (the default) silently disables all CORS headers.
     *                                     Always set this explicitly in production.
     * @param list<string> $machineApiKeyExcludedPaths Paths that bypass the machine API key check even when
     *                                                  `$machineApiKey` is set. Useful when a route should remain
     *                                                  public while all other routes require an API key.
     *                                                  Only has effect when `$machineApiKey` is non-null and
     *                                                  `$machineApiKeyProtectedPaths` is empty.
     * @param list<string> $machineApiKeyProtectedPaths Exact paths protected by the machine API key. Combined with
     *                                                   `$machineApiKeyProtectedPathPrefixes` in union mode — a path
     *                                                   is protected when it matches any exact entry OR any prefix.
     *                                                   Defaults to `['/machine/health']`.
     * @param list<string> $machineApiKeyProtectedPathPrefixes Path prefixes protected by the machine API key
     *                                                          (e.g. `['/admin/']` matches `/admin/users/42`).
     *                                                          Combined with `$machineApiKeyProtectedPaths` in union
     *                                                          mode.
     * @param list<string> $machineApiKeyProtectedMethods HTTP methods that require the machine API key
     *                                                     (uppercase, e.g. `['POST', 'PUT', 'DELETE']`).
     *                                                     When non-empty, GET/HEAD requests pass through
     *                                                     without a key even on protected paths. Useful for
     *                                                     "public read / key-gated write" patterns.
     * @param bool $debug When true, unhandled exception messages are exposed in 500 `detail`.
     *                    Never set to true in production.
     * @param int $requestMaxBodyBytes Maximum allowed request body size in bytes. Requests exceeding
     *                                 this limit are rejected with a 413 Problem Details response.
     *                                 Defaults to 1 MiB (1 048 576 bytes). Increase for bulk-import
     *                                 or large-payload endpoints.
     * @param string $problemDetailsBaseUrl Base URL prefixed to the `type` field of framework-level
     *                                       Problem Details responses (validation failures, 404, 405,
     *                                       413, 500, etc.). Pass `AppConfig::$problemDetailsBaseUrl`
     *                                       (env `PROBLEM_DETAILS_BASE_URL`) so framework and domain
     *                                       errors share the same `type` namespace. Defaults to
     *                                       `https://nene2.dev/problems/` for backward compatibility.
     * @param string|null $appVersion The consuming application's own semantic version (e.g. `'1.4.2'`),
     *                                surfaced on `GET /machine/health` as the `version` field. Each app
     *                                injects its own value from wherever it tracks its release (its
     *                                `composer.json`, a `VERSION` constant, or an environment variable);
     *                                leave it null to omit the field entirely. This is the *application*
     *                                version and is intentionally distinct from the *framework* version
     *                                ({@see FrameworkInfo::VERSION}), which is always reported separately
     *                                as `framework_version`. Surfacing it on the auth-gated machine
     *                                endpoint keeps the application version readable by machine clients
     *                                (monitoring, operators, deployment tooling) without exposing it on
     *                                the public `GET /health`.
     * @param DatabaseCandidateInspector|null $databaseCandidateInspector When provided, registers the
     *                                auth-gated `POST /machine/database/preflight` endpoint, which
     *                                inspects a candidate database read-only and returns a structured
     *                                verdict (see issue #1419). Leave null to omit the endpoint entirely
     *                                — the framework core stays database-agnostic for applications that
     *                                do not opt in. The path is automatically added to the machine API
     *                                key protected paths when an allowlist is in effect.
     * @param array<string, CandidateProfile> $databaseCandidateProfiles Candidate profiles keyed by the
     *                                id the caller references. The endpoint resolves a profile from this
     *                                map; connection details and credentials are never read from the
     *                                request body. Only consulted when $databaseCandidateInspector is set.
     * @param bool $enableHsts Emit `Strict-Transport-Security` from the security-headers middleware.
     *                         Off by default; enable only when the app is served over HTTPS (directly or
     *                         behind a TLS-terminating proxy). See {@see SecurityHeadersMiddleware}.
     * @param bool $enableAuthorizationHeaderFallback Adopt the `X-Authorization` mirror header as
     *                         `Authorization` when the standard header is absent or empty. Off by
     *                         default; enable only on deployments whose front proxy strips
     *                         `Authorization` before it reaches PHP (HETEML-class shared hosting) —
     *                         on gateways that strip the header *deliberately* (delegated auth, WAF
     *                         filtering) enabling this would open a client-controlled bypass. Runs
     *                         at the start of the auth stage, before the machine API key check and
     *                         any injected `$authMiddleware`. See
     *                         {@see AuthorizationHeaderFallbackMiddleware} and ADR 0019.
     */
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private ?LoggerInterface $logger = null,
        private ?string $machineApiKey = null,
        private array $domainExceptionHandlers = [],
        private ?RequestIdHolder $requestIdHolder = null,
        private array $routeRegistrars = [],
        private MiddlewareInterface|array|null $authMiddleware = null,
        private array $healthChecks = [],
        private ?ThrottleMiddleware $throttleMiddleware = null,
        private bool $debug = false,
        private array $allowedOrigins = [],
        private array $machineApiKeyExcludedPaths = [],
        private array $machineApiKeyProtectedPaths = ['/machine/health'],
        private array $machineApiKeyProtectedPathPrefixes = [],
        private array $machineApiKeyProtectedMethods = [],
        private int $requestMaxBodyBytes = 1_048_576,
        private string $problemDetailsBaseUrl = 'https://nene2.dev/problems/',
        private ?string $appVersion = null,
        private ?DatabaseCandidateInspector $databaseCandidateInspector = null,
        private array $databaseCandidateProfiles = [],
        private bool $enableHsts = false,
        private bool $enableAuthorizationHeaderFallback = false,
    ) {
    }

    public function create(): RequestHandlerInterface
    {
        $logger = $this->logger ?? new NullLogger();

        /** @var list<MiddlewareInterface> $authMiddlewares */
        $authMiddlewares = match (true) {
            $this->authMiddleware === null  => [],
            is_array($this->authMiddleware) => $this->authMiddleware,
            default                         => [$this->authMiddleware],
        };

        $logger->info('NENE2 runtime started.', [
            'machine_api_key' => $this->machineApiKey !== null,
            'auth_middleware' => $authMiddlewares !== [],
        ]);

        $jsonResponses = new JsonResponseFactory($this->responseFactory, $this->streamFactory);
        $problemDetails = new ProblemDetailsResponseFactory(
            $this->responseFactory,
            $this->streamFactory,
            $this->problemDetailsBaseUrl,
        );
        $framework = new FrameworkInfo();
        $appVersion = $this->appVersion;

        $router = (new Router())
            ->get(
                '/',
                static fn (ServerRequestInterface $request) => $jsonResponses->create([
                    'name' => $framework->name(),
                    'description' => $framework->description(),
                    'status' => 'ok',
                ]),
            )
            ->get(
                '/health',
                function (ServerRequestInterface $request) use ($jsonResponses, $framework) {
                    if ($this->healthChecks === []) {
                        return $jsonResponses->create([
                            'status' => 'ok',
                            'service' => $framework->name(),
                        ]);
                    }

                    $checks = [];
                    $degraded = false;

                    foreach ($this->healthChecks as $check) {
                        try {
                            $status = $check->check();
                        } catch (Throwable) {
                            $status = HealthStatus::Error;
                        }

                        $checks[$check->name()] = $status->value;

                        if ($status === HealthStatus::Error) {
                            $degraded = true;
                        }
                    }

                    return $jsonResponses->create(
                        [
                            'status' => $degraded ? 'degraded' : 'ok',
                            'service' => $framework->name(),
                            'checks' => $checks,
                        ],
                        $degraded ? 503 : 200,
                    );
                },
            )
            ->get(
                '/machine/health',
                function (ServerRequestInterface $request) use ($jsonResponses, $framework, $appVersion) {
                    $payload = [
                        'status' => 'ok',
                        'service' => $framework->name(),
                    ];

                    if ($appVersion !== null) {
                        $payload['version'] = $appVersion;
                    }

                    $payload['framework_version'] = FrameworkInfo::VERSION;
                    $payload['credential_type'] = $request->getAttribute('nene2.auth.credential_type');

                    return $jsonResponses->create($payload);
                },
            )
            ->get(
                '/examples/ping',
                static fn (ServerRequestInterface $request) => $jsonResponses->create([
                    'message' => 'pong',
                    'status' => 'ok',
                ]),
            )
        ;

        if ($authMiddlewares !== []) {
            $router->get(
                '/examples/protected',
                static fn (ServerRequestInterface $request) => $jsonResponses->create([
                    'message' => 'Welcome, authenticated user.',
                    'claims' => $request->getAttribute('nene2.auth.claims') ?? [],
                ]),
            );
        }

        if ($this->databaseCandidateInspector !== null) {
            $inspector = $this->databaseCandidateInspector;
            $profiles = $this->databaseCandidateProfiles;

            $router->post(
                '/machine/database/preflight',
                static function (ServerRequestInterface $request) use ($jsonResponses, $inspector, $profiles) {
                    $body = JsonRequestBodyParser::parse($request);
                    $candidateId = $body['candidate'] ?? null;

                    // Connection details / credentials in the body are intentionally ignored — only the
                    // candidate id is read, and the profile (and its connection) is resolved from the
                    // application's own configuration. This blocks SSRF and keeps credentials off the wire.
                    if (!is_string($candidateId) || $candidateId === '') {
                        throw new ValidationException([
                            new ValidationError('candidate', 'A candidate profile id is required.', 'required'),
                        ]);
                    }

                    $profile = $profiles[$candidateId] ?? null;

                    if (!$profile instanceof CandidateProfile) {
                        throw new ValidationException([
                            new ValidationError('candidate', 'Unknown candidate profile.', 'unknown_candidate'),
                        ]);
                    }

                    return $jsonResponses->create($inspector->inspect($profile)->toArray());
                },
            );
        }

        foreach ($this->routeRegistrars as $registrar) {
            $registrar($router);
        }

        // Auto-protect the preflight endpoint with the machine API key. Only append when an allowlist
        // is already in effect — appending to "protect all" mode (both lists empty) would silently
        // switch it to allowlist mode and un-protect every other path.
        $machineProtectedPaths = $this->machineApiKeyProtectedPaths;
        $allowlistMode = $this->machineApiKeyProtectedPaths !== [] || $this->machineApiKeyProtectedPathPrefixes !== [];

        if (
            $this->databaseCandidateInspector !== null
            && $allowlistMode
            && !in_array('/machine/database/preflight', $machineProtectedPaths, true)
        ) {
            $machineProtectedPaths[] = '/machine/database/preflight';
        }

        $middlewareStack = [
            new RequestIdMiddleware('X-Request-Id', $this->requestIdHolder),
            new RequestLoggingMiddleware($logger),
            new SecurityHeadersMiddleware(enableHsts: $this->enableHsts),
            new CorsMiddleware($this->responseFactory, $this->allowedOrigins),
            new ErrorHandlerMiddleware($problemDetails, $this->domainExceptionHandlers, $this->debug, $logger),
            new RequestSizeLimitMiddleware($problemDetails, $this->requestMaxBodyBytes, $this->streamFactory),
        ];

        // Start of the auth stage: restore `Authorization` from the `X-Authorization` mirror
        // (opt-in, for proxy-stripped hosting) before any credential-reading middleware runs.
        if ($this->enableAuthorizationHeaderFallback) {
            $middlewareStack[] = new AuthorizationHeaderFallbackMiddleware();
        }

        $middlewareStack[] = new ApiKeyAuthenticationMiddleware(
            $problemDetails,
            $this->machineApiKey,
            $machineProtectedPaths,
            excludedPaths:          $this->machineApiKeyExcludedPaths,
            protectedPathPrefixes:  $this->machineApiKeyProtectedPathPrefixes,
            protectedMethods:       $this->machineApiKeyProtectedMethods,
        );

        foreach ($authMiddlewares as $authMiddleware) {
            $middlewareStack[] = $authMiddleware;
        }

        if ($this->throttleMiddleware !== null) {
            $middlewareStack[] = $this->throttleMiddleware;
        }

        return new MiddlewareDispatcher($middlewareStack, $router);
    }
}
