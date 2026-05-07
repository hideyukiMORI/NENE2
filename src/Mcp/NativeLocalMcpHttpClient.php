<?php

declare(strict_types=1);

namespace Nene2\Mcp;

final readonly class NativeLocalMcpHttpClient implements LocalMcpHttpClientInterface
{
    public function get(string $baseUrl, string $path): LocalMcpHttpResponse
    {
        $headers = [
            'Accept: application/json',
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 10,
            ],
        ]);

        $url = rtrim($baseUrl, '/') . $path;
        $body = file_get_contents($url, false, $context);

        if ($body === false) {
            throw new LocalMcpException(sprintf('Local API request failed for "%s".', $url));
        }

        return new LocalMcpHttpResponse(
            $this->statusCode($http_response_header),
            $this->headers($http_response_header),
            $body,
        );
    }

    /**
     * @param list<string> $responseHeaders
     */
    private function statusCode(array $responseHeaders): int
    {
        $statusLine = $responseHeaders[0] ?? '';

        if (preg_match('/\AHTTP\/\S+\s+(\d{3})\b/', $statusLine, $matches) !== 1) {
            throw new LocalMcpException('Local API response did not include an HTTP status line.');
        }

        return (int) $matches[1];
    }

    /**
     * @param list<string> $responseHeaders
     * @return array<string, string>
     */
    private function headers(array $responseHeaders): array
    {
        $headers = [];

        foreach ($responseHeaders as $header) {
            if (!str_contains($header, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $header, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }
}
