<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\QuoteRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quote_request_id' => QuoteRequest::factory(),
            'tenant_id' => fn (array $attributes) => QuoteRequest::findOrFail($attributes['quote_request_id'])->tenant_id,
            'number' => 'N20-'.strtoupper((string) Str::ulid()),
            'plan_code' => 'business',
            'plan_name' => 'NAC20 Doanh nghiệp',
            'amount_vnd' => 1000000,
            'service_days' => 30,
            'terms' => 'Điều khoản chỉ sử dụng trong kiểm thử.',
            'expires_at' => now()->addDays(7),
        ];
    }
}
