<?php

namespace App\Enums;

enum PermissionStatus: string
{
    case Pending = 'pending';
    case Allowed = 'allowed';
    case Denied = 'denied';
}
