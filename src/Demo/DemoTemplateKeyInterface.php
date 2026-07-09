<?php

declare(strict_types=1);

namespace Nene2\Demo;

/**
 * A product-defined key identifying one seedable demo template (an "industry" preset).
 *
 * The value is the `{template}` segment of the demo start URL and the key the
 * product's {@see DemoDataSeederInterface} switches on. A string-backed enum
 * satisfies this interface with two one-line methods:
 *
 * ```php
 * enum DemoTemplate: string implements DemoTemplateKeyInterface
 * {
 *     case Kensetsu = 'kensetsu';
 *     case Seisaku = 'seisaku';
 *
 *     public function value(): string
 *     {
 *         return $this->value;
 *     }
 *
 *     public static function tryFromValue(string $value): ?static
 *     {
 *         return self::tryFrom($value);
 *     }
 * }
 * ```
 *
 * {@see StartDisposableDemoHandler} receives the implementing class name and calls
 * {@see tryFromValue()} on the raw route parameter; `null` maps to 404, so an
 * unknown template never reaches provisioning.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
interface DemoTemplateKeyInterface
{
    /** The canonical string form (the `{template}` URL segment). */
    public function value(): string;

    /** Parses a raw, untrusted string; returns null when it names no template. */
    public static function tryFromValue(string $value): ?static;
}
