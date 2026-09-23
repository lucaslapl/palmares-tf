<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useIsolatedDataDir();
        config()->set('admin.access_token', 'supersecrettoken');
        config()->set('admin.username', 'admin');
        config()->set('admin.password_hash', Hash::make('password123'));
    }

    #[Test]
    public function the_dashboard_is_hidden_without_the_magic_link(): void
    {
        $this->get('/admin')->assertNotFound();
        $this->get('/admin/login')->assertNotFound();
        $this->get('/admin/logs')->assertNotFound();
    }

    #[Test]
    public function a_wrong_access_token_is_rejected(): void
    {
        $this->get('/admin/wrongtoken')->assertNotFound();
    }

    #[Test]
    public function the_magic_link_opens_the_login_page(): void
    {
        $this->get('/admin/supersecrettoken')->assertRedirect(route('admin.login'));
    }

    #[Test]
    public function the_dashboard_stays_locked_before_login(): void
    {
        $this->withSession(['admin_magic_ok' => true])
            ->get('/admin')
            ->assertNotFound();
    }

    #[Test]
    public function wrong_credentials_are_rejected(): void
    {
        $this->withSession(['admin_magic_ok' => true])
            ->post('/admin/login', ['username' => 'admin', 'password' => 'wrong'])
            ->assertSessionHasErrors('password');
    }

    #[Test]
    public function valid_credentials_authorize_the_dashboard(): void
    {
        $this->withSession(['admin_magic_ok' => true])
            ->post('/admin/login', ['username' => 'admin', 'password' => 'password123'])
            ->assertRedirect(route('admin.dashboard'));

        $this->get('/admin')->assertOk()->assertSee('Pipeline overview');
    }

    #[Test]
    public function logout_revokes_access(): void
    {
        $session = ['admin_magic_ok' => true, 'admin_authenticated' => true];

        $this->withSession($session)->get('/admin')->assertOk();
        $this->withSession($session)->post('/admin/logout')->assertRedirect(route('home'));
        $this->withSession(['admin_magic_ok' => true])->get('/admin')->assertNotFound();
    }

    #[Test]
    public function login_attempts_are_throttled(): void
    {
        $this->withSession(['admin_magic_ok' => true]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['username' => 'admin', 'password' => 'wrong'])
                ->assertStatus(302);
        }

        $this->post('/admin/login', ['username' => 'admin', 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
