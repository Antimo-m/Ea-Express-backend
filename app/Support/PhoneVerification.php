<?php

namespace App\Support;

class PhoneVerification
{
    public static function enabled(): bool
    {
        return ! app()->environment(['local', 'testing']) || (bool) config('sms.verification_enabled', true);
    }
}
