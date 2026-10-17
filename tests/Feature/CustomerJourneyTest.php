<?php

namespace Tests\Feature;

use App\Jobs\ProvisionEnvironment;
use App\Models\Environment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_lifecycle_from_account_creation_to_payment_ssh_install_handover_and_support(): void
    {
        $this->freezeTime();
        Queue::fake([ProvisionEnvironment::class]);
        Process::fake();
        Http::preventStrayRequests();
        $zone = str_repeat('a', 32);
        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/'.$zone.'/dns_records*' => Http::response(['success' => true, 'result' => []]),
        ]);
        config([
            'commerce.bank' => ['enabled' => true, 'name' => 'Synthetic bank', 'account_number' => 'TEST-ONLY', 'account_holder' => 'Synthetic holder'],
            'saas.driver' => 'manual', 'saas.provisioning_enabled' => true, 'saas.ssh_enabled' => true,
            'saas.cloudflare.token' => 'synthetic-token', 'saas.cloudflare.zone_id' => $zone, 'saas.ansible_binary' => PHP_BINARY,
            'saas.artifact_url' => 'https://github.com/lehuunghi/NAC/releases/download/nac20-16.0.0/nac20-debian12-amd64-online.tar.gz', 'saas.artifact_sha256' => str_repeat('a', 64),
        ]);
        $admin = User::factory()->create(['email' => 'journey-admin@example.test', 'password' => 'Journey-Admin-Password!', 'is_platform_admin' => true]);
        $this->login($admin->email, 'Journey-Admin-Password!');
        $tenantId = $this->postJson('/saas/api/v1/admin/customers', [
            'name' => 'Khách hàng kiểm thử xuyên suốt', 'slug' => 'journey-customer', 'create_owner' => true,
            'owner_email' => 'journey-owner@example.test', 'owner_name' => 'Chủ công ty kiểm thử',
            'owner_password' => 'Initial-Owner-Password!', 'owner_password_confirmation' => 'Initial-Owner-Password!', 'current_password' => 'Journey-Admin-Password!',
        ])->assertCreated()->json('id');
        $this->postJson('/saas/api/v1/logout')->assertNoContent();
        $this->login('journey-owner@example.test', 'Initial-Owner-Password!');
        $this->getJson('/saas/api/v1/tenants')->assertStatus(423);
        $this->postJson('/saas/api/v1/password', [
            'current_password' => 'Initial-Owner-Password!', 'password' => 'Private-Owner-Password!', 'password_confirmation' => 'Private-Owner-Password!',
        ])->assertOk();
        $base = '/saas/api/v1/tenants/'.$tenantId;
        $this->postJson($base.'/sites', ['name' => 'Chi nhánh kiểm thử', 'code' => 'branch-test'])->assertCreated();
        $quoteId = $this->postJson($base.'/quotes', [
            'plan_code' => 'business', 'devices' => 100, 'branches' => 1, 'requirements' => 'Triển khai trên máy chủ riêng qua SSH.', 'idempotency_key' => Str::uuid(),
        ])->assertCreated()->json('id');
        $this->postJson('/saas/api/v1/logout')->assertNoContent();
        $this->login($admin->email, 'Journey-Admin-Password!');
        $orderId = $this->postJson('/saas/api/v1/admin/quotes/'.$quoteId.'/issue', [
            'amount_vnd' => 1000000, 'service_days' => 30, 'terms' => 'Điều khoản tổng hợp chỉ dùng kiểm thử, không phải giao dịch thật.',
        ])->assertCreated()->json('id');
        $this->postJson('/saas/api/v1/logout')->assertNoContent();
        $this->login('journey-owner@example.test', 'Private-Owner-Password!');
        $this->postJson($base.'/orders/'.$orderId, ['action' => 'accept'])->assertOk()->assertJsonPath('status', 'pending_payment');
        $this->postJson($base.'/orders/'.$orderId, ['action' => 'report_transfer'])->assertOk()->assertJsonPath('status', 'pending_review');
        $this->assertDatabaseCount('payments', 0);
        $ticketId = $this->postJson($base.'/tickets', ['subject' => 'Yêu cầu triển khai', 'message' => 'Đề nghị TN20 triển khai trên máy chủ kiểm thử riêng.'])->assertCreated()->json('id');
        $this->postJson('/saas/api/v1/logout')->assertNoContent();
        $this->login($admin->email, 'Journey-Admin-Password!');
        $payment = ['reference' => 'SYNTHETIC-JOURNEY-ONLY', 'amount_vnd' => 1000000, 'confirmed_received' => true, 'defer_installation' => true];
        $this->postJson('/saas/api/v1/admin/orders/'.$orderId.'/verify-payment', $payment)->assertOk()->assertJsonPath('status', 'paid');
        $this->postJson('/saas/api/v1/admin/orders/'.$orderId.'/verify-payment', $payment)->assertOk();
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('environments', 0);
        $target = [
            'driver' => 'ssh', 'idempotency_key' => (string) Str::uuid(), 'confirmed' => true, 'dedicated_server' => true, 'current_password' => 'Journey-Admin-Password!',
            'ssh_host' => '8.8.4.4', 'ssh_port' => 22, 'ssh_user' => 'root', 'ssh_password' => 'Synthetic-SSH-Password!',
            'ssh_host_key' => 'ssh-ed25519 '.base64_encode("\x00\x00\x00\x0bssh-ed25519\x00\x00\x00\x20".str_repeat('b', 32)),
        ];
        $operationId = $this->postJson('/saas/api/v1/admin/customers/'.$tenantId.'/install', $target)->assertAccepted()->json('id');
        $this->postJson('/saas/api/v1/admin/customers/'.$tenantId.'/install', $target)->assertAccepted()->assertJsonPath('id', $operationId);
        (new ProvisionEnvironment($operationId))->handle();
        $environment = Environment::where('tenant_id', $tenantId)->firstOrFail();
        $this->assertSame('awaiting_configuration', $environment->status);
        $this->assertNull($environment->ssh_password);
        $this->assertDatabaseHas('operations', ['id' => $operationId, 'status' => 'completed']);
        $this->postJson('/saas/api/v1/admin/environments/'.$environment->id.'/handover', [])->assertUnprocessable();
        $this->postJson('/saas/api/v1/admin/environments/'.$environment->id.'/handover', [
            'database_checked' => true, 'tls_checked' => true, 'connector_checked' => true, 'radius_checked' => true, 'backup_checked' => true,
            'evidence' => 'Biên bản tổng hợp giả lập trong kiểm thử tự động; không phải nghiệm thu máy thật.',
        ])->assertOk();
        $this->postJson('/saas/api/v1/admin/tickets/'.$ticketId.'/reply', ['reply' => 'Đã xử lý trong kịch bản kiểm thử tổng hợp.', 'status' => 'answered'])->assertOk();
        $this->postJson('/saas/api/v1/logout')->assertNoContent();
        $this->login('journey-owner@example.test', 'Private-Owner-Password!');
        $this->getJson($base.'/billing')->assertOk()->assertJsonCount(1, 'payments.data')->assertJsonPath('subscription.status', 'active');
        $this->getJson($base)->assertOk()->assertJsonPath('environments.0.status', 'ready')->assertJsonMissingPath('environments.0.ssh_password');
        $this->getJson($base.'/tickets')->assertOk()->assertJsonPath('data.0.status', 'answered');
        $notification = $this->getJson('/saas/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 1)->json('notifications.data.0.id');
        $this->postJson('/saas/api/v1/notifications/'.$notification.'/read')->assertOk();
        $this->getJson('/saas/api/v1/notifications')->assertOk()->assertJsonPath('unread_count', 0);
        $this->assertSame(1, Tenant::findOrFail($tenantId)->members()->count());
        Queue::assertPushed(ProvisionEnvironment::class, 1);
        Http::assertSentCount(2);
        Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command[0] === PHP_BINARY, 2);
    }

    private function login(string $email, string $password): void
    {
        $this->postJson('/saas/api/v1/login', ['email' => $email, 'password' => $password])->assertOk();
    }
}
