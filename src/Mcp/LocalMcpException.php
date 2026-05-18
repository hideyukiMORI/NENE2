<?php

declare(strict_types=1);

namespace Nene2\Mcp;

use RuntimeException;

/**
 * Thrown by {@see LocalMcpServer} when a tool call or JSON-RPC request cannot be processed.
 *
 * Part of the public API stability guarantee (see ADR 0009).
 */
final class LocalMcpException extends RuntimeException
{
}
