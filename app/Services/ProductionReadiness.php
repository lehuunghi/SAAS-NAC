<?php

namespace App\Services;

use App\Models\Environment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProductionReadiness
{
    /** @return array<string, array{label: string, passed: bool}> */
    public function checks(bool $connections = false): array
    {
        $url = parse_url((string) config('app.url'));
        $driver = config('saas.driver');
        $artifact = (string) config('saas.artifact_url');
        $checks = [
            'production' => ['label' => 'APP_ENV=production, tắt mô phỏng', 'passed' => app()->environment('production')],
            'debug' => ['label' => 'Tắt debug', 'passed' => config('app.debug') === false],
            'https' => ['label' => 'Tên miền SaaS HTTPS tin cậy', 'passed' => is_array($url) && ($url['scheme'] ?? '') === 'https' && ! empty($url['host']) && ! in_array($url['host'], ['localhost', '127.0.0.1', '::1'], true) && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment']) && in_array($url['path'] ?? '', ['', '/'], true)],
            'app_key' => ['label' => 'Đã khai báo khóa ứng dụng', 'passed' => filled(config('app.key'))],
            'mysql' => ['label' => 'MySQL, InnoDB và utf8mb4 được cấu hình', 'passed' => config('database.default') === 'mysql' && config('database.connections.mysql.charset') === 'utf8mb4' && config('database.connections.mysql.engine') === 'InnoDB'],
            'redis' => ['label' => 'Session, cache và queue dùng Redis', 'passed' => config('session.driver') === 'redis' && config('cache.default') === 'redis' && config('queue.default') === 'redis'],
            'cookies' => ['label' => 'Cookie Secure/HttpOnly/SameSite, phiên mã hóa', 'passed' => config('session.secure') === true && config('session.http_only') === true && config('session.encrypt') === true && in_array(config('session.same_site'), ['lax', 'strict'], true)],
            'queue_timeout' => ['label' => 'Redis retry_after lớn hơn thời gian giữ khóa worker', 'passed' => config('queue.connections.redis.retry_after') >= 2700],
            'driver' => ['label' => 'Triển khai thật: manual/SSH hoặc Hetzner', 'passed' => config('saas.provisioning_enabled') === true && ($driver === 'manual' ? config('saas.ssh_enabled') === true : $driver === 'hetzner')],
            'dns' => ['label' => 'Vùng DNS và thông tin API đã khai báo', 'passed' => filled(config('saas.cloudflare.token')) && preg_match('/\\A[a-f0-9]{32}\\z/', (string) config('saas.cloudflare.zone_id')) === 1 && preg_match('/\\A[a-z0-9-]+(?:\\.[a-z0-9-]+)+\\z/', (string) config('saas.domain')) === 1],
            'artifact' => ['label' => 'Bộ cài có URL/SHA-256, không dùng nhãn preview', 'passed' => preg_match('/\\Ahttps:\\/\\/github\\.com\\/lehuunghi\\/NAC\\/releases\\/download\\/[A-Za-z0-9._-]+\\/nac20-debian12-amd64-online\\.tar\\.gz\\z/', $artifact) === 1 && ! str_contains(strtolower($artifact), 'preview') && preg_match('/\\A[a-f0-9]{64}\\z/', (string) config('saas.artifact_sha256')) === 1],
            'ansible' => ['label' => 'Tệp Ansible có quyền chạy trên worker', 'passed' => is_file((string) config('saas.ansible_binary')) && is_executable((string) config('saas.ansible_binary'))],
            'smtp' => ['label' => 'Khôi phục email đã bật với SMTP/HTTPS', 'passed' => app(PasswordRecovery::class)->available()],
            'bank' => ['label' => 'Thông tin chuyển khoản đã khai báo', 'passed' => config('commerce.bank.enabled') === true && filled(config('commerce.bank.name')) && filled(config('commerce.bank.account_number')) && filled(config('commerce.bank.account_holder'))],
        ];
        if ($driver === 'hetzner') {
            $checks['hcloud'] = ['label' => 'Thông tin Hetzner, khóa SSH và known_hosts', 'passed' => collect(['token', 'ssh_key_id', 'firewall_id', 'server_type', 'location'])->every(fn (string $key): bool => filled(config('saas.hcloud.'.$key))) && is_file((string) config('saas.ssh_key')) && is_file((string) config('saas.known_hosts'))];
        }
        if ($connections) {
            $mysqlReady = $this->mysqlReady();
            $checks['mysql_connection'] = ['label' => 'Kết nối MySQL, schema SaaS, bảng InnoDB/utf8mb4', 'passed' => $mysqlReady];
            $checks['redis_connection'] = ['label' => 'Redis PING: cache, session và queue', 'passed' => $this->redisReady()];
            $checks['simulation_data'] = ['label' => 'Database riêng không có môi trường mô phỏng', 'passed' => $mysqlReady && $this->withoutSimulationData()];
        }

        return $checks;
    }

    private function mysqlReady(): bool
    {
        if (config('database.default') !== 'mysql') {
            return false;
        }
        try {
            foreach (['users', 'tenants', 'environments', 'operations', 'orders', 'payments', 'service_subscriptions'] as $table) {
                if (! Schema::hasTable($table)) {
                    return false;
                }
            }
            $result = DB::selectOne("SELECT COUNT(*) AS invalid_tables FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND (engine <> 'InnoDB' OR table_collation NOT LIKE 'utf8mb4%')");

            return (int) $result->invalid_tables === 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function redisReady(): bool
    {
        if (config('cache.default') !== 'redis' || config('queue.default') !== 'redis' || config('session.driver') !== 'redis') {
            return false;
        }
        try {
            $connections = [
                config('cache.stores.redis.connection', 'cache'),
                config('cache.stores.redis.lock_connection', 'default'),
                config('session.connection') ?: 'default',
                config('queue.connections.redis.connection', 'default'),
            ];
            foreach (array_unique($connections) as $connection) {
                $result = Redis::connection($connection)->ping();
                if ($result !== true && strtoupper((string) $result) !== 'PONG') {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function withoutSimulationData(): bool
    {
        try {
            return ! Environment::where('driver', 'simulation')->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
