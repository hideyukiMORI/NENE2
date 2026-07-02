<?php

declare(strict_types=1);

namespace Nene2\Install;

/**
 * The single point through which the reference installer UI escapes output, so nothing an
 * operator typed (database names, domains, error context) can inject markup or script.
 * Quotes and invalid byte sequences are handled too. Part of the opt-in installer toolkit.
 */
final class Html
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
