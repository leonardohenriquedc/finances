<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class PresentationPageTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_root_serves_the_public_presentation_instead_of_the_financial_application(): void
    {
        $this->get(route('home'))->assertOk()->assertViewIs('welcome')->assertViewHas('page', 'presentation')
            ->assertSee('Provisionamentos e organização financeira')
            ->assertSee('data-page="presentation"', false)
            ->assertSee('data-app-url="'.route('application').'"', false)
            ->assertSee('data-register-url="'.route('application', ['auth' => 'register']).'"', false)
            ->assertDontSee('data-page="application"', false);
        $this->assertGuest();
    }

    public function test_root_remains_the_presentation_for_authenticated_visitors_and_query_filters(): void
    {
        $user = User::factory()->create(['name' => 'Nome privado do usuário']);
        $this->actingAs($user);

        $this->get('/?view=transactions&year=2026')->assertOk()->assertViewHas('page', 'presentation')
            ->assertDontSee('Nome privado do usuário');
        $this->assertAuthenticatedAs($user);
    }

    public function test_application_route_loads_the_existing_login_shell_for_guests(): void
    {
        $this->get(route('application'))->assertOk()->assertViewHas('page', 'application')
            ->assertSee('data-page="application"', false)
            ->assertSee('data-initial-register="false"', false);
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_registration_link_initializes_the_registration_form_inside_the_application(): void
    {
        $this->get(route('application', ['auth' => 'register']))->assertOk()
            ->assertViewHas('page', 'application')->assertSee('data-initial-register="true"', false);
        $this->assertGuest();
    }

    public function test_application_route_remains_available_for_authenticated_users_without_exposing_account_data_in_html(): void
    {
        $user = User::factory()->create(['email' => 'private@example.com']);
        $this->actingAs($user);

        $this->get(route('application', ['view' => 'transactions', 'year_from' => 2026, 'year_to' => 2027]))
            ->assertOk()->assertViewHas('page', 'application')->assertDontSee('private@example.com');
        $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('data.id', $user->id);
    }
}
