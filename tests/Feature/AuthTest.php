<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

class AuthTest extends FeatureTestCase
{
    #[Test]
    public function register_creates_a_user_and_returns_a_usable_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.email', 'alice@example.com')
            ->assertJsonMissingPath('user.password')
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

        $this->assertDatabaseHas('users', ['email' => 'alice@example.com']);
    }

    #[Test]
    public function login_with_correct_credentials_returns_a_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonStructure(['user', 'token']);
    }

    #[Test]
    public function login_with_a_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function authenticated_user_endpoint_returns_the_callers_own_resource(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email)
            ->assertJsonMissingPath('password');
    }

    #[Test]
    public function repeated_login_attempts_are_rate_limited(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

            $response->assertStatus(Response::HTTP_UNAUTHORIZED);
        }

        $sixth = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $sixth->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
    }

    #[Test]
    public function logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);
        $token = $login->json('token');

        $logout = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/logout');
        $logout->assertNoContent();

        auth()->forgetGuards();

        $reused = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/user');
        $reused->assertStatus(Response::HTTP_UNAUTHORIZED);
    }
}
