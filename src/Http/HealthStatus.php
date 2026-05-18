<?php

declare(strict_types=1);

namespace Nene2\Http;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
}
