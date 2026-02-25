<?php

namespace Amtgard\IdP\Utility;

enum UserRole: string
{
    case Admin = 'admin';
    case Approver = 'approver';
    case User = 'user';
}