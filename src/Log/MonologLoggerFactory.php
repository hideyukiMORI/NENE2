<?php

declare(strict_types=1);

namespace Nene2\Log;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final readonly class MonologLoggerFactory
{
    public function create(string $channel, bool $debug = false, ?RequestIdHolder $requestIdHolder = null): LoggerInterface
    {
        $level = $debug ? Level::Debug : Level::Warning;
        $handler = new StreamHandler('php://stderr', $level);
        $handler->setFormatter(new JsonFormatter());

        $processors = $requestIdHolder !== null ? [new RequestIdProcessor($requestIdHolder)] : [];

        return new Logger($channel, [$handler], $processors);
    }
}
