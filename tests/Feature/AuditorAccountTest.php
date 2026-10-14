<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccountRole;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuditorAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditor_can_open_self_service_account_page(): void
    {
        $auditor = User::factory()->create([
            'name' => 'Audit Officer',
            'username' => 'audit.officer',
            'password' => 'stored-secret',
        ]);

        $this->actingAs($auditor)
            ->get(route('auditor-account.edit'))
            ->assertOk()
            ->assertSee('My account')
            ->assertSee('value="Audit Officer"', false)
            ->assertSee('value="audit.officer"', false)
            ->assertSee('action="/my-account/details"', false)
            ->assertSee('action="/my-account/password"', false)
            ->assertSee('aria-controls="current_password"', false)
            ->assertDontSee('stored-secret');

        $this->actingAs($auditor)->get(route('dashboard'))
            ->assertSee('My account')
            ->assertSee('images/um_logo.png');
    }

    public function test_guest_and_admin_cannot_access_auditor_account_routes(): void
    {
        $this->get(route('auditor-account.edit'))->assertRedirect(route('login'));

        $admin = User::factory()->create(['role' => AccountRole::Admin]);

        $this->actingAs($admin)->get(route('auditor-account.edit'))->assertForbidden();
        $this->actingAs($admin)->patch(route('auditor-account.details.update'), [
            'name' => 'Changed',
            'username' => 'changed',
        ])->assertForbidden();
        $this->actingAs($admin)->patch(route('auditor-account.password.update'), [
            'current_password' => 'password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertForbidden();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertDontSee('title="My account"', false);
    }

    public function test_auditor_can_update_own_name_and_username_without_changing_role_or_status(): void
    {
        $auditor = User::factory()->create(['name' => 'Before', 'username' => 'before']);
        $other = User::factory()->create(['name' => 'Other account']);

        $this->actingAs($auditor)->patch(route('auditor-account.details.update'), [
            'name' => 'Updated Officer',
            'username' => 'updated.officer',
            'role' => AccountRole::Admin->value,
            'is_active' => false,
            'user_id' => $other->id,
        ])->assertRedirect(route('auditor-account.edit'))
            ->assertSessionHas('status', 'Your account details were updated successfully.');

        $auditor->refresh();
        $this->assertSame('Updated Officer', $auditor->name);
        $this->assertSame('updated.officer', $auditor->username);
        $this->assertSame(AccountRole::Auditor, $auditor->role);
        $this->assertTrue($auditor->is_active);
        $this->assertSame('Other account', $other->refresh()->name);

        $log = ActivityLog::query()->where('action', 'auditor-account.details.update')->sole();
        $this->assertSame('Updated own account details', $log->description);
        $this->assertSame('User', $log->affectedRecords()[0]['type']);
        $this->assertSame((string) $auditor->id, $log->affectedRecords()[0]['id']);
    }

    public function test_auditor_cannot_take_another_users_username(): void
    {
        $auditor = User::factory()->create(['username' => 'original']);
        User::factory()->create(['username' => 'already.taken']);

        $this->actingAs($auditor)->patch(route('auditor-account.details.update'), [
            'name' => 'Changed name',
            'username' => 'already.taken',
        ])->assertSessionHasErrors('username');

        $this->assertSame('original', $auditor->refresh()->username);
    }

    public function test_auditor_account_details_require_a_name_and_username(): void
    {
        $auditor = User::factory()->create(['name' => 'Original', 'username' => 'original']);

        $this->actingAs($auditor)->patch(route('auditor-account.details.update'), [
            'name' => '',
            'username' => '',
        ])->assertSessionHasErrors(['name', 'username']);

        $this->assertSame('Original', $auditor->refresh()->name);
        $this->assertSame('original', $auditor->username);
    }

    public function test_auditor_must_provide_current_password_and_confirmation_to_change_it(): void
    {
        $auditor = User::factory()->create(['password' => 'old-secret-password']);
        $originalHash = $auditor->password;

        $this->actingAs($auditor)->patch(route('auditor-account.password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($auditor)->patch(route('auditor-account.password.update'), [
            'current_password' => 'old-secret-password',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->actingAs($auditor)->patch(route('auditor-account.password.update'), [
            'current_password' => 'old-secret-password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'different-password',
        ])->assertSessionHasErrors('password');

        $this->assertSame($originalHash, $auditor->refresh()->password);
    }

    public function test_auditor_can_change_password_without_exposing_it_in_activity_logs(): void
    {
        $auditor = User::factory()->create(['password' => 'old-secret-password']);
        $rememberToken = $auditor->getRememberToken();

        $this->actingAs($auditor)->patch(route('auditor-account.password.update'), [
            'current_password' => 'old-secret-password',
            'password' => 'new-secret-password',
            'password_confirmation' => 'new-secret-password',
            'role' => AccountRole::Admin->value,
        ])->assertRedirect(route('auditor-account.edit'))
            ->assertSessionHas('status', 'Your password was updated successfully.');

        $auditor->refresh();
        $this->assertTrue(Hash::check('new-secret-password', $auditor->password));
        $this->assertFalse(Hash::check('old-secret-password', $auditor->password));
        $this->assertNotSame($rememberToken, $auditor->getRememberToken());
        $this->assertSame(AccountRole::Auditor, $auditor->role);

        $log = ActivityLog::query()->where('action', 'auditor-account.password.update')->sole();
        $this->assertSame('Changed own password', $log->description);
        $this->assertStringNotContainsString('new-secret-password', $log->toJson());
        $this->assertStringNotContainsString('old-secret-password', $log->toJson());
    }

    public function test_login_branding_uses_um_logo_on_desktop_and_mobile(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('images/um_logo.png')
            ->assertDontSee('images/osa_logo.png');
    }
}
