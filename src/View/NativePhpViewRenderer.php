<?php

declare(strict_types=1);

namespace Nene2\View;

use Throwable;

final readonly class NativePhpViewRenderer
{
    public function __construct(
        private string $templateRoot,
        private HtmlEscaper $escaper = new HtmlEscaper(),
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $path = $this->resolveTemplate($template);
        $escape = fn (mixed $value): string => $this->escaper->escape($value);

        ob_start();

        try {
            $this->includeTemplate($path, $data, $escape);

            return (string) ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }
    }

    private function resolveTemplate(string $template): string
    {
        $normalized = ltrim($template, '/');

        if ($normalized === '' || str_contains($normalized, '..')) {
            throw new TemplateNotFoundException(sprintf('Template "%s" was not found.', $template));
        }

        $path = rtrim($this->templateRoot, '/') . '/' . $normalized;

        if (!is_file($path)) {
            throw new TemplateNotFoundException(sprintf('Template "%s" was not found.', $template));
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $data
     * @param callable(mixed): string $escape
     */
    private function includeTemplate(string $path, array $data, callable $escape): void
    {
        (static function () use ($path, $data, $escape): void {
            extract($data, EXTR_SKIP);
            $e = $escape;

            require $path;
        })();
    }
}
