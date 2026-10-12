<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Log;

enum LogChannel: string
{
    case App = 'app';
    case Security = 'security';
    case Oauth = 'oauth';
    case Pvh = 'pvh';
    case Audit = 'audit';
}
