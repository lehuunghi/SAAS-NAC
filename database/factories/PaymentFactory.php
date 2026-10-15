<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->state(['status' => 'paid']),
            'tenant_id' => fn (array $attributes) => Order::findOrFail($attributes['order_id'])->tenant_id,
            'amount_vnd' => fn (array $attributes) => Order::findOrFail($attributes['order_id'])->amount_vnd,
            'reference' => 'test-'.Str::uuid(),
            'verified_at' => now(),
        ];
    }
}
