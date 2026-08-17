<?php

namespace App\Shared\Enums;

enum UserRole: string
{
    case SuperAdmin = 'SUPER_ADMIN';
    case User = 'USER';
}
