<?php

namespace Tests\Feature;

use App\Jobs\ProvisionEnvironment;
use App\Models\Order;
use App\Models\QuoteRequest;
use App\Models\ServiceSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $role = 'owner'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $tenant->members()->attach($user->id, ['role' => $role]);

        return [$tenant, $user];
    }

    public function test_catalog_has_contact_quotes_without_invented_prices(): void
    {
        $this->getJson('/saas/api/v1/catalog')->assertOk()->assertJsonCount(3, 'plans')->assertJsonMissingPath('plans.0.amount_vnd');
    }

    public function test_customer_registration_creates_only_its_own_company_without_subscription(): void
    {
        $this->postJson('/saas/api/v1/register', ['name' => 'Khách hàng', 'company' => 'Công ty mới', 'email' => 'new@example.test', 'password' => 'Registration-Test-2026!', 'password_confirmation' => 'Registration-Test-2026!'])->assertCreated();
        $this->getJson('/saas/api/v1/tenants')->assertJsonCount(1)->assertJsonPath('0.name', 'Công ty mới');
        $this->assertDatabaseCount('service_subscriptions', 0);
        $this->assertDatabaseCount('environments', 0);
    }

    public function test_unauthenticated_and_viewer_cannot_purchase(): void
    {
        [$tenant, $user] = $this->company('viewer');
        $url = '/saas/api/v1/tenants/'.$tenant->id.'/quotes';
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs($user)->postJson($url)->assertForbidden();
        $this->getJson('/saas/api/v1/tenants/'.$tenant->id.'/billing')->assertForbidden();
    }

    public function test_quote_rejects_price_or_tenant_override_and_deduplicates(): void
    {
        [$tenant, $user] = $this->company();
        $url = '/saas/api/v1/tenants/'.$tenant->id.'/quotes';
        $body = ['plan_code' => 'business', 'devices' => 50, 'branches' => 2, 'idempotency_key' => (string) Str::uuid()];
        $this->actingAs($user)->postJson($url, [...$body, 'amount_vnd' => 1])->assertUnprocessable();
        $this->postJson($url, [...$body, 'tenant_id' => 100])->assertUnprocessable();
        $id = $this->postJson($url, $body)->assertCreated()->json('id');
        $this->postJson($url, $body)->assertCreated()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('quote_requests', 1);
    }

    public function test_order_and_payment_history_are_isolated(): void
    {
        [$a, $user] = $this->company();
        [$b] = $this->company();
        $quote = QuoteRequest::factory()->create(['tenant_id' => $b->id]);
        $order = Order::factory()->create(['quote_request_id' => $quote->id]);
        $this->actingAs($user)->getJson('/saas/api/v1/tenants/'.$a->id.'/billing')->assertJsonCount(0, 'orders.data');
        $this->getJson('/saas/api/v1/tenants/'.$a->id.'/orders/'.$order->id)->assertNotFound();
        $this->postJson('/saas/api/v1/tenants/'.$a->id.'/orders/'.$order->id, ['action' => 'accept'])->assertNotFound();
        $this->getJson('/saas/api/v1/tenants/'.$b->id.'/billing')->assertForbidden();
    }

    public function test_customer_transfer_report_cannot_activate_service(): void
    {
        [$tenant, $user] = $this->company();
        $quote = QuoteRequest::factory()->create(['tenant_id' => $tenant->id]);
        $order = Order::factory()->create(['quote_request_id' => $quote->id]);
        $url = '/saas/api/v1/tenants/'.$tenant->id.'/orders/'.$order->id;
        $this->actingAs($user)->postJson($url, ['action' => 'accept'])->assertOk()->assertJsonPath('status', 'pending_payment');
        $this->postJson($url, ['action' => 'report_transfer'])->assertConflict();
        config(['commerce.bank' => ['enabled' => true, 'name' => 'Test bank', 'account_number' => 'test-only', 'account_holder' => 'Test only']]);
        $this->postJson($url, ['action' => 'report_transfer'])->assertOk()->assertJsonPath('status', 'pending_review');
        $this->postJson($url, ['action' => 'accept', 'status' => 'paid'])->assertUnprocessable();
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('service_subscriptions', 0);
    }

    public function test_expired_or_cancelled_quote_cannot_be_accepted(): void
    {
        $this->freezeTime();
        [$tenant, $user] = $this->company();
        $quote = QuoteRequest::factory()->create(['tenant_id' => $tenant->id]);
        $order = Order::factory()->create(['quote_request_id' => $quote->id, 'expires_at' => now()->subMinute()]);
        $url = '/saas/api/v1/tenants/'.$tenant->id.'/orders/'.$order->id;
        $this->actingAs($user)->postJson($url, ['action' => 'accept'])->assertConflict();
        $this->postJson($url, ['action' => 'cancel'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson($url, ['action' => 'accept'])->assertConflict();
    }

    public function test_verified_payment_is_idempotent_and_starts_only_one_environment(): void
    {
        $this->freezeTime();
        Queue::fake();
        [$tenant, $user] = $this->company();
        $quote = QuoteRequest::factory()->create(['tenant_id' => $tenant->id, 'requested_by' => $user->id]);
        $service = app(CommerceService::class);
        $order = $service->issueQuote($quote->id, 1000000, 30, 'Điều khoản báo giá dành riêng cho kiểm thử.');
        $order->update(['status' => 'pending_payment', 'accepted_at' => now()]);
        $service->confirmPayment($order->id, 'BANK-TEST-0001', 1000000);
        $service->confirmPayment($order->id, 'BANK-TEST-0001', 1000000);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('environments', 1);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
        $this->assertSame(now()->addDays(30)->toDateTimeString(), ServiceSubscription::where('tenant_id', $tenant->id)->first()->expires_at->toDateTimeString());
        Queue::assertPushed(ProvisionEnvironment::class, 1);
    }

    public function test_wrong_amount_cannot_be_confirmed(): void
    {
        $order = Order::factory()->create(['status' => 'pending_payment']);
        try {
            app(CommerceService::class)->confirmPayment($order->id, 'TEST-WRONG-AMOUNT', 1);
            $this->fail('Expected amount mismatch');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_support_is_scoped_and_cannot_forge_staff_reply(): void
    {
        [$tenant, $user] = $this->company('viewer');
        [$other] = $this->company();
        $url = '/saas/api/v1/tenants/'.$tenant->id.'/tickets';
        $this->actingAs($user)->postJson($url, ['subject' => 'Kết nối', 'message' => 'Yêu cầu hỗ trợ kết nối.', 'reply' => 'fake'])->assertUnprocessable();
        $this->postJson($url, ['subject' => 'Kết nối', 'message' => 'Yêu cầu hỗ trợ kết nối.'])->assertCreated();
        $this->getJson('/saas/api/v1/tenants/'.$other->id.'/tickets')->assertForbidden();
        $this->getJson($url)->assertJsonCount(1, 'data');
    }

    public function test_renewal_extends_remaining_term_without_creating_another_environment(): void
    {
        $this->freezeTime();
        Queue::fake();
        [$tenant] = $this->company();
        ServiceSubscription::create(['tenant_id' => $tenant->id, 'payment_reference' => 'previous-test-payment', 'status' => 'active', 'expires_at' => now()->addDays(10)]);
        $quote = QuoteRequest::factory()->create(['tenant_id' => $tenant->id]);
        $order = Order::factory()->create(['quote_request_id' => $quote->id, 'status' => 'pending_payment']);

        app(CommerceService::class)->confirmPayment($order->id, 'RENEWAL-TEST-01', 1000000);

        $this->assertSame(now()->addDays(40)->toDateTimeString(), ServiceSubscription::where('tenant_id', $tenant->id)->first()->expires_at->toDateTimeString());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_one_bank_reference_cannot_pay_for_two_orders(): void
    {
        Queue::fake();
        [$tenant] = $this->company();
        $first = Order::factory()->create(['quote_request_id' => QuoteRequest::factory()->create(['tenant_id' => $tenant->id])->id, 'status' => 'pending_payment']);
        $second = Order::factory()->create(['quote_request_id' => QuoteRequest::factory()->create(['tenant_id' => $tenant->id])->id, 'status' => 'pending_payment']);
        $service = app(CommerceService::class);
        $service->confirmPayment($first->id, 'SAME-REF-TEST', 1000000);
        try {
            $service->confirmPayment($second->id, 'SAME-REF-TEST', 1000000);
            $this->fail('Duplicate bank reference must be rejected');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseHas('orders', ['id' => $second->id, 'status' => 'pending_payment']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('environments', 1);
    }

    public function test_cancelled_order_cannot_activate_subscription(): void
    {
        $order = Order::factory()->create(['status' => 'cancelled']);
        try {
            app(CommerceService::class)->confirmPayment($order->id, 'CANCELLED-ORDER-TEST', 1000000);
            $this->fail('Cancelled order must be rejected');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertDatabaseCount('service_subscriptions', 0);
    }
}
