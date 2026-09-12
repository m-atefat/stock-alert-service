<?php

namespace Tests\Feature;

use App\Enums\AlertDirection;
use App\Enums\Symbol;
use App\Models\PriceAlert;
use App\Models\User;
use App\Support\PriceCache;
use App\Support\PriceQuote;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;

class PriceAlertApiTest extends FeatureTestCase
{
    private function cacheCurrentPrice(Symbol $symbol, string $price): void
    {
        app(PriceCache::class)->put(new PriceQuote($symbol, $price, now()->toImmutable()));
    }

    #[Test]
    public function index_returns_only_the_callers_own_alerts_as_a_resource_collection(): void
    {
        $me = User::factory()->create();
        $someoneElse = User::factory()->create();

        PriceAlert::factory()->for($me)->create();
        PriceAlert::factory()->for($someoneElse)->create();

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/alerts');

        $response->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function store_infers_above_when_the_target_is_above_the_current_price(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->cacheCurrentPrice(Symbol::XauUsd, '2000.00000000');

        $response = $this->postJson('/api/alerts', [
            'target_price' => '2500.00000000',
        ]);

        $response->assertStatus(201)->assertJsonPath('direction', 'above');
        $this->assertDatabaseHas('price_alerts', ['user_id' => $user->id, 'direction' => AlertDirection::Above->value]);
    }

    #[Test]
    public function store_infers_below_when_the_target_is_below_the_current_price(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->cacheCurrentPrice(Symbol::XauUsd, '2000.00000000');

        $response = $this->postJson('/api/alerts', [
            'target_price' => '1500.00000000',
        ]);

        $response->assertStatus(201)->assertJsonPath('direction', 'below');
        $this->assertDatabaseHas('price_alerts', ['user_id' => $user->id, 'direction' => AlertDirection::Below->value]);
    }

    #[Test]
    public function store_approves_a_target_exactly_equal_to_the_current_price(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->cacheCurrentPrice(Symbol::XauUsd, '2000.00000000');

        $response = $this->postJson('/api/alerts', [
            'target_price' => '2000.00000000',
        ]);

        $response->assertStatus(201)->assertJsonPath('direction', 'above');
    }

    #[Test]
    public function store_ignores_a_symbol_field_and_always_stores_xauusd(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->cacheCurrentPrice(Symbol::XauUsd, '2000.00000000');

        $response = $this->postJson('/api/alerts', [
            'target_price' => '2500.00000000',
        ]);

        $response->assertStatus(201)->assertJsonPath('symbol', 'XAUUSD');
        $this->assertDatabaseHas('price_alerts', ['user_id' => $user->id, 'symbol' => 'XAUUSD']);

        $response = $this->postJson('/api/alerts', [
            'symbol' => 'FOOBAR',
            'target_price' => '2600.00000000',
        ]);

        $response->assertStatus(201)->assertJsonPath('symbol', 'XAUUSD');
    }

    #[Test]
    public function store_returns_503_when_no_price_has_been_cached_yet(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/alerts', [
            'target_price' => '2000.00000000',
        ]);

        $response->assertStatus(503);
    }

    #[Test]
    public function store_rejects_scientific_notation_with_a_422_instead_of_crashing(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->cacheCurrentPrice(Symbol::XauUsd, '2000.00000000');

        $response = $this->postJson('/api/alerts', [
            'target_price' => '1e5',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('target_price');
    }

    #[Test]
    public function store_rejects_a_target_price_past_the_scaled_score_ceiling_with_a_422(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->cacheCurrentPrice(Symbol::XauUsd, '2000.00000000');

        $response = $this->postJson('/api/alerts', [
            'target_price' => '999999999.99',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('target_price');
    }

    #[Test]
    public function store_returns_409_for_a_duplicate_active_alert(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->cacheCurrentPrice(Symbol::XauUsd, '2000.00000000');

        $first = $this->postJson('/api/alerts', ['target_price' => '2500.00000000']);
        $first->assertStatus(201);

        $second = $this->postJson('/api/alerts', ['target_price' => '2500.00000000']);

        $second->assertStatus(409);

        $this->assertDatabaseCount('price_alerts', 1);
    }

    #[Test]
    public function store_rejects_a_new_alert_once_the_caller_has_100_active_alerts(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->cacheCurrentPrice(Symbol::XauUsd, '2000.00000000');

        PriceAlert::factory()->for($user)->count(100)->sequence(
            fn ($sequence) => ['target_price' => (string) (2500 + $sequence->index)],
        )->create();

        $response = $this->postJson('/api/alerts', ['target_price' => '3000.00000000']);

        $response->assertStatus(422)->assertJsonValidationErrors('target_price');
    }

    #[Test]
    public function destroy_removes_the_callers_own_alert(): void
    {
        $owner = User::factory()->create();
        $alert = PriceAlert::factory()->for($owner)->create();

        Sanctum::actingAs($owner);

        $response = $this->deleteJson("/api/alerts/{$alert->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('price_alerts', ['id' => $alert->id]);
    }

    #[Test]
    public function destroy_on_another_users_alert_returns_404_not_403(): void
    {
        $owner = User::factory()->create();
        $alert = PriceAlert::factory()->for($owner)->create();

        Sanctum::actingAs(User::factory()->create());

        $response = $this->deleteJson("/api/alerts/{$alert->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('price_alerts', ['id' => $alert->id]);
    }

    #[Test]
    public function destroy_on_a_missing_alert_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->deleteJson('/api/alerts/999999');

        $response->assertStatus(404);
    }
}
