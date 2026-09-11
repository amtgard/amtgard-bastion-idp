<?php

declare(strict_types=1);

namespace Amtgard\IdP\Utility\Security;

enum ConfidentialClientAuthMode
{
    case CredentialsOnly;
    case RequireIamService;
}
