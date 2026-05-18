<?php

declare(strict_types=1);

namespace Nene2\View;

/**
 * Escapes values for safe HTML output using `htmlspecialchars` with UTF-8 and full quoting.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final readonly class HtmlEscaper
{
    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
