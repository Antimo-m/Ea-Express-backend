<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountSecurityRevisionTest extends TestCase
{
    use RefreshDatabase;

    public static function roles(): array
    {
        return ['rider' => [UserRole::Rider], 'customer' => [UserRole::Customer], 'admin' => [UserRole::Admin]];
    }

    #[DataProvider('roles')]
    public function test_self_service_deletion_is_denied_and_account_is_preserved(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->deleteJson('/profile', ['password' => 'password'])->assertForbidden();
        $this->assertModelExists($user);
    }

    public function test_admin_disables_customer_without_removing_historical_records(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $customer = User::factory()->create(['role' => UserRole::Customer]);
        $order = Order::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($admin)->patch(route('users.update', $customer), ['action' => 'deactivate'])->assertRedirect();

        $this->assertFalse($customer->fresh()->is_active);
        $this->assertModelExists($order);
        $this->assertDatabaseHas('economic_audits', ['user_id' => $admin->id, 'action' => 'user.deactivate', 'entity_id' => $customer->id]);
    }

    public function test_rider_cannot_disable_another_user_or_choose_admin_role(): void
    {
        $rider = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($rider)->patchJson(route('users.update', $other), ['action' => 'deactivate', 'role' => 'admin'])->assertForbidden();
        $this->postJson(route('users.store'), ['role' => 'admin'])->assertForbidden();
        $this->assertTrue($other->fresh()->is_active);
        $this->assertSame(0, User::where('role', UserRole::Admin)->count());
    }

    public function test_first_admin_can_only_be_promoted_through_server_command_and_second_is_denied(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->artisan('app:user-role', ['email' => $first->email, 'role' => 'admin'])->assertSuccessful();
        $this->artisan('app:user-role', ['email' => $second->email, 'role' => 'admin'])->assertFailed();
        $this->assertSame(UserRole::Admin, $first->fresh()->role);
        $this->assertSame(UserRole::Rider, $second->fresh()->role);
        $this->assertDatabaseHas('economic_audits', ['action' => 'user.role_changed', 'entity_id' => $first->id]);
    }

    public function test_database_unique_constraint_rejects_promotion_that_bypasses_model_events(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $other = User::factory()->create();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('users')->where('id', $other->id)->update(['role' => 'admin']);
    }

    public function test_model_rejects_a_second_admin_with_validation_error(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $this->expectException(ValidationException::class);
        User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_admin_cannot_be_deleted_demoted_or_disabled(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->patchJson(route('users.update', $admin), ['action' => 'deactivate'])->assertForbidden();
        $this->artisan('app:user-role', ['email' => $admin->email, 'role' => 'rider'])->assertFailed();
        try {
            $admin->delete();
            $this->fail('Admin deletion was allowed');
        } catch (ValidationException) {
            $this->assertModelExists($admin);
        }
        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
    }

    public function test_transfer_verifies_identity_and_atomically_moves_role_with_audit(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        $this->artisan('app:transfer-admin', ['email' => $rider->email, '--operator' => $admin->email])
            ->expectsQuestion('Password dell’Admin operatore', 'wrong')->assertFailed();
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->artisan('app:transfer-admin', ['email' => $rider->email, '--operator' => $admin->email])
            ->expectsQuestion('Password dell’Admin operatore', 'password')
            ->expectsConfirmation('Trasferire l’amministrazione a '.$rider->email.' revocando le sessioni coinvolte?', 'yes')->assertSuccessful();
        $this->assertSame(UserRole::Rider, $admin->fresh()->role);
        $this->assertSame(UserRole::Admin, $rider->fresh()->role);
        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
        $this->assertDatabaseHas('economic_audits', ['user_id' => $admin->id, 'entity_id' => $rider->id, 'action' => 'admin.transferred']);
    }

    public static function missingTransferPrerequisites(): array
    {
        return ['trusted device table' => ['table'], 'credential version column' => ['column']];
    }

    #[DataProvider('missingTransferPrerequisites')]
    public function test_transfer_reports_missing_migrations_before_requesting_password_without_changing_accounts(string $missing): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $rider = User::factory()->create();
        if ($missing === 'table') {
            Schema::drop('trusted_rider_devices');
        } else {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('auth_version'));
        }

        $this->artisan('app:transfer-admin', ['email' => $rider->email, '--operator' => $admin->email, '--reconcile' => true])
            ->expectsOutputToContain('Prerequisiti mancanti')
            ->expectsOutputToContain('php artisan migrate --path=')
            ->assertFailed();

        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
        $this->assertSame(UserRole::Rider, $rider->fresh()->role);
        $this->assertDatabaseCount('economic_audits', 0);
    }

    public function test_existing_multiple_admins_stop_migration_and_require_authenticated_reconciliation(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['admin_slot']);
            $table->dropColumn('admin_slot');
        });
        $first = User::factory()->create(['role' => UserRole::Admin]);
        $second = User::factory()->create();
        DB::table('users')->where('id', $second->id)->update(['role' => 'admin']);
        $migration = require database_path('migrations/2026_11_10_123558_enforce_single_admin_on_users_table.php');
        try {
            $migration->up();
            $this->fail('Migration accepted multiple administrators.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Più Admin presenti', $exception->getMessage());
        }
        $this->assertSame(2, User::where('role', UserRole::Admin)->count());
        $this->artisan('app:transfer-admin', ['email' => $second->email, '--operator' => $first->email])
            ->expectsQuestion('Password dell’Admin operatore', 'password')->assertFailed();
        $this->artisan('app:transfer-admin', ['email' => $second->email, '--operator' => $first->email, '--reconcile' => true])
            ->expectsQuestion('Password dell’Admin operatore', 'password')
            ->expectsConfirmation('Trasferire l’amministrazione a '.$second->email.' revocando le sessioni coinvolte?', 'yes')->assertSuccessful();
        $migration->up();
        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
        $this->assertSame(UserRole::Admin, $second->fresh()->role);
    }
}
