<?php

declare(strict_types=1);

namespace Nene2\Log;

final class RequestIdHolder
{
    private string $requestId = '';

    public function set(string $requestId): void
    {
        $this->requestId = $requestId;
    }

    public function get(): string
    {
        return $this->requestId;
    }
}
