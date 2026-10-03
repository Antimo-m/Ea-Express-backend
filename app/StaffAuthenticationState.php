<?php

namespace App;

enum StaffAuthenticationState: string
{
    case Guest = 'guest';
    case PrimaryAuthenticated = 'primary_authenticated';
    case FactorVerified = 'factor_verified';
    case FullyAuthenticated = 'fully_authenticated';
}
