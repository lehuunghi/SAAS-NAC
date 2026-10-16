<?php

namespace App\Services\Provisioning;

use App\Models\Environment;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SshProvisioner implements Provisioner
{
    public static function validHostKey(string $key): bool
    {
        if (! preg_match('/\Assh-ed25519 ([A-Za-z0-9+\/]{68})(?: [^\r\n]+)?\z/', $key, $matches)) {
            return false;
        }
        $decoded = base64_decode($matches[1], true);

        return is_string($decoded) && strlen($decoded) === 51 && str_starts_with($decoded, "\x00\x00\x00\x0bssh-ed25519\x00\x00\x00\x20");
    }

    public static function validateTarget(array $input): array
    {
        return Validator::make($input, [
            'ssh_host' => ['required', 'ipv4', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) {
                    $fail('Cần địa chỉ IPv4 công khai của máy chủ riêng.');
                }
            }],
            'ssh_port' => ['required', 'integer', 'between:1,65535'],
            'ssh_user' => ['required', 'string', 'regex:/\A[a-z_][a-z0-9_-]{0,31}\z/'],
            'ssh_password' => ['required', 'string', 'max:1024', 'regex:/\A[^\x00\r\n]+\z/u'],
            'ssh_host_key' => ['required', 'string', 'max:512', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! self::validHostKey($value)) {
                    $fail('Cần khóa công khai ssh-ed25519 lấy từ console tin cậy của máy chủ.');
                }
            }],
        ])->validate();
    }

    public static function assertTarget(Environment $environment): void
    {
        if (! filter_var($environment->ssh_host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)
            || $environment->ip_address !== $environment->ssh_host
            || ! preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/', $environment->ssh_user ?? '')
            || ! is_int($environment->ssh_port) || $environment->ssh_port < 1 || $environment->ssh_port > 65535
            || ! self::validHostKey($environment->ssh_host_key ?? '') || ! $environment->ssh_password) {
            throw new RuntimeException('INVALID_SSH_TARGET');
        }
    }

    public function install(Environment $environment, callable $progress): void
    {
        $progress('checking_ssh_target');
        self::assertTarget($environment);
        app(AnsibleInstaller::class)->checkConfiguration($environment);
        $progress('checking_ssh_connection');
        app(AnsibleInstaller::class)->preflight($environment);
        $progress('configuring_dns');
        app(DnsManager::class)->configure($environment);
        $progress('installing_packages');
        app(AnsibleInstaller::class)->install($environment);
        $environment->update(['status' => 'awaiting_configuration', 'ssh_password' => null]);
        $progress('awaiting_configuration');
    }
}
