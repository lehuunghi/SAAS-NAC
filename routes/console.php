<?php

use App\Jobs\ProvisionEnvironment;
use App\Models\AuditEvent;
use App\Models\Environment;
use App\Models\Operation;
use App\Models\ServiceSubscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('saas:company {slug} {name} {email}', function () {
    $slug = $this->argument('slug');
    if (! preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', $slug) || ! filter_var($this->argument('email'), FILTER_VALIDATE_EMAIL)) {
        $this->error('Mã công ty 3–50 ký tự thường/số/gạch ngang và email hợp lệ là bắt buộc.');

        return 1;
    }
    $email = strtolower($this->argument('email'));
    $user = User::where('email', $email)->first();
    $password = $user ? null : $this->secret('Mật khẩu chủ công ty (tối thiểu 12 ký tự)');
    if (! $user && strlen((string) $password) < 12) {
        $this->error('Mật khẩu quá ngắn.');

        return 1;
    }
    if (Tenant::where('slug', $slug)->exists()) {
        $this->error('Mã công ty đã tồn tại.');

        return 1;
    }
    DB::transaction(function () use ($user, $email, $password, $slug) {
        $owner = $user ?? User::create(['name' => $this->argument('name'), 'email' => $email, 'password' => Hash::make($password)]);
        $tenant = Tenant::create(['name' => $this->argument('name'), 'slug' => $slug]);
        $tenant->members()->attach($owner->id, ['role' => 'owner']);
    });
    $this->info('Đã tạo công ty và quyền chủ công ty.');
})->purpose('TN20 tạo công ty và chủ sở hữu; không có tài khoản mặc định');

Artisan::command('saas:member {slug} {email} {role}', function () {
    $role = $this->argument('role');
    if (! in_array($role, ['admin', 'operator', 'viewer'], true)) {
        $this->error('Vai trò: admin, operator hoặc viewer.');

        return 1;
    }
    $tenant = Tenant::where('slug', $this->argument('slug'))->firstOrFail();
    $user = User::where('email', strtolower($this->argument('email')))->firstOrFail();
    if ($tenant->members()->where('users.id', $user->id)->wherePivot('role', 'owner')->exists()) {
        $this->error('Không thay vai trò chủ công ty bằng lệnh này.');

        return 1;
    }
    $tenant->members()->syncWithoutDetaching([$user->id => ['role' => $role]]);
    $this->info('Đã cập nhật thành viên.');
})->purpose('Gán người dùng đã có vào công ty; thực hiện tại console tin cậy');

Artisan::command('saas:activate {slug} {reference} {--days=30}', function () {
    $days = filter_var($this->option('days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 366]]);
    if (! $days || strlen($this->argument('reference')) > 120) {
        $this->error('Ngày phải từ 1 đến 366, tham chiếu tối đa 120 ký tự.');

        return 1;
    }
    $tenant = Tenant::where('slug', $this->argument('slug'))->firstOrFail();
    if (! $this->confirm('Đã đối soát thanh toán và đồng ý cấp dịch vụ cho '.$tenant->name.'?')) {
        return 1;
    }
    DB::transaction(function () use ($tenant, $days) {
        ServiceSubscription::updateOrCreate(['tenant_id' => $tenant->id], ['status' => 'active', 'payment_reference' => $this->argument('reference'), 'expires_at' => now()->addDays($days)]);
        AuditEvent::create(['tenant_id' => $tenant->id, 'action' => 'subscription.activated_by_console', 'subject' => (string) $tenant->id]);
    });
    $this->info('Đã kích hoạt thuê bao; khách hàng có thể yêu cầu cấp phát.');
})->purpose('Xác nhận thuê bao thủ công sau đối soát; chưa phải webhook thanh toán');

Artisan::command('saas:retry {operation}', function () {
    $operation = Operation::findOrFail($this->argument('operation'));
    $environment = Environment::where('tenant_id', $operation->tenant_id)->findOrFail($operation->environment_id);
    if ($operation->status !== 'failed' || ($environment->driver !== 'simulation' && ! $environment->server_id)) {
        $this->error('Chỉ thử lại tác vụ lỗi đã xác định server_id. Nếu tạo máy bị timeout, đối soát nhà cung cấp trước.');

        return 1;
    }
    if (! $this->confirm('Đã kiểm tra tài nguyên, SSH host key và nguyên nhân lỗi?')) {
        return 1;
    }
    $operation->update(['status' => 'queued', 'error_code' => null]);
    AuditEvent::create(['tenant_id' => $operation->tenant_id, 'action' => 'operation.retry_by_console', 'subject' => (string) $operation->id]);
    ProvisionEnvironment::dispatch($operation->id);
    $this->info('Đã đưa lại vào hàng đợi.');
})->purpose('Thử lại có đối soát, không tự tạo thêm máy khi kết quả API chưa rõ');

Artisan::command('saas:dispatch-pending', function () {
    Operation::where('status', 'queued')->where('updated_at', '<', now()->subMinute())->orderBy('id')->limit(100)
        ->each(fn ($operation) => ProvisionEnvironment::dispatch($operation->id));
    $this->info('Đã đối soát tác vụ đang chờ.');
})->purpose('Đưa tác vụ queued vào Redis nếu enqueue trước đó bị gián đoạn');

Schedule::command('saas:dispatch-pending')->everyMinute()->withoutOverlapping();
