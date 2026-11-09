<?php

namespace Tests\Feature;

use App\Support\AuthSecurityStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthSecurityPruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_pruning_removes_only_expired_authentication_keys_and_locks_in_bounded_batches(): void
    {
        $this->freezeTime();
        config(['auth_security.store' => 'database']);
        $store = app(AuthSecurityStore::class)->cache()->getStore();
        $prefix = $store->getPrefix();
        foreach (['auth:expired', 'other:expired', 'auth:active'] as $key) {
            DB::table('cache')->insert(['key' => $prefix.$key, 'value' => 'i:1;', 'expiration' => now()->timestamp + ($key === 'auth:active' ? 60 : -1)]);
            DB::table('cache_locks')->insert(['key' => $prefix.$key, 'owner' => 'test-owner', 'expiration' => now()->timestamp + ($key === 'auth:active' ? 60 : -1)]);
        }

        $this->artisan('auth-security:prune', ['--batch' => 1, '--batches' => 2])->assertSuccessful();

        foreach (['cache', 'cache_locks'] as $table) {
            $this->assertDatabaseMissing($table, ['key' => $prefix.'auth:expired']);
            $this->assertDatabaseHas($table, ['key' => $prefix.'other:expired']);
            $this->assertDatabaseHas($table, ['key' => $prefix.'auth:active']);
        }
    }

    public function test_pruning_does_not_delete_a_counter_renewed_after_selection(): void
    {
        $this->freezeTime();
        config(['auth_security.store' => 'database']);
        $key = app(AuthSecurityStore::class)->cache()->getStore()->getPrefix().'auth:renewed';
        DB::table('cache')->insert(['key' => $key, 'value' => 'i:1;', 'expiration' => now()->subSecond()->timestamp]);
        $renewed = false;
        DB::listen(function ($query) use ($key, &$renewed): void {
            if (! $renewed && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"cache"')) {
                $renewed = true;
                DB::table('cache')->where('key', $key)->update(['expiration' => now()->addMinute()->timestamp]);
            }
        });

        $this->artisan('auth-security:prune')->assertSuccessful();

        $this->assertTrue($renewed);
        $this->assertDatabaseHas('cache', ['key' => $key, 'expiration' => now()->addMinute()->timestamp]);
    }
}
