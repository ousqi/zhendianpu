<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'users',
        'workspaces',
        'workspace_members',
        'mcp_servers',
        'mcp_server_access',
    ];

    public function test_custom_login_authenticates_with_username(): void
    {
        $user = User::factory()->create(['username' => 'shop-owner', 'password' => 'correct-password']);

        $this->post(route('login.store'), ['username' => 'shop-owner', 'password' => 'correct-password'])
            ->assertRedirect(Filament::getDefaultPanel()->getUrl());

        $this->assertAuthenticatedAs($user);
    }

    public function test_user_resource_requires_a_unique_username(): void
    {
        $manager = User::factory()->create();
        User::factory()->create(['username' => 'existing-user']);

        Livewire::actingAs($manager)
            ->test(CreateUser::class)
            ->fillForm([
                'username' => 'existing-user',
                'name' => '新用户',
                'email' => 'new@example.com',
                'password' => 'new-password-123',
            ])
            ->call('create')
            ->assertHasFormErrors(['username' => 'unique']);
    }

    public function test_user_resource_provisions_panel_access_for_new_users(): void
    {
        $manager = User::factory()->create();

        Livewire::actingAs($manager)
            ->test(CreateUser::class)
            ->fillForm([
                'username' => 'new-manager',
                'name' => '新用户',
                'email' => 'new-manager@example.com',
                'password' => 'new-password-123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('username', 'new-manager')->sole();

        $this->assertTrue($user->canAccessPanel(Filament::getDefaultPanel()));
        $this->assertDatabaseHas('workspace_members', ['user_id' => $user->id, 'role' => 'manager']);
        $this->assertDatabaseCount('mcp_server_access', count(config('mcp_servers')));
    }

    public function test_user_resource_accepts_seven_character_numeric_passwords_and_rejects_shorter_passwords(): void
    {
        $manager = User::factory()->create();

        Livewire::actingAs($manager)
            ->test(CreateUser::class)
            ->fillForm([
                'username' => 'numeric-password-user',
                'name' => '数字密码用户',
                'email' => 'numeric-password@example.com',
                'password' => '123456',
            ])
            ->call('create')
            ->assertHasFormErrors(['password'])
            ->fillForm(['password' => '1234567'])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_user_resource_rejects_passwords_longer_than_the_login_limit(): void
    {
        $manager = User::factory()->create();

        Livewire::actingAs($manager)
            ->test(CreateUser::class)
            ->fillForm([
                'username' => 'long-password-user',
                'name' => '新用户',
                'email' => 'long-password@example.com',
                'password' => str_repeat('a', 256),
            ])
            ->call('create')
            ->assertHasFormErrors(['password' => 'max']);
    }

    public function test_user_resource_keeps_password_when_edit_password_is_blank(): void
    {
        $manager = User::factory()->create();
        $user = User::factory()->create(['password' => 'existing-password']);
        $passwordHash = $user->password;

        Livewire::actingAs($manager)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm([
                'username' => $user->username,
                'name' => '更新后的姓名',
                'email' => $user->email,
                'password' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('更新后的姓名', $user->name);
        $this->assertSame($passwordHash, $user->password);
        $this->assertTrue(Hash::check('existing-password', $user->password));
    }

    public function test_user_policy_forbids_deleting_account_id_one_but_allows_its_update(): void
    {
        $protectedUser = User::factory()->create();
        $manager = User::factory()->create();

        $this->assertSame(1, $protectedUser->id);
        $this->assertFalse(Gate::forUser($manager)->allows('delete', $protectedUser));
        $this->assertTrue(Gate::forUser($manager)->allows('update', $protectedUser));
    }
}
