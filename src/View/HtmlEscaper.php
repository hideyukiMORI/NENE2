<?php

declare(strict_types=1);

namespace Nene2\View;

final readonly class HtmlEscaper
{
    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
