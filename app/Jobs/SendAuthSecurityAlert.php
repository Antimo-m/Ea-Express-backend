<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\AuthenticationAlert;
use App\UserRole;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendAuthSecurityAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $reason, public string $requestId) {}

    public function handle(): void
    {
        User::where('role', UserRole::Admin)->where('is_active', true)->eachById(function (User $admin): void {
            $admin->notify(new AuthenticationAlert($this->reason, $this->requestId));
        });
    }
}
