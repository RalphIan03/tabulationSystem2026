<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\ActivityLog;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CredentialSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_requires_existing_auditors_to_change_credentials(): void
    {
        $auditor = User::factory()->create(['role' => AccountRole::Auditor]);
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $migration = require database_path('migrations/2026_10_18_065605_add_must_change_credentials_to_users_table.php');

        $migration->down();
        $migration->up();

        $this->assertTrue($auditor->refresh()->must_change_credentials);
        $this->assertFalse($admin->refresh()->must_change_credentials);
    }

    public function test_admin_provisioned_account_must_change_both_credentials_before_using_system(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'New Auditor',
            'username' => 'assigned.auditor',
            'role' => AccountRole::Auditor->value,
            'password' => 'admin-secret-password',
            'password_confirmation' => 'admin-secret-password',
            'is_active' => true,
            'must_change_credentials' => false,
        ])->assertRedirect(route('users.index'));

        $account = User::query()->where('username', 'assigned.auditor')->sole();
        $event = Event::factory()->create();
        $this->assertTrue($account->must_change_credentials);
        $this->get(route('users.index'))->assertSee('Credential change required');

        auth()->logout();

        $this->post(route('login.store'), [
            'username' => 'assigned.auditor',
            'password' => 'admin-secret-password',
        ])->assertRedirect(route('credentials.edit'));

        $this->assertAuthenticatedAs($account);
        $this->get(route('credentials.edit'))
            ->assertSee('Set your own credentials')
            ->assertSee('action="/change-credentials"', false)
            ->assertSee('assigned.auditor')
            ->assertDontSee('admin-secret-password');
        $this->get(route('dashboard'))->assertRedirect(route('credentials.edit'));
        $this->get(route('events.index'))->assertRedirect(route('credentials.edit'));
        $this->post(route('events.participants.store', $event), ['participant_name' => 'Blocked'])
            ->assertForbidden();
        $this->assertSame(0, $event->participants()->count());
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_credential_setup_requires_a_different_unique_username_and_new_password(): void
    {
        $account = User::factory()->create([
            'username' => 'assigned.user',
            'password' => 'old-secret-password',
            'must_change_credentials' => true,
        ]);
        User::factory()->create(['username' => 'taken.user']);
        $oldHash = $account->password;

        $this->actingAs($account)->patch(route('credentials.update'), [
            'username' => 'assigned.user',
            'current_password' => 'old-secret-password',
            'password' => 'old-secret-password',
            'password_confirmation' => 'old-secret-password',
        ])->assertSessionHasErrors([
            'username' => 'Choose a username different from the one assigned to you.',
            'password' => 'Choose a password different from your current password.',
        ]);

        $this->actingAs($account)->patch(route('credentials.update'), [
            'username' => 'taken.user',
            'current_password' => 'wrong-password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertSessionHasErrors(['username', 'current_password']);

        $this->actingAs($account)->patch(route('credentials.update'), [
            'username' => 'fresh.user',
            'current_password' => 'old-secret-password',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors('password');

        $account->refresh();
        $this->assertSame('assigned.user', $account->username);
        $this->assertSame($oldHash, $account->password);
        $this->assertTrue($account->must_change_credentials);
    }

    public function test_completing_setup_unlocks_account_and_does_not_log_passwords(): void
    {
        $account = User::factory()->create([
            'username' => 'assigned.user',
            'password' => 'old-secret-password',
            'must_change_credentials' => true,
        ]);
        $rememberToken = $account->getRememberToken();

        $this->actingAs($account)->patch(route('credentials.update'), [
            'username' => 'personal.user',
            'current_password' => 'old-secret-password',
            'password' => 'personal-secret-password',
            'password_confirmation' => 'personal-secret-password',
            'role' => AccountRole::Admin->value,
            'must_change_credentials' => true,
        ])->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Your username and password have been updated.');

        $account->refresh();
        $this->assertSame('personal.user', $account->username);
        $this->assertTrue(Hash::check('personal-secret-password', $account->password));
        $this->assertFalse($account->must_change_credentials);
        $this->assertNotSame($rememberToken, $account->getRememberToken());
        $this->assertSame(AccountRole::Auditor, $account->role);
        $this->actingAs($account)->get(route('dashboard'))->assertOk();

        $log = ActivityLog::query()->where('action', 'credentials.update')->sole();
        $this->assertSame('Changed initial username and password', $log->description);
        $this->assertStringNotContainsString('old-secret-password', $log->toJson());
        $this->assertStringNotContainsString('personal-secret-password', $log->toJson());

        auth()->logout();
        $this->post(route('login.store'), [
            'username' => 'assigned.user',
            'password' => 'old-secret-password',
        ])->assertSessionHasErrors('username');
        $this->post(route('login.store'), [
            'username' => 'personal.user',
            'password' => 'personal-secret-password',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_admin_changes_to_username_or_password_require_setup_again_but_name_only_does_not(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);
        $account = User::factory()->create(['username' => 'original.user']);

        $this->actingAs($admin)->patch(route('users.update', $account), [
            'name' => 'Renamed User',
            'username' => 'original.user',
            'role' => AccountRole::Auditor->value,
            'password' => '',
        ])->assertRedirect(route('users.index'));
        $this->assertFalse($account->refresh()->must_change_credentials);

        $this->actingAs($admin)->patch(route('users.update', $account), [
            'name' => 'Renamed User',
            'username' => 'admin.changed',
            'role' => AccountRole::Auditor->value,
            'password' => '',
        ])->assertRedirect(route('users.index'));
        $this->assertTrue($account->refresh()->must_change_credentials);

        $account->must_change_credentials = false;
        $account->save();

        $this->actingAs($admin)->patch(route('users.update', $account), [
            'name' => 'Renamed User',
            'username' => 'admin.changed',
            'role' => AccountRole::Auditor->value,
            'password' => 'reset-secret-password',
            'password_confirmation' => 'reset-secret-password',
        ])->assertRedirect(route('users.index'));
        $this->assertTrue($account->refresh()->must_change_credentials);
    }

    public function test_admin_provisioned_admin_must_also_complete_setup_and_completed_accounts_cannot_repeat_it(): void
    {
        $admin = User::factory()->create(['role' => AccountRole::Admin]);

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'New Admin',
            'username' => 'new.admin',
            'role' => AccountRole::Admin->value,
            'password' => 'admin-secret-password',
            'password_confirmation' => 'admin-secret-password',
            'is_active' => true,
        ])->assertRedirect(route('users.index'));

        $account = User::query()->where('username', 'new.admin')->sole();
        $this->assertTrue($account->must_change_credentials);

        $this->actingAs($account)->get(route('dashboard'))->assertRedirect(route('credentials.edit'));
        $this->actingAs($admin)->get(route('credentials.edit'))->assertRedirect(route('dashboard'));
        $this->actingAs($admin)->patch(route('credentials.update'), [
            'username' => 'different.admin',
            'current_password' => 'password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertForbidden();
    }

    public function test_guests_and_deactivated_accounts_cannot_use_setup(): void
    {
        $this->get(route('credentials.edit'))->assertRedirect(route('login'));

        $account = User::factory()->create([
            'must_change_credentials' => true,
            'is_active' => false,
        ]);

        $this->actingAs($account)->get(route('credentials.edit'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
