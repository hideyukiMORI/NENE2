<?php

declare(strict_types=1);

namespace Nene2\Log;

use Monolog\LogRecord;

final readonly class RequestIdProcessor
{
    public function __construct(
        private RequestIdHolder $holder,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $requestId = $this->holder->get();

        if ($requestId === '') {
            return $record;
        }

        return $record->with(extra: array_merge($record->extra, ['request_id' => $requestId]));
    }
}
