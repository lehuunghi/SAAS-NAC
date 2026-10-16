<?php

use App\Jobs\ProvisionEnvironment;
use App\Models\AuditEvent;
use App\Models\Operation;
use App\Models\Order;
use App\Models\QuoteRequest;
use App\Models\ServiceSubscription;
use App\Models\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CommerceService;
use App\Services\DeploymentService;
use App\Services\OperationLifecycle;
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

Artisan::command('saas:retry {operation} {--actor=}', function () {
    $operation = Operation::findOrFail($this->argument('operation'));
    $actor = $this->option('actor') ? User::where('email', strtolower($this->option('actor')))->first() : User::find($operation->requested_by);
    if (! $actor?->is_active || ! $actor->is_platform_admin) {
        $this->error('Cần admin TN20 đang hoạt động. Chỉ định --actor=EMAIL_ADMIN tại console tin cậy.');

        return 1;
    }
    if (! $this->confirm('Đã kiểm tra tài nguyên, SSH host key và nguyên nhân lỗi?')) {
        return 1;
    }
    app(DeploymentService::class)->retry($operation, $actor);
    AuditEvent::create(['tenant_id' => $operation->tenant_id, 'user_id' => $actor->id, 'action' => 'operation.retry_by_console', 'subject' => (string) $operation->id]);
    $this->info('Đã đưa lại vào hàng đợi.');
})->purpose('Thử lại có đối soát, không tự tạo thêm máy khi kết quả API chưa rõ');

Artisan::command('saas:dispatch-pending', function () {
    Operation::where('status', 'queued')->where('updated_at', '<', now()->subMinute())->orderBy('id')->limit(100)
        ->each(fn ($operation) => ProvisionEnvironment::dispatch($operation->id));
    $this->info('Đã đối soát tác vụ đang chờ.');
})->purpose('Đưa tác vụ queued vào Redis nếu enqueue trước đó bị gián đoạn');

Schedule::command('saas:dispatch-pending')->everyMinute()->withoutOverlapping();
Artisan::command('saas:recover-stale', function () {
    $count = app(OperationLifecycle::class)->recoverStale();
    $this->info('Đã chuyển '.$count.' tác vụ quá hạn sang lỗi cần đối soát; không tự chạy lại.');
})->purpose('Đối soát tác vụ running quá 50 phút khi khóa worker đã hết');
Schedule::command('saas:recover-stale')->everyMinute()->withoutOverlapping();

Artisan::command('saas:admin {email} {--revoke}', function () {
    $user = User::where('email', strtolower($this->argument('email')))->firstOrFail();
    if (! $this->confirm(($this->option('revoke') ? 'Thu hồi' : 'Cấp').' quyền quản trị toàn nền tảng cho '.$user->email.'?')) {
        return 1;
    }
    DB::transaction(function () use ($user) {
        $user->forceFill(['is_platform_admin' => ! $this->option('revoke')])->save();
        DB::table('admin_audit_events')->insert(['user_id' => null, 'tenant_id' => null, 'action' => $this->option('revoke') ? 'platform_admin.revoked_by_console' : 'platform_admin.granted_by_console', 'subject' => (string) $user->id, 'created_at' => now(), 'updated_at' => now()]);
    });
    $this->info('Đã cập nhật quyền. Tài khoản admin đăng nhập tại /admin.');
})->purpose('Chỉ console TN20 được cấp/thu hồi admin; không có API tự nâng quyền');

Artisan::command('saas:sales', function () {
    $this->table(['Yêu cầu', 'Công ty', 'Gói', 'Thiết bị', 'Chi nhánh', 'Trạng thái'], QuoteRequest::latest('id')->limit(50)->get(['id', 'tenant_id', 'plan_code', 'devices', 'branches', 'status'])->toArray());
    $this->table(['Đơn', 'Công ty', 'Số tiền VND', 'Trạng thái'], Order::latest('id')->limit(50)->get(['id', 'tenant_id', 'amount_vnd', 'status'])->toArray());
    $this->table(['Hỗ trợ', 'Công ty', 'Tiêu đề', 'Trạng thái'], SupportTicket::latest('id')->limit(30)->get(['id', 'tenant_id', 'subject', 'status'])->toArray());
})->purpose('TN20 xem yêu cầu báo giá, đơn hàng và yêu cầu hỗ trợ');

Artisan::command('saas:quote {request} {amount} {--days=30} {--terms=}', function () {
    $amount = filter_var($this->argument('amount'), FILTER_VALIDATE_INT);
    $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
    if (! $amount || ! $days || mb_strlen($this->option('terms')) < 10) {
        $this->error('Nhập số tiền nguyên VND, số ngày và --terms mô tả đầy đủ giá, thuế/phí và phạm vi dịch vụ.');

        return 1;
    }
    if (! $this->confirm('Phát hành báo giá '.$amount.' VND cho yêu cầu #'.$this->argument('request').'?')) {
        return 1;
    }
    $order = app(CommerceService::class)->issueQuote((int) $this->argument('request'), $amount, $days, $this->option('terms'));
    $this->info('Đã phát hành '.$order->number.' (ID '.$order->id.').');
})->purpose('TN20 phát hành báo giá riêng, khách xác nhận trên cổng');

Artisan::command('saas:order-paid {order} {reference} {amount}', function () {
    $amount = filter_var($this->argument('amount'), FILTER_VALIDATE_INT);
    if (! $amount || ! $this->confirm('Đã kiểm tra giao dịch thực nhận, số tiền và công ty cho đơn #'.$this->argument('order').'?')) {
        return 1;
    }
    app(CommerceService::class)->confirmPayment((int) $this->argument('order'), $this->argument('reference'), $amount);
    $this->info('Đã ghi nhận thanh toán và kích hoạt/gia hạn. Cấp phát chỉ chạy khi cấu hình cho phép.');
})->purpose('Đối soát thủ công; không thực hiện chuyển tiền hoặc đánh dấu từ trình duyệt');

Artisan::command('saas:ticket-reply {ticket} {message} {--close}', function () {
    if (mb_strlen($this->argument('message')) < 5 || mb_strlen($this->argument('message')) > 6000) {
        $this->error('Nội dung phản hồi từ 5 đến 6000 ký tự.');

        return 1;
    }
    $ticket = SupportTicket::findOrFail($this->argument('ticket'));
    $ticket->update(['reply' => $this->argument('message'), 'status' => $this->option('close') ? 'closed' : 'answered']);
    AuditEvent::create(['tenant_id' => $ticket->tenant_id, 'action' => 'support.replied_by_console', 'subject' => (string) $ticket->id]);
    $this->info('Đã lưu phản hồi trên cổng khách hàng.');
})->purpose('TN20 phản hồi yêu cầu hỗ trợ trong ứng dụng; không tự gửi email');
