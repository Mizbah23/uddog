<?php

namespace App;

enum UserRole: string
{
    case Superadmin = 'superadmin';
    case Admin = 'admin';
    case Manager = 'manager';
    case Staff = 'staff';
}
