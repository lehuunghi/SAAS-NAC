<?php

namespace Database\Factories;

use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
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
            'user_id' => User::factory(),
            'subject' => 'Yêu cầu hỗ trợ kiểm thử',
            'message' => 'Nội dung yêu cầu trong môi trường kiểm thử.',
        ];
    }
}
