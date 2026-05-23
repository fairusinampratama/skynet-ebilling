<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapInitialAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('INITIAL_ADMIN_NAME');
        putenv('INITIAL_ADMIN_EMAIL');
        putenv('INITIAL_ADMIN_PASSWORD');

        parent::tearDown();
    }

    public function test_it_creates_initial_superadmin_from_environment(): void
    {
        putenv('INITIAL_ADMIN_NAME=Launch Admin');
        putenv('INITIAL_ADMIN_EMAIL=launch@example.com');
        putenv('INITIAL_ADMIN_PASSWORD=StrongerPassword123!');

        $this->artisan('users:bootstrap-initial-admin')
            ->expectsOutput('Initial superadmin created for launch@example.com.')
            ->assertSuccessful();

        $user = User::where('email', 'launch@example.com')->firstOrFail();

        $this->assertSame('Launch Admin', $user->name);
        $this->assertSame('superadmin', $user->role);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('StrongerPassword123!', $user->password));
    }

    public function test_it_skips_when_a_superadmin_already_exists(): void
    {
        User::factory()->create(['role' => 'superadmin']);

        $this->artisan('users:bootstrap-initial-admin')
            ->expectsOutput('A superadmin already exists. Skipping initial admin bootstrap.')
            ->assertSuccessful();

        $this->assertSame(1, User::where('role', 'superadmin')->count());
    }

    public function test_it_fails_without_environment_when_no_superadmin_exists(): void
    {
        $this->artisan('users:bootstrap-initial-admin')
            ->expectsOutput('Initial admin bootstrap failed. Set valid INITIAL_ADMIN_NAME, INITIAL_ADMIN_EMAIL, and INITIAL_ADMIN_PASSWORD values.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
