<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_csrf_endpoint_returns_the_session_token_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/csrf');

        $response->assertOk()->assertJsonStructure(['token']);
        $this->assertIsString($response->json('token'));
        $this->assertNotSame('', $response->json('token'));
        $response->assertSessionHas('_token', $response->json('token'));
        $this->assertGuest('web');
    }

    public function test_registration_creates_a_user_with_a_hashed_password_and_authenticates_the_session(): void
    {
        $response = $this->postJson('/api/v1/register', $this->registration());

        $response->assertCreated();
        $user = User::query()->where('email', 'ana@example.com')->sole();
        $this->assertSame('Ana Silva', $user->name);
        $this->assertTrue(Hash::check('correct-horse-battery', $user->password));
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('users', 1);
        $response->assertJsonMissingPath('data.password')->assertJsonMissingPath('password')
            ->assertJsonMissingPath('data.remember_token')->assertJsonMissingPath('remember_token');
        $this->getJson('/api/v1/user')->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Ana Silva')
            ->assertJsonPath('data.email', 'ana@example.com')
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function invalidRegistrations(): array
    {
        return [
            'required fields' => [['name' => '', 'email' => '', 'password' => '', 'password_confirmation' => ''], ['name', 'email', 'password']],
            'invalid email' => [['email' => 'not-an-email'], ['email']],
            'password confirmation' => [['password_confirmation' => 'different-password'], ['password']],
            'short password' => [['password' => 'short', 'password_confirmation' => 'short'], ['password']],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $errors
     */
    #[DataProvider('invalidRegistrations')]
    public function test_invalid_registration_returns_422_without_creating_or_authenticating_a_user(array $changes, array $errors): void
    {
        $response = $this->postJson('/api/v1/register', $this->registration($changes));

        $response->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_duplicate_email_returns_422_without_changing_the_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com', 'name' => 'Original']);
        $password = $user->password;

        $response = $this->postJson('/api/v1/register', $this->registration());

        $response->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Original', $user->fresh()->name);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertGuest('web');
    }

    public function test_login_authenticates_the_web_session_and_returns_only_the_current_user(): void
    {
        $user = User::factory()->create(['email' => 'ana@example.com']);
        User::factory()->create(['email' => 'other@example.com']);
        $this->withSession(['_token' => 'existing-session-token']);
        $oldSessionId = session()->getId();

        $response = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertSuccessful();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($oldSessionId, session()->getId());
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/user')->assertOk()
            ->assertJsonPath('data.id', $user->id)->assertJsonPath('data.email', $user->email)
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissing(['email' => 'other@example.com']);
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function invalidLogins(): array
    {
        return [
            'missing credentials' => [[], ['email', 'password']],
            'malformed email' => [['email' => 'invalid', 'password' => 'password'], ['email']],
            'wrong password' => [['email' => 'ana@example.com', 'password' => 'wrong-password'], ['email']],
            'unknown account' => [['email' => 'missing@example.com', 'password' => 'password'], ['email']],
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  list<string>  $errors
     */
    #[DataProvider('invalidLogins')]
    public function test_invalid_login_returns_422_and_keeps_the_session_unauthenticated(array $credentials, array $errors): void
    {
        User::factory()->create(['email' => 'ana@example.com']);

        $response = $this->postJson('/api/v1/login', $credentials);

        $response->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->assertGuest('web');
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_logout_invalidates_the_session_and_protects_subsequent_requests(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertSuccessful();
        $this->withSession(['private_marker' => 'must disappear']);
        $oldToken = session()->token();

        $response = $this->postJson('/api/v1/logout');

        $response->assertSuccessful()->assertSessionMissing('private_marker');
        $this->assertGuest('web');
        $this->assertNotSame($oldToken, session()->token());
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/user')->assertUnauthorized();
        $this->getJson('/api/v1/occurrences')->assertUnauthorized();
    }

    public function test_login_returns_429_after_five_attempts_for_the_same_case_insensitive_email_and_ip(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-15 12:00:00'));
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.41']);
        $credentials = ['email' => 'throttled-account@example.com', 'password' => 'incorrect-password'];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/login', $credentials)->assertUnprocessable()->assertJsonValidationErrors('email');
        }

        $this->postJson('/api/v1/login', [...$credentials, 'email' => 'THROTTLED-ACCOUNT@example.com'])
            ->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/v1/login', [...$credentials, 'email' => 'another-account@example.com'])->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/login', $credentials)->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_returns_429_after_thirty_attempts_from_one_ip_even_with_different_emails(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-15 12:00:00'));
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.42']);
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->postJson('/api/v1/login', ['email' => 'ip-throttle-'.$attempt.'@example.com', 'password' => 'incorrect-password'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }

        $this->postJson('/api/v1/login', ['email' => 'ip-throttle-next@example.com', 'password' => 'incorrect-password'])
            ->assertTooManyRequests()->assertHeader('Retry-After');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function invalidCsrfHeaders(): array
    {
        return ['missing token' => [[]], 'mismatched token' => [['X-CSRF-TOKEN' => 'invalid-token']]];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('invalidCsrfHeaders')]
    public function test_registration_returns_419_without_a_matching_session_csrf_token(array $headers): void
    {
        // Exercise the real forgery checks without changing the testing environment or database.
        $this->app->instance(PreventRequestForgery::class, new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $token = $this->getJson('/api/v1/csrf')->assertOk()->json('token');

        $this->postJson('/api/v1/register', $this->registration(['email' => 'csrf-check@example.com']), $headers)->assertStatus(419);

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->postJson('/api/v1/register', [], ['X-CSRF-TOKEN' => $token])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_current_user_returns_401_for_a_guest(): void
    {
        $this->getJson('/api/v1/user')->assertUnauthorized();
    }

    public function test_logout_returns_401_for_a_guest(): void
    {
        $this->postJson('/api/v1/logout')->assertUnauthorized();
    }

    /** @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function registration(array $changes = []): array
    {
        return array_replace([
            'name' => 'Ana Silva', 'email' => 'ana@example.com',
            'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery',
        ], $changes);
    }
}
