<?php

namespace App\Services\Provisioning;

use App\Models\Environment;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class AnsibleInstaller
{
    public function checkConfiguration(Environment $environment): void
    {
        if (! preg_match('/\Ahttps:\/\/github\.com\/lehuunghi\/NAC\/releases\/download\/[A-Za-z0-9._-]+\/nac20-debian12-amd64-online\.tar\.gz\z/', $environment->release_url ?? config('saas.artifact_url') ?? '')
            || ! preg_match('/\A[a-f0-9]{64}\z/', $environment->release_sha256 ?? config('saas.artifact_sha256') ?? '')
            || ! is_file(config('saas.ansible_binary') ?? '')) {
            throw new RuntimeException('INVALID_CONFIGURATION');
        }
        if ($environment->driver !== 'ssh' && (! is_file(config('saas.ssh_key') ?? '') || ! is_file(config('saas.known_hosts') ?? ''))) {
            throw new RuntimeException('INVALID_CONFIGURATION');
        }
    }

    public function install(Environment $environment): void
    {
        $this->run($environment, 'install-nac20.yml', 2100, 'ANSIBLE_FAILED');
    }

    public function preflight(Environment $environment): void
    {
        $this->run($environment, 'check-nac20-host.yml', 45, 'SSH_PREFLIGHT_FAILED');
    }

    private function run(Environment $environment, string $playbook, int $timeout, string $error): void
    {
        $this->checkConfiguration($environment);
        $directory = is_dir('/dev/shm') && is_writable('/dev/shm') ? '/dev/shm/nac20-provisioning' : storage_path('app/private/provisioning');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('INVALID_CONFIGURATION');
        }
        $files = [];
        try {
            $hostsFile = config('saas.known_hosts');
            $variables = [
                'ansible_host' => $environment->ip_address,
                'ansible_user' => 'root',
                'ansible_ssh_private_key_file' => config('saas.ssh_key'),
                'nac_wait_cloud_init' => true,
                'nac_bootstrap_python' => false,
                'nac_deployment_id' => hash('sha256', config('app.key').':'.$environment->tenant_id.':'.$environment->id),
                'nac_artifact_url' => $environment->release_url ?? config('saas.artifact_url'),
                'nac_artifact_sha256' => $environment->release_sha256 ?? config('saas.artifact_sha256'),
            ];
            if ($environment->driver === 'ssh') {
                SshProvisioner::assertTarget($environment);
                $hostsFile = $this->temporaryFile($directory, $files);
                $host = $environment->ssh_port === 22 ? $environment->ssh_host : '['.$environment->ssh_host.']:'.$environment->ssh_port;
                $key = implode(' ', array_slice(explode(' ', trim($environment->ssh_host_key)), 0, 2));
                file_put_contents($hostsFile, $host.' '.$key."\n");
                unset($variables['ansible_ssh_private_key_file']);
                $variables = [...$variables,
                    'ansible_host' => $environment->ssh_host,
                    'ansible_user' => $environment->ssh_user,
                    'ansible_port' => $environment->ssh_port,
                    'ansible_password' => $environment->ssh_password,
                    'ansible_ssh_password_mechanism' => 'sshpass',
                    'ansible_ssh_args' => '-C -o ControlMaster=no -o ControlPersist=no',
                    'ansible_become_password' => $environment->ssh_password,
                    'nac_wait_cloud_init' => false,
                    'nac_bootstrap_python' => true,
                ];
            }
            $variables['ansible_ssh_common_args'] = '-o StrictHostKeyChecking=yes -o UserKnownHostsFile='.escapeshellarg($hostsFile);
            if ($environment->driver === 'ssh') {
                $variables['ansible_ssh_common_args'] .= ' -o GlobalKnownHostsFile=/dev/null -o PubkeyAuthentication=no -o PreferredAuthentications=password,keyboard-interactive -o HostKeyAlgorithms=ssh-ed25519';
            }
            $inventory = $this->temporaryFile($directory, $files, '.yml');
            $content = "all:\n  hosts:\n    nac20:\n";
            foreach ($variables as $key => $value) {
                $tag = in_array($key, ['ansible_password', 'ansible_become_password'], true) ? '!unsafe ' : '';
                $content .= '      '.$key.': '.$tag.json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
            }
            file_put_contents($inventory, $content);
            try {
                $result = Process::timeout($timeout)->env(['ANSIBLE_HOST_KEY_CHECKING' => 'True', 'ANSIBLE_NO_LOG' => 'True'])
                    ->run([config('saas.ansible_binary'), '-i', $inventory, base_path('infrastructure/ansible/'.$playbook)]);
            } catch (\Throwable) {
                throw new RuntimeException($error);
            }
            if (! $result->successful()) {
                throw new RuntimeException($error);
            }
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function temporaryFile(string $directory, array &$files, string $suffix = ''): string
    {
        $file = tempnam($directory, 'nac20-');
        if ($file === false) {
            throw new RuntimeException('INVALID_CONFIGURATION');
        }
        $files[] = $file;
        if ($suffix !== '') {
            if (! rename($file, $file.$suffix)) {
                throw new RuntimeException('INVALID_CONFIGURATION');
            }
            $file .= $suffix;
            $files[] = $file;
        }
        chmod($file, 0600);

        return $file;
    }
}
