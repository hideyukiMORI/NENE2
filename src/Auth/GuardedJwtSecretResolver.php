<?php

declare(strict_types=1);

namespace Nene2\Auth;

use Nene2\Config\AppConfig;
use Nene2\Config\AppEnvironment;

/**
 * Resolves the HMAC secret used to sign and verify local bearer tokens, failing closed.
 *
 * The same secret authenticates every operator and service token, so a predictable
 * value is a full authentication bypass (a forged superadmin token). Rather than let
 * each product reimplement the guard, this is the framework default. It applies a
 * hybrid development-secret policy:
 *
 * 1. A non-empty configured secret is always used, in every environment. An empty
 *    string is treated as "unset" and rejected.
 * 2. Otherwise, in {@see AppEnvironment::Production} resolution always throws — the
 *    development-secret opt-in is intentionally ignored, so production can never sign
 *    tokens with a development secret (hard fail).
 * 3. Otherwise (local / test), when the operator has explicitly opted in
 *    (`$allowDevSecret`) and the product injected a non-empty development secret,
 *    that development secret is used.
 * 4. Otherwise resolution throws.
 *
 * The framework does not own a development secret: the product injects it
 * (`?string $devSecret`, `null` disables the development path entirely). This keeps
 * each product on its own development secret so tokens from one product's development
 * environment are not accepted by another.
 *
 * The env-name parameters are used only to build actionable exception messages; they
 * let a product that reads the secret under a custom key (e.g. the serve command's
 * `NENE_SERVE_JWT_SECRET`) surface the correct variable name.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class GuardedJwtSecretResolver
{
    /**
     * @param string $configuredSecret The operator-configured secret; empty string means "unset".
     * @param AppEnvironment $environment The runtime environment; production always rejects the dev path.
     * @param bool $allowDevSecret Whether the operator opted in to the development secret (local/test only).
     * @param string|null $devSecret The product-injected development secret, or null to disable the dev path.
     * @param string $secretEnvName Name of the secret env var, used in exception messages.
     * @param string $optInEnvName Name of the opt-in env var, used in exception messages.
     */
    public function __construct(
        private string $configuredSecret,
        private AppEnvironment $environment,
        private bool $allowDevSecret,
        private ?string $devSecret,
        private string $secretEnvName = 'NENE2_LOCAL_JWT_SECRET',
        private string $optInEnvName = 'NENE2_ALLOW_DEV_SECRET',
    ) {
    }

    /**
     * Resolve the JWT secret or fail closed.
     *
     * @throws JwtSecretException when no secret can be resolved safely.
     */
    public function resolve(): string
    {
        if ($this->configuredSecret !== '') {
            return $this->configuredSecret;
        }

        // Production never signs with a development secret, even behind the opt-in.
        if ($this->environment === AppEnvironment::Production) {
            $this->failClosed();
        }

        if ($this->allowDevSecret && $this->devSecret !== null && $this->devSecret !== '') {
            return $this->devSecret;
        }

        $this->failClosed();
    }

    /**
     * Convenience resolver for the common `NENE2_LOCAL_JWT_SECRET` path: reads the
     * configured secret, environment, and opt-in from typed config, and takes the
     * product-injected development secret (`null` to disable the dev path).
     *
     * @throws JwtSecretException when no secret can be resolved safely.
     */
    public static function fromConfig(AppConfig $config, ?string $devSecret): string
    {
        return (new self(
            $config->localJwtSecret ?? '',
            $config->environment,
            $config->allowDevSecret,
            $devSecret,
        ))->resolve();
    }

    /**
     * @throws JwtSecretException
     */
    private function failClosed(): never
    {
        $base = sprintf(
            '%s is not configured. Set it to a random 32+ byte secret '
            . '(generate one with: php -r "echo bin2hex(random_bytes(32));").',
            $this->secretEnvName,
        );

        if ($this->environment === AppEnvironment::Production) {
            throw new JwtSecretException($base . sprintf(
                ' The development-secret opt-in (%s) is intentionally ignored in production.',
                $this->optInEnvName,
            ));
        }

        throw new JwtSecretException($base . sprintf(
            ' Alternatively, set %s=1 to permit an injected development secret '
            . '(local / development only — never in production).',
            $this->optInEnvName,
        ));
    }
}
