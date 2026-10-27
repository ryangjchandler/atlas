<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\McpServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_registered_user_becomes_admin_and_later_ones_are_users(): void
    {
        foreach (['first@example.com', 'second@example.com'] as $email) {
            $this->post(route('register.store'), [
                'name' => 'Someone',
                'email' => $email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);
            auth()->logout();
        }

        $this->assertSame(Role::Admin, User::where('email', 'first@example.com')->sole()->role);
        $this->assertSame(Role::User, User::where('email', 'second@example.com')->sole()->role);
    }

    public function test_users_can_view_sources_but_not_configure_them(): void
    {
        $user = User::factory()->create();
        $server = McpServer::create(['name' => 'Docs', 'url' => 'https://docs.example.com/mcp']);

        $this->actingAs($user)->get(route('sources'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('mcpServers', 1)->where('accounts', []));

        $this->actingAs($user)->post(route('mcp-servers.store'), ['name' => 'X', 'url' => 'https://x.example.com', 'auth' => 'none'])->assertForbidden();
        $this->actingAs($user)->delete(route('mcp-servers.destroy', $server))->assertForbidden();
        $this->actingAs($user)->post(route('github.sync'))->assertForbidden();
        $this->actingAs($user)->get(route('users.index'))->assertForbidden();

        $this->assertSame(1, McpServer::count());
    }

    public function test_admins_manage_roles_but_cannot_change_their_own(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin)->patch(route('users.update', $user), ['role' => 'admin'])->assertRedirect();
        $this->assertSame(Role::Admin, $user->fresh()->role);

        $this->actingAs($admin)->patch(route('users.update', $admin), ['role' => 'user'])->assertForbidden();
        $this->assertSame(Role::Admin, $admin->fresh()->role);

        $this->actingAs($user->fresh())->patch(route('users.update', $admin), ['role' => 'nonsense'])->assertSessionHasErrors('role');
    }
}
