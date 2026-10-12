<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

/** Retention class for hourly SQLite log files under LOG_ROOT. */
enum LogStream: string
{
    case Trace = 'trace';
    case Error = 'error';
}
