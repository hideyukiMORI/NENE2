<?php

declare(strict_types=1);

namespace Nene2\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Recovers the Bearer token on hosts whose front proxy strips the standard
 * `Authorization` header before it reaches PHP (observed on HETEML-class shared
 * hosting: custom headers pass through, `Authorization` does not, so neither
 * `.htaccess` `E=HTTP_AUTHORIZATION` tricks nor `CGIPassAuth` can help).
 *
 * The NENE2 frontend client (`@hideyukimori/nene2-client` — see ADR 0019 for the
 * exact wire contract) mirrors the token into `X-Authorization` on every request.
 * This middleware adopts that mirror **only when `Authorization` is absent or
 * empty**, so environments that deliver the standard header are byte-for-byte
 * unaffected. The header name is fixed (`FALLBACK_HEADER`) — it is a fleet-wide
 * wiring contract with the frontend client, not a tuning knob.
 *
 * Method-independent and path-independent: the mirror is sent on every request,
 * and downstream auth middleware decides which paths actually require a token.
 *
 * **Not enabled by default.** Adopting a non-standard client-controlled header
 * as `Authorization` changes the trust boundary: deployments where an upstream
 * gateway deliberately strips `Authorization` (delegated authentication, WAF
 * filtering) must not gain a bypass surface silently. Opt in per deployment via
 * {@see \Nene2\Http\RuntimeApplicationFactory} (`enableAuthorizationHeaderFallback`)
 * or by placing this middleware before your auth middleware. See ADR 0019.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class AuthorizationHeaderFallbackMiddleware implements MiddlewareInterface
{
    public const string FALLBACK_HEADER = 'X-Authorization';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle(self::apply($request));
    }

    /**
     * Applies the fallback outside a PSR-15 pipeline (e.g. hand-rolled front
     * controllers). The middleware itself delegates here.
     */
    public static function apply(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->getHeaderLine('Authorization') !== '') {
            return $request;
        }

        $fallback = $request->getHeaderLine(self::FALLBACK_HEADER);

        if ($fallback === '') {
            return $request;
        }

        return $request->withHeader('Authorization', $fallback);
    }
}
