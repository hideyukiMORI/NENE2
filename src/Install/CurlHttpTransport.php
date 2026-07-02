<?php

declare(strict_types=1);

namespace Nene2\Install;

use CurlHandle;
use RuntimeException;

/**
 * The default {@see HttpTransport}: a cURL client locked down for fetching releases.
 *
 * Every request — and every redirect it follows — is confined to https, TLS is verified,
 * and both a connect and total timeout are enforced. `getString()` caps the in-memory body
 * so a hostile server cannot exhaust memory; `download()` streams to disk and aborts the
 * moment the transfer would exceed the caller's ceiling. Errors are reported without
 * leaking internal details beyond the failing URL.
 *
 * Network I/O by nature, so it is exercised through {@see HttpReleaseSource} with a fake
 * transport rather than in unit tests. Part of the opt-in installer toolkit.
 */
final readonly class CurlHttpTransport implements HttpTransport
{
    private const MANIFEST_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct()
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required to download releases.');
        }
    }

    public function getString(string $url, int $timeoutSeconds): string
    {
        $handle = $this->handle($url, $timeoutSeconds);

        $body = '';
        $limit = self::MANIFEST_MAX_BYTES;

        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function (CurlHandle $handle, string $chunk) use (&$body, $limit): int {
            $body .= $chunk;

            // Returning fewer bytes than received tells cURL to abort the transfer.
            return strlen($body) > $limit ? 0 : strlen($chunk);
        });

        $ok = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($ok === false) {
            throw new RuntimeException($this->failure('fetch the release manifest', $url, $error));
        }

        return $body;
    }

    public function download(string $url, string $destinationPath, int $timeoutSeconds, int $maxBytes): void
    {
        $file = @fopen($destinationPath, 'wb');

        if ($file === false) {
            throw new RuntimeException('Could not open the download destination for writing.');
        }

        $handle = $this->handle($url, $timeoutSeconds);
        curl_setopt($handle, CURLOPT_FILE, $file);
        curl_setopt($handle, CURLOPT_NOPROGRESS, false);
        curl_setopt($handle, CURLOPT_XFERINFOFUNCTION, static function (CurlHandle $handle, int $dlTotal, int $dlNow) use ($maxBytes): int {
            // Non-zero aborts: refuse as soon as either the advertised or received size overruns.
            return ($dlTotal > $maxBytes || $dlNow > $maxBytes) ? 1 : 0;
        });

        $ok = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($file);

        if ($ok === false) {
            @unlink($destinationPath);

            throw new RuntimeException($this->failure('download the release', $url, $error));
        }

        clearstatcache(true, $destinationPath);
        $size = filesize($destinationPath);

        if ($size === false || $size > $maxBytes) {
            @unlink($destinationPath);

            throw new RuntimeException('The release download exceeded the allowed size.');
        }
    }

    private function handle(string $url, int $timeoutSeconds): CurlHandle
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new RuntimeException('Could not initialise the HTTP client.');
        }

        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($handle, CURLOPT_MAXREDIRS, 5);

        // Confine the request AND every redirect to https. Use the legacy bitmask, not the
        // CURLOPT_*_STR variants: those need libcurl 7.85+, and on the older libcurl still
        // common on shared hosting curl_setopt() would merely return false and silently leave
        // the protocol restriction off. So we also check the return value and fail closed.
        $restricted = curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS)
            && curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);

        if (!$restricted) {
            curl_close($handle);

            throw new RuntimeException('Could not restrict the HTTP client to https; refusing to fetch a release.');
        }

        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, max(1, $timeoutSeconds));
        curl_setopt($handle, CURLOPT_TIMEOUT, max(1, $timeoutSeconds));
        curl_setopt($handle, CURLOPT_FAILONERROR, true);

        return $handle;
    }

    private function failure(string $action, string $url, string $error): string
    {
        $suffix = $error === '' ? '' : ' (' . $error . ')';

        return sprintf('Failed to %s from %s%s', $action, $url, $suffix);
    }
}
