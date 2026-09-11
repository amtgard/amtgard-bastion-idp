<?php

declare(strict_types=1);

namespace Amtgard\IdP\Controllers\Client;

enum AuthorizationFinalizeRedirect
{
    case NewUserProfile;
    case ReturningUserWithStoredRedirect;
}
