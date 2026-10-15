<?php

namespace Database\Factories;

use App\Models\QuoteRequest;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuoteRequest>
 */
class QuoteRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'requested_by' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'plan_code' => 'business',
            'devices' => 100,
            'branches' => 1,
        ];
    }
}
