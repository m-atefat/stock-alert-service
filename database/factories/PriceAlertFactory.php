<?php

namespace Database\Factories;

use App\Enums\AlertDirection;
use App\Enums\AlertStatus;
use App\Enums\Symbol;
use App\Models\PriceAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PriceAlert>
 */
class PriceAlertFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'symbol' => Symbol::XauUsd,
            'target_price' => $this->faker->randomFloat(2, 1500, 3000),
            'direction' => AlertDirection::Above,
            'status' => AlertStatus::Active,
        ];
    }

    public function below(): static
    {
        return $this->state(fn () => ['direction' => AlertDirection::Below]);
    }

    public function triggered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AlertStatus::Triggered,
            'triggered_price' => $attributes['target_price'] ?? 2000,
            'triggered_at' => now(),
        ]);
    }
}
