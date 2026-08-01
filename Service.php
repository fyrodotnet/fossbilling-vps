<?php

/**
 * FOSSBilling.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 *
 * Copyright FOSSBilling 2022
 * This software may contain code previously used in the BoxBilling project.
 * Copyright BoxBilling, Inc 2011-2021
 *
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */

namespace Box\Mod\Vps;

use FOSSBilling\Config;
use FOSSBilling\InformationException;
use GuzzleHttp\Client as GuzzleClient;
use ProxmoxVE\Proxmox as Proxmox;

require_once __DIR__ . '/Api/vendor/autoload.php';

class Service
{
    public const DEFAULT_VPS_DOMAIN = 'rc7.net';
    public const DEFAULT_PROXMOX_HOST = 'pm2.' . self::DEFAULT_VPS_DOMAIN;
    public const DEFAULT_PROXMOX_NODE = 'pm2';
    public const DEFAULT_PROXMOX_ROOT_USER = 'root';
    public const DEFAULT_PROXMOX_AUTH_REALM = 'pam';
    public const DEFAULT_PROXMOX_CLIENT_REALM = 'pve';
    public const PROXMOX_CLIENT_TOKEN_ID = 'fossbilling';
    public const VM_ID_START = 1000;
    public const STORAGE_SLOW = 'nas2-vm-slow';
    public const STORAGE_FAST = 'nas2-vm-fast';
    public const NETWORK_MODE_PUBLIC = 'public';
    public const NETWORK_MODE_INTERNAL = 'internal';
    public const NETWORK_MODE_IPV6_ONLY = 'ipv6_only';
    private const TEMP_ROOT_PASSWORD = 'rc7temppass';
    private const CLIENT_FOLDER_META_KEY = 'vps_vm_folders';
    private const ROOT_TOKEN_SECRET_META_KEY = 'proxmox_root_token_secret';
    private const ROOT_PASSWORD_META_KEY = 'proxmox_root_password';

    private const TEMPLATE_MAP_SSD = [
        'alma8' => 901,
        'alma9' => 902,
        'alma10' => 903,
        'rock9' => 904,
        'rock10' => 905,
        'cent9' => 907,
        'cent10' => 908,
        'ubu22' => 909,
        'ubu24' => 910,
        'deb135' => 912,
        'bsd151' => 913,
        'win19' => 914,
        'win22' => 915,
        'win25' => 916,
				'ubu26' => 917,
    ];

    // Optional SSD-specific template VMIDs by slug.
    // Leave entries unset to fall back to the SATA template VMID for that OS.
    private const TEMPLATE_MAP_SATA = [
        'alma8' => 801,
        'alma9' => 802,
        'alma10' => 803,
        'rock9' => 804,
        'rock10' => 805,
        'cent9' => 807,
        'cent10' => 808,
        'ubu22' => 809,
        'ubu24' => 810,
				'ubu26' => 817,
        'deb135' => 812,
        'bsd151' => 813,
        'win19' => 814,
        'win22' => 815,
        'win25' => 814,
    ];

    protected $di;
    private ?Proxmox $rootProxmox = null;
    private array $clientProxmoxCache = [];
    private ?float $rootAuthFailureAt = null;
    private ?string $rootAuthFailureMessage = null;

    public static function onBeforeAdminCronRun(\Box_Event $event): void
    {
        $di = $event->getDi();
        /** @var self $service */
        $service = $di['mod_service']('Vps');
        $service->setDi($di);

        try {
            $service->syncVmRuntimeState();
        } catch (\Throwable $e) {
            $di['logger']->warning('VPS state-sync cron warning: ' . $e->getMessage());
        }
        try {
            $service->syncContainerRuntimeState();
        } catch (\Throwable $e) {
            $di['logger']->warning('VPS container state-sync cron warning: ' . $e->getMessage());
        }

        try {
            $service->runBillingCycleNow();
        } catch (\Throwable $e) {
            $di['logger']->warning('VPS hourly billing cron warning: ' . $e->getMessage());
        }

        try {
            $service->runQueuedProvisionTasks(5);
        } catch (\Throwable $e) {
            $di['logger']->warning('VPS async provisioning cron warning: ' . $e->getMessage());
        }

        try {
            $service->runQueuedDeleteTasks(5);
        } catch (\Throwable $e) {
            $di['logger']->warning('VPS async delete cron warning: ' . $e->getMessage());
        }

        try {
            $service->runQueuedSnapshotTasks(5);
        } catch (\Throwable $e) {
            $di['logger']->warning('VPS async snapshot cron warning: ' . $e->getMessage());
        }

        try {
            $service->runQueuedContainerTasks(5);
        } catch (\Throwable $e) {
            $di['logger']->warning('VPS async container cron warning: ' . $e->getMessage());
        }
    }

    public function setDi(\Pimple\Container|null $di): void
    {
        $this->di = $di;
    }

    public function getModulePermissions(): array
    {
        return [
            'manage_settings' => [
                'type' => 'bool',
                'display_name' => 'Manage settings',
                'description' => 'Allows the staff member to edit VPS module settings',
            ],
            'manage_vms' => [
                'type' => 'bool',
                'display_name' => 'Manage VPS instances',
                'description' => 'Allows the staff member to view and manage all VPS instances',
            ],
        ];
    }

    public function install(): bool
    {
        $sql = '
        CREATE TABLE IF NOT EXISTS `mod_vps_vm` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `client_id` bigint(20) NOT NULL,
            `order_id` bigint(20) DEFAULT NULL,
            `vmid` int(11) NOT NULL,
            `vm_name` varchar(255) DEFAULT NULL,
            `template_vmid` int(11) DEFAULT NULL,
            `os_slug` varchar(50) DEFAULT NULL,
            `disk_size` int(11) DEFAULT NULL,
            `disk_type` varchar(20) DEFAULT NULL,
            `network_mode` varchar(20) DEFAULT NULL,
            `monthly_cost` decimal(10,2) DEFAULT NULL,
            `ip_address` varchar(64) DEFAULT NULL,
            `power_state` varchar(20) DEFAULT "unknown",
            `powered_off_at` varchar(35) DEFAULT NULL,
            `state_synced_at` varchar(35) DEFAULT NULL,
            `root_password` varchar(255) DEFAULT NULL,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `vmid` (`vmid`),
            KEY `client_id` (`client_id`),
            KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';
        $this->di['db']->exec($sql);
        $this->ensureBillingSchema();

        return true;
    }

    public function uninstall(): bool
    {
        $this->di['db']->exec('DROP TABLE IF EXISTS `mod_vps_vm`;');
        $this->di['db']->exec('DROP TABLE IF EXISTS `mod_vps_provision_task`;');
        $this->di['db']->exec('DROP TABLE IF EXISTS `mod_vps_delete_task`;');
        $this->di['db']->exec('DROP TABLE IF EXISTS `mod_vps_snapshot_task`;');
        $this->di['db']->exec('DROP TABLE IF EXISTS `mod_vps_snapshot`;');
        $this->di['db']->exec('DROP TABLE IF EXISTS `mod_vps_container_task`;');
        $this->di['db']->exec('DROP TABLE IF EXISTS `mod_vps_container`;');
        $this->di['db']->exec('DROP TABLE IF EXISTS `mod_vps_usage_hourly`;');
        $this->di['db']->exec('DROP TABLE IF EXISTS `mod_vps_container_usage_hourly`;');
        $this->di['db']->exec('DROP TABLE IF EXISTS `mod_vps_billing_run`;');

        return true;
    }

    public function update(array $manifest): bool
    {
        return $this->install();
    }

    public function getTemplateMap(?string $diskType = null): array
    {
        if ($diskType !== null && strtolower($diskType) === 'sata') {
            return self::TEMPLATE_MAP_SATA;
        }

        if ($diskType !== null && strtolower($diskType) === 'ssd') {
            return array_replace(self::TEMPLATE_MAP_SATA, self::TEMPLATE_MAP_SSD);
        }

        return array_replace(self::TEMPLATE_MAP_SATA, self::TEMPLATE_MAP_SSD);
    }

    public function getModuleConfig(): array
    {
        return $this->di['mod_config']('vps');
    }

    public function saveSecureSettings(array $data): bool
    {
        $current = $this->getModuleConfig();
        $tokenIdToSave = $this->normalizeTokenId(trim((string) ($data['proxmox_root_token_id'] ?? ($current['proxmox_root_token_id'] ?? ''))));

        $tokenSecret = trim((string) ($data['proxmox_root_token_secret'] ?? ''));
        $rootPassword = trim((string) ($data['proxmox_root_password'] ?? ''));
        $slowHourlyRate = max(0.0, (float) ($data['storage_slow_hourly_rate'] ?? ($current['storage_slow_hourly_rate'] ?? 0.0002777778)));
        $fastHourlyRate = max(0.0, (float) ($data['storage_fast_hourly_rate'] ?? ($current['storage_fast_hourly_rate'] ?? 0.0006944444)));
        $cpuHourlyRate = max(0.0, (float) ($data['cpu_hourly_rate'] ?? ($current['cpu_hourly_rate'] ?? 0.0018055556)));
        $memoryHourlyRate = max(0.0, (float) ($data['memory_hourly_rate'] ?? ($current['memory_hourly_rate'] ?? 0.0016666667)));
        $legacyIpMonthlyRate = max(0.0, (float) ($current['ip_hourly_rate'] ?? 0.0) * 720);
        $publicIpMonthlyRate = max(0.0, (float) ($data['ip_public_monthly_rate'] ?? ($current['ip_public_monthly_rate'] ?? $legacyIpMonthlyRate)));
        $internalIpMonthlyRate = max(0.0, (float) ($data['ip_internal_monthly_rate'] ?? ($current['ip_internal_monthly_rate'] ?? $publicIpMonthlyRate)));
        $ipv6OnlyIpMonthlyRate = max(0.0, (float) ($data['ip_ipv6_only_monthly_rate'] ?? ($current['ip_ipv6_only_monthly_rate'] ?? 0.0)));
        $legacyIpHourlyRate = $publicIpMonthlyRate / 720;

        if ($tokenSecret !== '') {
            $this->storeModuleSecret(self::ROOT_TOKEN_SECRET_META_KEY, $tokenSecret);
        } elseif ($tokenIdToSave === '') {
            $this->storeModuleSecret(self::ROOT_TOKEN_SECRET_META_KEY, '');
        }
        if ($rootPassword !== '') {
            $this->storeModuleSecret(self::ROOT_PASSWORD_META_KEY, $rootPassword);
        }

        $effectiveTokenSecret = $tokenSecret !== '' ? $tokenSecret : ($tokenIdToSave !== '' ? $this->getModuleSecret(self::ROOT_TOKEN_SECRET_META_KEY) : '');
        $effectivePassword = $rootPassword !== '' ? $rootPassword : $this->getModuleSecret(self::ROOT_PASSWORD_META_KEY);
        if ($tokenIdToSave !== '' && $effectiveTokenSecret === '') {
            throw new InformationException('Root token configuration is incomplete: provide token secret for the configured token ID', [], 400);
        }
        if ($tokenIdToSave === '' && $effectivePassword === '') {
            throw new InformationException('Root authentication is incomplete: configure token ID + token secret, or set root password', [], 400);
        }

        $configToSave = [
            'ext' => 'mod_vps',
            'proxmox_host' => trim((string) ($data['proxmox_host'] ?? ($current['proxmox_host'] ?? self::DEFAULT_PROXMOX_HOST))),
            'proxmox_node' => trim((string) ($data['proxmox_node'] ?? ($current['proxmox_node'] ?? self::DEFAULT_PROXMOX_NODE))),
            'proxmox_auth_realm' => trim((string) ($data['proxmox_auth_realm'] ?? ($current['proxmox_auth_realm'] ?? self::DEFAULT_PROXMOX_AUTH_REALM))),
            'proxmox_client_realm' => trim((string) ($data['proxmox_client_realm'] ?? ($current['proxmox_client_realm'] ?? self::DEFAULT_PROXMOX_CLIENT_REALM))),
            'proxmox_root_user' => trim((string) ($data['proxmox_root_user'] ?? ($current['proxmox_root_user'] ?? self::DEFAULT_PROXMOX_ROOT_USER))),
            'proxmox_root_token_id' => $tokenIdToSave,
            // Never persist secrets in extension config payload.
            'proxmox_root_token_secret' => '',
            'proxmox_root_password' => '',
            'provision_billing_bypass' => !empty($data['provision_billing_bypass']) ? 1 : 0,
            'proxmox_timeout' => (string) ($data['proxmox_timeout'] ?? ($current['proxmox_timeout'] ?? '12')),
            'proxmox_connect_timeout' => (string) ($data['proxmox_connect_timeout'] ?? ($current['proxmox_connect_timeout'] ?? '4')),
            'storage_slow_hourly_rate' => number_format($slowHourlyRate, 8, '.', ''),
            'storage_fast_hourly_rate' => number_format($fastHourlyRate, 8, '.', ''),
            'cpu_hourly_rate' => number_format($cpuHourlyRate, 8, '.', ''),
            'memory_hourly_rate' => number_format($memoryHourlyRate, 8, '.', ''),
            // Keep legacy key for backwards compatibility.
            'ip_hourly_rate' => number_format($legacyIpHourlyRate, 8, '.', ''),
            'ip_public_monthly_rate' => number_format($publicIpMonthlyRate, 2, '.', ''),
            'ip_internal_monthly_rate' => number_format($internalIpMonthlyRate, 2, '.', ''),
            'ip_ipv6_only_monthly_rate' => number_format($ipv6OnlyIpMonthlyRate, 2, '.', ''),
        ];

        $this->di['mod_service']('Extension')->setConfig($configToSave);
        $this->di['cache']->delete('config_vps');

        return true;
    }

    public function getProxmoxHost(): string
    {
        $config = $this->getModuleConfig();

        return trim((string) ($config['proxmox_host'] ?? self::DEFAULT_PROXMOX_HOST));
    }

    public function getProxmoxNode(): string
    {
        $config = $this->getModuleConfig();

        return trim((string) ($config['proxmox_node'] ?? self::DEFAULT_PROXMOX_NODE));
    }

    public function getVpsDomain(): string
    {
        return self::DEFAULT_VPS_DOMAIN;
    }

    public function getProxmoxAuthRealm(): string
    {
        $config = $this->getModuleConfig();

        return trim((string) ($config['proxmox_auth_realm'] ?? self::DEFAULT_PROXMOX_AUTH_REALM));
    }

    public function getProxmoxClientRealm(): string
    {
        $config = $this->getModuleConfig();

        return trim((string) ($config['proxmox_client_realm'] ?? self::DEFAULT_PROXMOX_CLIENT_REALM));
    }

    public function isProvisionBillingBypassed(): bool
    {
        $config = $this->getModuleConfig();

        return (bool) ($config['provision_billing_bypass'] ?? false);
    }

    public function hasActiveVpsSubscription(int $clientId): bool
    {
        $count = (int) $this->di['db']->getCell(
            'SELECT COUNT(id) FROM client_order
             WHERE client_id = :client_id
               AND status = :status
               AND service_type = :service_type',
            [
                ':client_id' => $clientId,
                ':status' => \Model_ClientOrder::STATUS_ACTIVE,
                ':service_type' => 'vps',
            ]
        );

        return $count > 0;
    }

    public function getProxmoxUserId(int $clientId): string
    {
        return $clientId . '@' . $this->getProxmoxClientRealm();
    }

    public function getRootProxmox(): Proxmox
    {
        if ($this->rootProxmox instanceof Proxmox) {
            return $this->rootProxmox;
        }

        // Avoid hammering Proxmox auth endpoint repeatedly when credentials are invalid.
        if ($this->rootAuthFailureAt !== null && (microtime(true) - $this->rootAuthFailureAt) < 30) {
            throw new InformationException(
                'Proxmox authentication is temporarily blocked after recent failure: ' . (string) $this->rootAuthFailureMessage,
                [],
                500
            );
        }

        $config = $this->getModuleConfig();
        $this->validateRootAuthConfig($config);
        $hostname = $this->getProxmoxHost();
        $authRealm = $this->getProxmoxAuthRealm();
        $rootUser = trim((string) ($config['proxmox_root_user'] ?? self::DEFAULT_PROXMOX_ROOT_USER));
        $rootIdentity = $this->normalizeAuthIdentity($rootUser, $authRealm);
        $rootTokenId = $this->normalizeTokenId(trim((string) ($config['proxmox_root_token_id'] ?? '')));
        $rootTokenSecret = $this->getEffectiveRootTokenSecret($config);
        $rootPassword = $this->getEffectiveRootPassword($config);

        if ($rootTokenId !== '' && $rootTokenSecret !== '') {
            try {
                $this->rootProxmox = new Proxmox([
                    'hostname' => $hostname,
                    'username' => $rootIdentity['username'] . '!' . $rootTokenId,
                    'password' => $rootTokenSecret,
                    'realm' => $rootIdentity['realm'],
                ], 'array', $this->buildProxmoxHttpClient());

                return $this->rootProxmox;
            } catch (\Throwable $e) {
                $this->rootAuthFailureAt = microtime(true);
                $this->rootAuthFailureMessage = 'token auth failed';
                $this->di['logger']->warning('VPS: root token auth failed, falling back to password auth: ' . $e->getMessage());
            }
        }

        if ($rootPassword === '') {
            throw new InformationException('Proxmox root credentials are not configured for mod_vps', [], 500);
        }

        try {
            $this->rootProxmox = new Proxmox([
                'hostname' => $hostname,
                'username' => $rootIdentity['username'],
                'password' => $rootPassword,
                'realm' => $rootIdentity['realm'],
            ], 'array', $this->buildProxmoxHttpClient());
            $this->rootAuthFailureAt = null;
            $this->rootAuthFailureMessage = null;

            return $this->rootProxmox;
        } catch (\Throwable $e) {
            $this->rootAuthFailureAt = microtime(true);
            $this->rootAuthFailureMessage = 'password auth failed';
            $this->di['logger']->warning('VPS: root password auth failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getRootAuthPreview(): array
    {
        $config = $this->getModuleConfig();
        $authRealm = trim((string) ($config['proxmox_auth_realm'] ?? self::DEFAULT_PROXMOX_AUTH_REALM));
        $rootUser = trim((string) ($config['proxmox_root_user'] ?? self::DEFAULT_PROXMOX_ROOT_USER));
        $identity = $this->normalizeAuthIdentity($rootUser, $authRealm);
        $tokenId = $this->normalizeTokenId(trim((string) ($config['proxmox_root_token_id'] ?? '')));
        $tokenSecret = $this->getEffectiveRootTokenSecret($config);
        $rootPassword = $this->getEffectiveRootPassword($config);

        $warnings = [];
        if ($identity['username'] === '') {
            $warnings[] = 'Root user is empty';
        }

        $hasTokenId = $tokenId !== '';
        $hasTokenSecret = $tokenSecret !== '';
        $hasPassword = $rootPassword !== '';

        if ($hasTokenId xor $hasTokenSecret) {
            $warnings[] = 'Token configuration is incomplete (both token ID and token secret are required)';
        }
        if (!$hasTokenId && !$hasTokenSecret && !$hasPassword) {
            $warnings[] = 'No root authentication configured (provide token pair or password)';
        }

        $method = 'none';
        if ($hasTokenId && $hasTokenSecret) {
            $method = 'token';
        } elseif ($hasPassword) {
            $method = 'password';
        }

        return [
            'valid' => $warnings === [],
            'method' => $method,
            'host' => $this->getProxmoxHost(),
            'node' => $this->getProxmoxNode(),
            'identity' => $identity['username'] . '@' . $identity['realm'],
            'token_id' => $tokenId !== '' ? $tokenId : null,
            'has_token_secret' => $hasTokenSecret,
            'has_password' => $hasPassword,
            'warnings' => $warnings,
        ];
    }

    public function getClientProxmox(int $clientId): Proxmox
    {
        if (isset($this->clientProxmoxCache[$clientId]) && $this->clientProxmoxCache[$clientId] instanceof Proxmox) {
            return $this->clientProxmoxCache[$clientId];
        }

        $token = $this->getStoredApiToken($clientId);
        if (!$token) {
            throw new InformationException('Proxmox API token not configured for this account', [], 500);
        }

        $userid = $this->getProxmoxUserId($clientId);
        $identity = $this->normalizeAuthIdentity($userid, $this->getProxmoxClientRealm());
        $tokenId = $this->normalizeTokenId((string) ($token['token_id'] ?? ''));
        if ($tokenId === '') {
            throw new InformationException('Invalid Proxmox API token ID for this account', [], 500);
        }

        $this->clientProxmoxCache[$clientId] = new Proxmox([
            'hostname' => $this->getProxmoxHost(),
            'username' => $identity['username'] . '!' . $tokenId,
            'password' => $token['secret'],
            'realm' => $identity['realm'],
        ], 'array', $this->buildProxmoxHttpClient());

        return $this->clientProxmoxCache[$clientId];
    }

    public function ensureProxmoxUser(int $clientId): array
    {
        $proxmox = $this->getRootProxmox();
        $userid = $this->getProxmoxUserId($clientId);

        try {
            $proxmox->get('/access/users/' . rawurlencode($userid));
        } catch (\Throwable) {
            $password = $this->generatePassword(24);
            $proxmox->create('/access/users', [
                'userid' => $userid,
                'password' => $password,
                'enable' => 1,
            ]);
        }

        $stored = $this->getStoredApiToken($clientId);
        if ($stored) {
            return $stored;
        }

        $tokenPath = '/access/users/' . rawurlencode($userid) . '/token/' . self::PROXMOX_CLIENT_TOKEN_ID;
        try {
            $proxmox->delete($tokenPath);
        } catch (\Throwable) {
            // token may not exist yet
        }

        $response = $proxmox->create($tokenPath, [
            'privsep' => 1,
        ]);

        $tokenSecret = $response['data']['value'] ?? null;
        if (!$tokenSecret) {
            throw new InformationException('Failed to create Proxmox API token', [], 500);
        }
        $token = [
            'userid' => $userid,
            'token_id' => self::PROXMOX_CLIENT_TOKEN_ID,
            'secret' => $tokenSecret,
        ];
        $this->storeApiToken($clientId, $token);

        return $token;
    }

    public function getClientVmIds(int $clientId): array
    {
        $userid = $this->getProxmoxUserId($clientId);

        try {
            $proxmox = $this->getClientProxmox($clientId);
            $result = $proxmox->get('/access/permissions', ['userid' => $userid]);
        } catch (\Throwable) {
            try {
                $proxmox = $this->getRootProxmox();
                $result = $proxmox->get('/access/permissions', ['userid' => $userid]);
            } catch (\Throwable $e) {
                $this->di['logger']->warning('VPS: unable to fetch Proxmox permissions for client ' . $clientId . ': ' . $e->getMessage());
                $result = ['data' => []];
            }
        }

        $vms = [];
        foreach ($result['data'] ?? [] as $key => $val) {
            if (preg_match('#^/vms/(\d+)$#', (string) $key, $matches)) {
                $vms[] = $matches[1];
            }
        }

        if ($vms === []) {
            $vms = $this->getClientVmIdsFromDb($clientId);
        }

        sort($vms, SORT_NUMERIC);

        return array_values(array_unique($vms));
    }

    private function normalizeAuthIdentity(string $userValue, string $defaultRealm): array
    {
        $value = trim($userValue);
        if ($value === '') {
            return ['username' => '', 'realm' => $defaultRealm];
        }

        if (str_contains($value, '!')) {
            [$value] = explode('!', $value, 2);
        }

        if (str_contains($value, '@')) {
            [$username, $realm] = explode('@', $value, 2);
            $username = trim($username);
            $realm = trim($realm);

            return [
                'username' => $username,
                'realm' => $realm !== '' ? $realm : $defaultRealm,
            ];
        }

        return [
            'username' => $value,
            'realm' => $defaultRealm,
        ];
    }

    private function normalizeTokenId(string $tokenId): string
    {
        $value = trim($tokenId);
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '!')) {
            [, $value] = explode('!', $value, 2);
            $value = trim($value);
        }

        return $value;
    }

    private function validateRootAuthConfig(array $config): void
    {
        $authRealm = trim((string) ($config['proxmox_auth_realm'] ?? self::DEFAULT_PROXMOX_AUTH_REALM));
        $rootUser = trim((string) ($config['proxmox_root_user'] ?? self::DEFAULT_PROXMOX_ROOT_USER));
        $identity = $this->normalizeAuthIdentity($rootUser, $authRealm);
        $tokenId = $this->normalizeTokenId(trim((string) ($config['proxmox_root_token_id'] ?? '')));
        $tokenSecret = $this->getEffectiveRootTokenSecret($config);
        $rootPassword = $this->getEffectiveRootPassword($config);

        if ($identity['username'] === '') {
            throw new InformationException('Root authentication is incomplete: root user is required', [], 400);
        }

        if (($tokenId !== '' && $tokenSecret === '') || ($tokenId === '' && $tokenSecret !== '')) {
            throw new InformationException(
                'Root token configuration is incomplete: provide both token ID and token secret, or clear both',
                [],
                400
            );
        }

        if ($tokenId === '' && $tokenSecret === '' && $rootPassword === '') {
            throw new InformationException(
                'Root authentication is incomplete: configure token ID + token secret, or set root password',
                [],
                400
            );
        }
    }

    private function buildProxmoxHttpClient(): GuzzleClient
    {
        $config = $this->getModuleConfig();
        $timeout = (float) ($config['proxmox_timeout'] ?? 12);
        $connectTimeout = (float) ($config['proxmox_connect_timeout'] ?? 4);

        return new GuzzleClient([
            'timeout' => max(2.0, $timeout),
            'connect_timeout' => max(1.0, $connectTimeout),
            'verify' => true,
            'http_errors' => false,
        ]);
    }

    public function clientOwnsVm(int $clientId, int $vmid): bool
    {
        return in_array((string) $vmid, $this->getClientVmIds($clientId), true);
    }

    public function clientOwnsContainer(int $clientId, int $ctid): bool
    {
        $count = (int) $this->di['db']->getCell(
            'SELECT COUNT(*) FROM mod_vps_container WHERE client_id = :client_id AND ctid = :ctid',
            [':client_id' => $clientId, ':ctid' => $ctid]
        );

        return $count > 0;
    }

    public function requestProvisionVm(\Model_Client $client, array $data): array
    {
        $this->ensureBillingSchema();
        $request = $this->normalizeProvisionRequestData($data);
        $monthlyCost = $this->calculateMonthlyCost(
            $request['vm_cpu'],
            $request['vm_mem'],
            $request['vm_disk_size'],
            $request['vm_disk_type'],
            (string) ($request['network_mode'] ?? self::NETWORK_MODE_PUBLIC)
        );
        $this->assertProvisionEligibility($client, $monthlyCost, 'provision');

        $now = date('Y-m-d H:i:s');
        $task = $this->di['db']->dispense('mod_vps_provision_task');
        $task->client_id = (int) $client->id;
        $task->status = 'queued';
        $task->payload = json_encode($request);
        $task->result = null;
        $task->error_message = null;
        $task->vmid = null;
        $task->worker_token = bin2hex(random_bytes(32));
        $task->started_at = null;
        $task->finished_at = null;
        $task->created_at = $now;
        $task->updated_at = $now;
        $taskId = (int) $this->di['db']->store($task);

        $this->dispatchProvisionTask($taskId, (string) $task->worker_token);

        return [
            'task_id' => $taskId,
            'status' => 'queued',
            'message' => 'Provisioning started in background',
            'can_close_page' => true,
        ];
    }

    public function getProvisionTaskStatusForClient(int $clientId, int $taskId): array
    {
        $task = $this->di['db']->getRow(
            'SELECT id, status, result, error_message, vmid, created_at, updated_at, started_at, finished_at
             FROM mod_vps_provision_task
             WHERE id = :id AND client_id = :client_id
             LIMIT 1',
            [':id' => $taskId, ':client_id' => $clientId]
        );
        if (!$task) {
            throw new InformationException('Provision task not found', [], 404);
        }

        $result = [];
        if (!empty($task['result'])) {
            $decoded = json_decode((string) $task['result'], true);
            if (is_array($decoded)) {
                $result = [
                    'vmid' => isset($decoded['vmid']) ? (int) $decoded['vmid'] : null,
                    'name' => (string) ($decoded['name'] ?? ''),
                    'ip_address' => isset($decoded['ip_address']) ? (string) $decoded['ip_address'] : null,
                ];
            }
        }

        return [
            'task_id' => (int) $task['id'],
            'status' => (string) $task['status'],
            'vmid' => isset($task['vmid']) ? (int) $task['vmid'] : null,
            'result' => $result,
            'error_message' => (string) ($task['error_message'] ?? ''),
            'created_at' => (string) ($task['created_at'] ?? ''),
            'updated_at' => (string) ($task['updated_at'] ?? ''),
            'started_at' => (string) ($task['started_at'] ?? ''),
            'finished_at' => (string) ($task['finished_at'] ?? ''),
        ];
    }

    public function runProvisionTaskByToken(int $taskId, string $workerToken): array
    {
        $task = $this->di['db']->getRow(
            'SELECT id, client_id, status, payload, worker_token
             FROM mod_vps_provision_task
             WHERE id = :id
             LIMIT 1',
            [':id' => $taskId]
        );
        if (!$task || !hash_equals((string) ($task['worker_token'] ?? ''), $workerToken)) {
            throw new InformationException('Invalid provisioning task token', [], 403);
        }

        if (($task['status'] ?? '') === 'completed') {
            return ['ok' => true, 'status' => 'completed'];
        }
        if (($task['status'] ?? '') === 'running') {
            return ['ok' => true, 'status' => 'running'];
        }

        $now = date('Y-m-d H:i:s');
        $updated = $this->di['db']->exec(
            'UPDATE mod_vps_provision_task
             SET status = :status, started_at = :started_at, updated_at = :updated_at
             WHERE id = :id AND worker_token = :worker_token AND status = :queued_status',
            [
                ':status' => 'running',
                ':started_at' => $now,
                ':updated_at' => $now,
                ':id' => $taskId,
                ':worker_token' => $workerToken,
                ':queued_status' => 'queued',
            ]
        );
        if ((int) $updated === 0) {
            $fresh = $this->di['db']->getCell(
                'SELECT status FROM mod_vps_provision_task WHERE id = :id LIMIT 1',
                [':id' => $taskId]
            );

            return ['ok' => true, 'status' => (string) $fresh];
        }

        $payload = json_decode((string) ($task['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            $this->markProvisionTaskFailed($taskId, 'Provisioning payload is invalid');
            return ['ok' => false, 'status' => 'failed'];
        }

        $client = $this->di['db']->findOne('Client', 'id = :id', [':id' => (int) $task['client_id']]);
        if (!$client instanceof \Model_Client) {
            $this->markProvisionTaskFailed($taskId, 'Client account was not found');
            return ['ok' => false, 'status' => 'failed'];
        }

        try {
            $result = $this->provisionVm($client, $payload);
            $finishedAt = date('Y-m-d H:i:s');
            $this->di['db']->exec(
                'UPDATE mod_vps_provision_task
                 SET status = :status, result = :result, vmid = :vmid, finished_at = :finished_at, updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':status' => 'completed',
                    ':result' => json_encode($result),
                    ':vmid' => isset($result['vmid']) ? (int) $result['vmid'] : null,
                    ':finished_at' => $finishedAt,
                    ':updated_at' => $finishedAt,
                    ':id' => $taskId,
                ]
            );

            return ['ok' => true, 'status' => 'completed'];
        } catch (\Throwable $e) {
            $this->markProvisionTaskFailed($taskId, $e->getMessage());

            return ['ok' => false, 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function getVmAccessDetails(int $clientId, int $vmid): array
    {
        $this->ensureBillingSchema();
        $this->assertClientOwnsVm($clientId, $vmid);
        $record = $this->di['db']->getRow(
            'SELECT vmid, vm_name, ip_address, root_password
             FROM mod_vps_vm
             WHERE client_id = :client_id AND vmid = :vmid
             LIMIT 1',
            [':client_id' => $clientId, ':vmid' => $vmid]
        );
        if (!$record) {
            throw new InformationException('VM record not found', [], 404);
        }

        $ipAddress = !empty($record['ip_address']) ? (string) $record['ip_address'] : null;
        try {
            $proxmox = $this->getRootProxmox();
            $node = $this->getVmNode($proxmox, $vmid);
            $liveIp = $this->getVmIpAddress($proxmox, $node, $vmid);
            if ($liveIp !== null) {
                $ipAddress = $liveIp;
                $this->di['db']->exec(
                    'UPDATE mod_vps_vm SET ip_address = :ip_address, updated_at = :updated_at WHERE vmid = :vmid',
                    [':ip_address' => $liveIp, ':updated_at' => date('Y-m-d H:i:s'), ':vmid' => $vmid]
                );
            }
        } catch (\Throwable) {
            // Keep stored IP when live lookup is unavailable.
        }

        $rootPassword = null;
        if (!empty($record['root_password'])) {
            try {
                $rootPassword = (string) $this->di['crypt']->decrypt((string) $record['root_password'], Config::getProperty('info.salt'));
            } catch (\Throwable) {
                $rootPassword = null;
            }
        }

        return [
            'vmid' => (int) $record['vmid'],
            'name' => (string) ($record['vm_name'] ?? ('vm-' . $vmid)),
            'ip_address' => $ipAddress,
            'root_password' => $rootPassword,
            'has_root_password' => $rootPassword !== null && $rootPassword !== '',
        ];
    }

    public function getClientContainerOverview(int $clientId): array
    {
        $this->ensureBillingSchema();
        $rows = $this->di['db']->getAll(
            'SELECT ctid, container_name, template, storage, network_mode, ip_address, power_state, created_at
             FROM mod_vps_container
             WHERE client_id = :client_id
             ORDER BY ctid ASC',
            [':client_id' => $clientId]
        );
        if ($rows === []) {
            return [];
        }

        $overview = [];
        foreach ($rows as $row) {
            $ctid = (int) ($row['ctid'] ?? 0);
            $overview[$ctid] = [
                'ctid' => $ctid,
                'name' => (string) ($row['container_name'] ?? ('ct-' . $ctid)),
                'template' => (string) ($row['template'] ?? ''),
                'storage' => (string) ($row['storage'] ?? ''),
                'network_mode' => (string) ($row['network_mode'] ?? ''),
                'ip_address' => !empty($row['ip_address']) ? (string) $row['ip_address'] : null,
                'status' => (string) ($row['power_state'] ?? 'unknown'),
                'uptime_hours' => null,
                'memory_mb' => null,
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }

        try {
            $proxmox = $this->getRootProxmox();
            $resources = $proxmox->get('/cluster/resources');
            foreach (($resources['data'] ?? []) as $resource) {
                if (($resource['type'] ?? '') !== 'lxc') {
                    continue;
                }
                $ctid = (int) ($resource['vmid'] ?? 0);
                if (!isset($overview[$ctid])) {
                    continue;
                }

                if (!empty($resource['name'])) {
                    $overview[$ctid]['name'] = (string) $resource['name'];
                }
                $overview[$ctid]['status'] = (string) (($resource['status'] ?? '') ?: $overview[$ctid]['status']);
                if (isset($resource['uptime'])) {
                    $overview[$ctid]['uptime_hours'] = round(((int) $resource['uptime']) / 3600, 2);
                }
                if (isset($resource['maxmem'])) {
                    $overview[$ctid]['memory_mb'] = (int) round(((int) $resource['maxmem']) / 1048576);
                }
                $previousIp = (string) ($overview[$ctid]['ip_address'] ?? '');
                $resourceIp = $this->sanitizeIpv4Address((string) ($resource['ip'] ?? ''));
                if ($resourceIp !== null) {
                    $overview[$ctid]['ip_address'] = $resourceIp;
                }

                // Refresh missing IPs from live container interfaces so the list is actionable.
                if (empty($overview[$ctid]['ip_address']) && (($overview[$ctid]['status'] ?? '') === 'running')) {
                    $node = (string) ($resource['node'] ?? '');
                    if ($node !== '') {
                        $liveIp = $this->getContainerIpAddress($proxmox, $node, $ctid);
                        if ($liveIp !== null) {
                            $overview[$ctid]['ip_address'] = $liveIp;
                        }
                    }
                }

                $resolvedIp = (string) ($overview[$ctid]['ip_address'] ?? '');
                if ($resolvedIp !== '' && $resolvedIp !== $previousIp) {
                    $this->di['db']->exec(
                        'UPDATE mod_vps_container SET ip_address = :ip_address, updated_at = :updated_at WHERE ctid = :ctid',
                        [':ip_address' => $resolvedIp, ':updated_at' => date('Y-m-d H:i:s'), ':ctid' => $ctid]
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->di['logger']->warning('VPS: unable to load live container overview data: ' . $e->getMessage());
        }

        return array_values($overview);
    }

    public function getContainerAccessDetails(int $clientId, int $ctid): array
    {
        $this->ensureBillingSchema();
        $this->assertClientOwnsContainer($clientId, $ctid);
        $record = $this->di['db']->getRow(
            'SELECT ctid, container_name, ip_address, root_password, template, network_mode
             FROM mod_vps_container
             WHERE client_id = :client_id AND ctid = :ctid
             LIMIT 1',
            [':client_id' => $clientId, ':ctid' => $ctid]
        );
        if (!$record) {
            throw new InformationException('Container record not found', [], 404);
        }

        $ipAddress = !empty($record['ip_address']) ? (string) $record['ip_address'] : null;
        try {
            $proxmox = $this->getRootProxmox();
            $node = $this->getContainerNode($proxmox, $ctid);
            $liveIp = $this->waitForContainerIpAddress($proxmox, $node, $ctid, 45);
            if ($liveIp !== null) {
                $ipAddress = $liveIp;
                $this->di['db']->exec(
                    'UPDATE mod_vps_container SET ip_address = :ip_address, updated_at = :updated_at WHERE ctid = :ctid',
                    [':ip_address' => $liveIp, ':updated_at' => date('Y-m-d H:i:s'), ':ctid' => $ctid]
                );
            }
        } catch (\Throwable) {
        }

        $rootPassword = null;
        if (!empty($record['root_password'])) {
            try {
                $rootPassword = (string) $this->di['crypt']->decrypt((string) $record['root_password'], Config::getProperty('info.salt'));
            } catch (\Throwable) {
                $rootPassword = null;
            }
        }

        $accessHints = $this->buildContainerTemplateAccessHints(
            (string) ($record['template'] ?? ''),
            $ipAddress,
            (string) ($record['template'] ?? '')
        );

        return [
            'ctid' => (int) $record['ctid'],
            'name' => (string) ($record['container_name'] ?? ('ct-' . $ctid)),
            'template' => (string) ($record['template'] ?? ''),
            'network_mode' => (string) ($record['network_mode'] ?? ''),
            'ip_address' => $ipAddress,
            'root_password' => $rootPassword,
            'has_root_password' => $rootPassword !== null && $rootPassword !== '',
            'application_url' => $accessHints['likely_web_url'] ?? null,
            'admin_url' => $accessHints['likely_admin_url'] ?? null,
            'ssh_command' => $accessHints['ssh_command'] ?? null,
            'access_hints' => $accessHints,
        ];
    }

    public function runQueuedProvisionTasks(int $limit = 5): array
    {
        $this->ensureBillingSchema();
        $safeLimit = max(1, min(20, $limit));
        $rows = $this->di['db']->getAll(
            'SELECT id, worker_token
             FROM mod_vps_provision_task
             WHERE status = :status
             ORDER BY id ASC
             LIMIT ' . $safeLimit,
            [':status' => 'queued']
        );

        $processed = 0;
        foreach ($rows as $row) {
            try {
                $this->runProvisionTaskByToken((int) $row['id'], (string) $row['worker_token']);
                ++$processed;
            } catch (\Throwable $e) {
                $this->di['logger']->warning('VPS: queued task #%s failed in cron: %s', (int) $row['id'], $e->getMessage());
            }
        }

        return [
            'queued' => count($rows),
            'processed' => $processed,
        ];
    }

    public function requestDeleteVm(int $clientId, int $vmid): array
    {
        $this->ensureBillingSchema();
        $this->assertClientOwnsVm($clientId, $vmid);

        $vmName = (string) $this->di['db']->getCell(
            'SELECT vm_name FROM mod_vps_vm WHERE client_id = :client_id AND vmid = :vmid LIMIT 1',
            [':client_id' => $clientId, ':vmid' => $vmid]
        );
        if ($vmName === '') {
            $vmName = 'VM #' . $vmid;
        }

        $now = date('Y-m-d H:i:s');
        $task = $this->di['db']->dispense('mod_vps_delete_task');
        $task->client_id = $clientId;
        $task->vmid = $vmid;
        $task->vm_name = $vmName;
        $task->status = 'queued';
        $task->result = null;
        $task->error_message = null;
        $task->worker_token = bin2hex(random_bytes(32));
        $task->started_at = null;
        $task->finished_at = null;
        $task->created_at = $now;
        $task->updated_at = $now;
        $taskId = (int) $this->di['db']->store($task);

        $this->dispatchDeleteTask($taskId, (string) $task->worker_token);

        return [
            'task_id' => $taskId,
            'status' => 'queued',
            'vmid' => $vmid,
            'vm_name' => $vmName,
            'message' => 'Delete VM started in background',
            'can_close_page' => true,
        ];
    }

    public function getDeleteTaskStatusForClient(int $clientId, int $taskId): array
    {
        $task = $this->di['db']->getRow(
            'SELECT id, client_id, vmid, vm_name, status, result, error_message, created_at, updated_at, started_at, finished_at
             FROM mod_vps_delete_task
             WHERE id = :id AND client_id = :client_id
             LIMIT 1',
            [':id' => $taskId, ':client_id' => $clientId]
        );
        if (!$task) {
            throw new InformationException('Delete task not found', [], 404);
        }

        $result = [];
        if (!empty($task['result'])) {
            $decoded = json_decode((string) $task['result'], true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }

        return [
            'task_id' => (int) $task['id'],
            'client_id' => (int) $task['client_id'],
            'vmid' => (int) $task['vmid'],
            'vm_name' => (string) ($task['vm_name'] ?? ''),
            'status' => (string) ($task['status'] ?? ''),
            'result' => $result,
            'error_message' => (string) ($task['error_message'] ?? ''),
            'created_at' => (string) ($task['created_at'] ?? ''),
            'updated_at' => (string) ($task['updated_at'] ?? ''),
            'started_at' => (string) ($task['started_at'] ?? ''),
            'finished_at' => (string) ($task['finished_at'] ?? ''),
        ];
    }

    public function runDeleteTaskByToken(int $taskId, string $workerToken): array
    {
        $task = $this->di['db']->getRow(
            'SELECT id, client_id, vmid, status, worker_token
             FROM mod_vps_delete_task
             WHERE id = :id
             LIMIT 1',
            [':id' => $taskId]
        );
        if (!$task || !hash_equals((string) ($task['worker_token'] ?? ''), $workerToken)) {
            throw new InformationException('Invalid delete task token', [], 403);
        }

        if (($task['status'] ?? '') === 'completed') {
            return ['ok' => true, 'status' => 'completed'];
        }
        if (($task['status'] ?? '') === 'running') {
            return ['ok' => true, 'status' => 'running'];
        }

        $now = date('Y-m-d H:i:s');
        $updated = $this->di['db']->exec(
            'UPDATE mod_vps_delete_task
             SET status = :status, started_at = :started_at, updated_at = :updated_at
             WHERE id = :id AND worker_token = :worker_token AND status = :queued_status',
            [
                ':status' => 'running',
                ':started_at' => $now,
                ':updated_at' => $now,
                ':id' => $taskId,
                ':worker_token' => $workerToken,
                ':queued_status' => 'queued',
            ]
        );
        if ((int) $updated === 0) {
            $fresh = $this->di['db']->getCell(
                'SELECT status FROM mod_vps_delete_task WHERE id = :id LIMIT 1',
                [':id' => $taskId]
            );

            return ['ok' => true, 'status' => (string) $fresh];
        }

        try {
            $this->deleteVm((int) $task['client_id'], (int) $task['vmid']);
            $finishedAt = date('Y-m-d H:i:s');
            $this->di['db']->exec(
                'UPDATE mod_vps_delete_task
                 SET status = :status, result = :result, finished_at = :finished_at, updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':status' => 'completed',
                    ':result' => json_encode(['ok' => true, 'vmid' => (int) $task['vmid']]),
                    ':finished_at' => $finishedAt,
                    ':updated_at' => $finishedAt,
                    ':id' => $taskId,
                ]
            );

            return ['ok' => true, 'status' => 'completed'];
        } catch (\Throwable $e) {
            $this->markDeleteTaskFailed($taskId, $e->getMessage());

            return ['ok' => false, 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function runQueuedDeleteTasks(int $limit = 5): array
    {
        $this->ensureBillingSchema();
        $safeLimit = max(1, min(20, $limit));
        $rows = $this->di['db']->getAll(
            'SELECT id, worker_token
             FROM mod_vps_delete_task
             WHERE status = :status
             ORDER BY id ASC
             LIMIT ' . $safeLimit,
            [':status' => 'queued']
        );

        $processed = 0;
        foreach ($rows as $row) {
            try {
                $this->runDeleteTaskByToken((int) $row['id'], (string) $row['worker_token']);
                ++$processed;
            } catch (\Throwable $e) {
                $this->di['logger']->warning('VPS: queued delete task #%s failed in cron: %s', (int) $row['id'], $e->getMessage());
            }
        }

        return [
            'queued' => count($rows),
            'processed' => $processed,
        ];
    }

    public function requestTakeSnapshot(int $clientId, int $vmid): array
    {
        $this->ensureBillingSchema();
        $this->assertClientOwnsVm($clientId, $vmid);

        $snapshotName = 'snap-' . date('Ymd-His');
        $vmName = (string) $this->di['db']->getCell(
            'SELECT vm_name FROM mod_vps_vm WHERE client_id = :client_id AND vmid = :vmid LIMIT 1',
            [':client_id' => $clientId, ':vmid' => $vmid]
        );

        $now = date('Y-m-d H:i:s');
        $task = $this->di['db']->dispense('mod_vps_snapshot_task');
        $task->client_id = $clientId;
        $task->vmid = $vmid;
        $task->snapshot_name = $snapshotName;
        $task->action = 'create';
        $task->payload = json_encode(['snapshot_name' => $snapshotName]);
        $task->status = 'queued';
        $task->result = null;
        $task->error_message = null;
        $task->worker_token = bin2hex(random_bytes(32));
        $task->started_at = null;
        $task->finished_at = null;
        $task->created_at = $now;
        $task->updated_at = $now;
        $taskId = (int) $this->di['db']->store($task);

        $this->dispatchSnapshotTask($taskId, (string) $task->worker_token);

        return [
            'task_id' => $taskId,
            'status' => 'queued',
            'action' => 'create',
            'vmid' => $vmid,
            'vm_name' => $vmName !== '' ? $vmName : ('VM #' . $vmid),
            'snapshot_name' => $snapshotName,
            'message' => 'Snapshot creation started in background',
            'can_close_page' => true,
        ];
    }

    public function requestRestoreSnapshot(int $clientId, int $vmid, string $snapshotName): array
    {
        $this->ensureBillingSchema();
        $this->assertClientOwnsVm($clientId, $vmid);
        $snapshotName = trim($snapshotName);
        if ($snapshotName === '') {
            throw new InformationException('Snapshot name is required', [], 400);
        }

        $exists = (int) $this->di['db']->getCell(
            'SELECT COUNT(*) FROM mod_vps_snapshot
             WHERE client_id = :client_id AND vmid = :vmid AND snapshot_name = :snapshot_name AND status = :status',
            [
                ':client_id' => $clientId,
                ':vmid' => $vmid,
                ':snapshot_name' => $snapshotName,
                ':status' => 'active',
            ]
        );
        if ($exists === 0) {
            throw new InformationException('Snapshot not found for this VM', [], 404);
        }

        $now = date('Y-m-d H:i:s');
        $task = $this->di['db']->dispense('mod_vps_snapshot_task');
        $task->client_id = $clientId;
        $task->vmid = $vmid;
        $task->snapshot_name = $snapshotName;
        $task->action = 'restore';
        $task->payload = json_encode(['snapshot_name' => $snapshotName]);
        $task->status = 'queued';
        $task->result = null;
        $task->error_message = null;
        $task->worker_token = bin2hex(random_bytes(32));
        $task->started_at = null;
        $task->finished_at = null;
        $task->created_at = $now;
        $task->updated_at = $now;
        $taskId = (int) $this->di['db']->store($task);

        $this->dispatchSnapshotTask($taskId, (string) $task->worker_token);

        return [
            'task_id' => $taskId,
            'status' => 'queued',
            'action' => 'restore',
            'vmid' => $vmid,
            'snapshot_name' => $snapshotName,
            'message' => 'Snapshot restore started in background',
            'can_close_page' => true,
        ];
    }

    public function getSnapshotTaskStatusForClient(int $clientId, int $taskId): array
    {
        $task = $this->di['db']->getRow(
            'SELECT id, client_id, vmid, snapshot_name, action, status, result, error_message, created_at, updated_at, started_at, finished_at
             FROM mod_vps_snapshot_task
             WHERE id = :id AND client_id = :client_id
             LIMIT 1',
            [':id' => $taskId, ':client_id' => $clientId]
        );
        if (!$task) {
            throw new InformationException('Snapshot task not found', [], 404);
        }

        $result = [];
        if (!empty($task['result'])) {
            $decoded = json_decode((string) $task['result'], true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }

        return [
            'task_id' => (int) $task['id'],
            'client_id' => (int) $task['client_id'],
            'vmid' => (int) $task['vmid'],
            'snapshot_name' => (string) ($task['snapshot_name'] ?? ''),
            'action' => (string) ($task['action'] ?? ''),
            'status' => (string) ($task['status'] ?? ''),
            'result' => $result,
            'error_message' => (string) ($task['error_message'] ?? ''),
            'created_at' => (string) ($task['created_at'] ?? ''),
            'updated_at' => (string) ($task['updated_at'] ?? ''),
            'started_at' => (string) ($task['started_at'] ?? ''),
            'finished_at' => (string) ($task['finished_at'] ?? ''),
        ];
    }

    public function runSnapshotTaskByToken(int $taskId, string $workerToken): array
    {
        $task = $this->di['db']->getRow(
            'SELECT id, client_id, vmid, snapshot_name, action, status, payload, worker_token
             FROM mod_vps_snapshot_task
             WHERE id = :id
             LIMIT 1',
            [':id' => $taskId]
        );
        if (!$task || !hash_equals((string) ($task['worker_token'] ?? ''), $workerToken)) {
            throw new InformationException('Invalid snapshot task token', [], 403);
        }

        if (($task['status'] ?? '') === 'completed') {
            return ['ok' => true, 'status' => 'completed'];
        }
        if (($task['status'] ?? '') === 'running') {
            return ['ok' => true, 'status' => 'running'];
        }

        $now = date('Y-m-d H:i:s');
        $updated = $this->di['db']->exec(
            'UPDATE mod_vps_snapshot_task
             SET status = :status, started_at = :started_at, updated_at = :updated_at
             WHERE id = :id AND worker_token = :worker_token AND status = :queued_status',
            [
                ':status' => 'running',
                ':started_at' => $now,
                ':updated_at' => $now,
                ':id' => $taskId,
                ':worker_token' => $workerToken,
                ':queued_status' => 'queued',
            ]
        );
        if ((int) $updated === 0) {
            $fresh = $this->di['db']->getCell(
                'SELECT status FROM mod_vps_snapshot_task WHERE id = :id LIMIT 1',
                [':id' => $taskId]
            );

            return ['ok' => true, 'status' => (string) $fresh];
        }

        $action = (string) ($task['action'] ?? '');
        $snapshotName = (string) ($task['snapshot_name'] ?? '');

        try {
            $result = [];
            if ($action === 'create') {
                $result = $this->createVmSnapshot((int) $task['client_id'], (int) $task['vmid'], $snapshotName);
            } elseif ($action === 'restore') {
                $result = $this->restoreVmSnapshot((int) $task['client_id'], (int) $task['vmid'], $snapshotName);
            } else {
                throw new InformationException('Unsupported snapshot task action', [], 400);
            }

            $finishedAt = date('Y-m-d H:i:s');
            $this->di['db']->exec(
                'UPDATE mod_vps_snapshot_task
                 SET status = :status, result = :result, finished_at = :finished_at, updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':status' => 'completed',
                    ':result' => json_encode($result),
                    ':finished_at' => $finishedAt,
                    ':updated_at' => $finishedAt,
                    ':id' => $taskId,
                ]
            );

            return ['ok' => true, 'status' => 'completed'];
        } catch (\Throwable $e) {
            $this->markSnapshotTaskFailed($taskId, $e->getMessage());

            return ['ok' => false, 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function runQueuedSnapshotTasks(int $limit = 5): array
    {
        $this->ensureBillingSchema();
        $safeLimit = max(1, min(20, $limit));
        $rows = $this->di['db']->getAll(
            'SELECT id, worker_token
             FROM mod_vps_snapshot_task
             WHERE status = :status
             ORDER BY id ASC
             LIMIT ' . $safeLimit,
            [':status' => 'queued']
        );

        $processed = 0;
        foreach ($rows as $row) {
            try {
                $this->runSnapshotTaskByToken((int) $row['id'], (string) $row['worker_token']);
                ++$processed;
            } catch (\Throwable $e) {
                $this->di['logger']->warning('VPS: queued snapshot task #%s failed in cron: %s', (int) $row['id'], $e->getMessage());
            }
        }

        return [
            'queued' => count($rows),
            'processed' => $processed,
        ];
    }

    public function getContainerTemplates(string $storage = self::STORAGE_SLOW): array
    {
        $storage = trim($storage) !== '' ? trim($storage) : self::STORAGE_SLOW;
        try {
            $node = $this->getProxmoxNode();
            $proxmox = $this->getRootProxmox();
            $response = $proxmox->get("/nodes/$node/storage/" . rawurlencode($storage) . '/content', [
                'content' => 'vztmpl',
            ]);
        } catch (\Throwable $e) {
            $this->di['logger']->warning('VPS: unable to load container templates from storage ' . $storage . ': ' . $e->getMessage());
            return [];
        }

        $templates = [];
        foreach (($response['data'] ?? []) as $item) {
            $volid = (string) ($item['volid'] ?? '');
            if ($volid === '' || !str_contains($volid, 'vztmpl/')) {
                continue;
            }
            $name = (string) ($item['text'] ?? basename($volid));
            $displayName = $name !== '' ? $name : basename($volid);
            $templates[] = [
                'volid' => $volid,
                'name' => $displayName,
                'access_hints' => $this->buildContainerTemplateAccessHints($displayName, null, $volid),
            ];
        }

        usort($templates, static function (array $a, array $b): int {
            return strcmp((string) $a['name'], (string) $b['name']);
        });

        return $templates;
    }

    public function requestProvisionContainer(\Model_Client $client, array $data): array
    {
        $this->ensureBillingSchema();
        $request = $this->normalizeContainerRequestData($data);
        $monthlyCost = $this->calculateContainerMonthlyCost($request['ct_disk_size'], $request['ct_disk_type'], (string) ($request['network_mode'] ?? self::NETWORK_MODE_PUBLIC));
        $this->assertProvisionEligibility($client, $monthlyCost, 'container provision');

        $now = date('Y-m-d H:i:s');
        $task = $this->di['db']->dispense('mod_vps_container_task');
        $task->client_id = (int) $client->id;
        $task->status = 'queued';
        $task->payload = json_encode($request);
        $task->result = null;
        $task->error_message = null;
        $task->ctid = null;
        $task->worker_token = bin2hex(random_bytes(32));
        $task->started_at = null;
        $task->finished_at = null;
        $task->created_at = $now;
        $task->updated_at = $now;
        $taskId = (int) $this->di['db']->store($task);

        $this->dispatchContainerTask($taskId, (string) $task->worker_token);

        return [
            'task_id' => $taskId,
            'status' => 'queued',
            'monthly_cost' => $monthlyCost,
            'message' => 'Container provisioning started in background',
            'can_close_page' => true,
        ];
    }

    public function getContainerProvisionTaskStatusForClient(int $clientId, int $taskId): array
    {
        $task = $this->di['db']->getRow(
            'SELECT id, status, result, error_message, ctid, created_at, updated_at, started_at, finished_at
             FROM mod_vps_container_task
             WHERE id = :id AND client_id = :client_id
             LIMIT 1',
            [':id' => $taskId, ':client_id' => $clientId]
        );
        if (!$task) {
            throw new InformationException('Container provision task not found', [], 404);
        }

        $result = [];
        if (!empty($task['result'])) {
            $decoded = json_decode((string) $task['result'], true);
            if (is_array($decoded)) {
                $result = $decoded;
            }
        }

        return [
            'task_id' => (int) $task['id'],
            'status' => (string) $task['status'],
            'ctid' => isset($task['ctid']) ? (int) $task['ctid'] : null,
            'result' => $result,
            'error_message' => (string) ($task['error_message'] ?? ''),
            'created_at' => (string) ($task['created_at'] ?? ''),
            'updated_at' => (string) ($task['updated_at'] ?? ''),
            'started_at' => (string) ($task['started_at'] ?? ''),
            'finished_at' => (string) ($task['finished_at'] ?? ''),
        ];
    }

    public function runContainerTaskByToken(int $taskId, string $workerToken): array
    {
        $task = $this->di['db']->getRow(
            'SELECT id, client_id, status, payload, worker_token
             FROM mod_vps_container_task
             WHERE id = :id
             LIMIT 1',
            [':id' => $taskId]
        );
        if (!$task || !hash_equals((string) ($task['worker_token'] ?? ''), $workerToken)) {
            throw new InformationException('Invalid container task token', [], 403);
        }

        if (($task['status'] ?? '') === 'completed') {
            return ['ok' => true, 'status' => 'completed'];
        }
        if (($task['status'] ?? '') === 'running') {
            return ['ok' => true, 'status' => 'running'];
        }

        $now = date('Y-m-d H:i:s');
        $updated = $this->di['db']->exec(
            'UPDATE mod_vps_container_task
             SET status = :status, started_at = :started_at, updated_at = :updated_at
             WHERE id = :id AND worker_token = :worker_token AND status = :queued_status',
            [
                ':status' => 'running',
                ':started_at' => $now,
                ':updated_at' => $now,
                ':id' => $taskId,
                ':worker_token' => $workerToken,
                ':queued_status' => 'queued',
            ]
        );
        if ((int) $updated === 0) {
            $fresh = $this->di['db']->getCell(
                'SELECT status FROM mod_vps_container_task WHERE id = :id LIMIT 1',
                [':id' => $taskId]
            );

            return ['ok' => true, 'status' => (string) $fresh];
        }

        $payload = json_decode((string) ($task['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            $this->markContainerTaskFailed($taskId, 'Container provisioning payload is invalid');
            return ['ok' => false, 'status' => 'failed'];
        }

        $client = $this->di['db']->findOne('Client', 'id = :id', [':id' => (int) $task['client_id']]);
        if (!$client instanceof \Model_Client) {
            $this->markContainerTaskFailed($taskId, 'Client account was not found');
            return ['ok' => false, 'status' => 'failed'];
        }

        try {
            $result = $this->provisionContainer($client, $payload);
            $finishedAt = date('Y-m-d H:i:s');
            $this->di['db']->exec(
                'UPDATE mod_vps_container_task
                 SET status = :status, result = :result, ctid = :ctid, finished_at = :finished_at, updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':status' => 'completed',
                    ':result' => json_encode($result),
                    ':ctid' => isset($result['ctid']) ? (int) $result['ctid'] : null,
                    ':finished_at' => $finishedAt,
                    ':updated_at' => $finishedAt,
                    ':id' => $taskId,
                ]
            );

            return ['ok' => true, 'status' => 'completed'];
        } catch (\Throwable $e) {
            $this->markContainerTaskFailed($taskId, $e->getMessage());

            return ['ok' => false, 'status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    public function runQueuedContainerTasks(int $limit = 5): array
    {
        $this->ensureBillingSchema();
        $safeLimit = max(1, min(20, $limit));
        $rows = $this->di['db']->getAll(
            'SELECT id, worker_token
             FROM mod_vps_container_task
             WHERE status = :status
             ORDER BY id ASC
             LIMIT ' . $safeLimit,
            [':status' => 'queued']
        );

        $processed = 0;
        foreach ($rows as $row) {
            try {
                $this->runContainerTaskByToken((int) $row['id'], (string) $row['worker_token']);
                ++$processed;
            } catch (\Throwable $e) {
                $this->di['logger']->warning('VPS: queued container task #%s failed in cron: %s', (int) $row['id'], $e->getMessage());
            }
        }

        return [
            'queued' => count($rows),
            'processed' => $processed,
        ];
    }

    public function provisionVm(\Model_Client $client, array $data): array
    {
        $clientId = (int) $client->id;
        $this->ensureBillingSchema();
        $request = $this->normalizeProvisionRequestData($data);
        $vmName = $request['vm_name'];
        $osSlug = $request['vm_os'];
        $vmCpu = $request['vm_cpu'];
        $vmMemGb = $request['vm_mem'];
        $diskSize = $request['vm_disk_size'];
        $diskType = $request['vm_disk_type'];
        $networkMode = (string) ($request['network_mode'] ?? self::NETWORK_MODE_PUBLIC);

        $monthlyCost = $this->calculateMonthlyCost(
            $vmCpu,
            $vmMemGb,
            $diskSize,
            $diskType,
            $networkMode
        );
        $this->assertProvisionEligibility($client, $monthlyCost, 'provision');

        $templateVmid = $this->resolveTemplateVmid($osSlug, $diskType);
        $this->ensureProxmoxUser($clientId);

        $proxmox = $this->getRootProxmox();
        $node = $this->getProxmoxNode();
        $newVmid = $this->allocateVmid($proxmox);
        $rootPassword = $this->generatePassword(16);
        $userid = $this->getProxmoxUserId($clientId);
        $orderId = $this->resolveClientVpsOrderId($clientId);
        $targetStorage = $this->resolveStorageForDiskType($diskType);

        $cloneResponse = $proxmox->create("/nodes/$node/qemu/$templateVmid/clone", [
            'newid' => $newVmid,
            'name' => $vmName,
            'full' => 1,
            'target' => $node,
            'storage' => $targetStorage,
        ]);

        $upid = $cloneResponse['data'] ?? null;
        if (!$upid) {
            throw new InformationException('Failed to start VM clone task', [], 500);
        }

        $this->waitForTask($proxmox, $node, $upid);

        if (!$this->isWindowsTemplate($osSlug)) {
            $cloudInitIpConfig = $networkMode === self::NETWORK_MODE_IPV6_ONLY ? 'ip=manual,ip6=dhcp' : 'ip=dhcp';
            $proxmox->set("/nodes/$node/qemu/$newVmid/config", [
                'ciuser' => 'root',
                'cipassword' => $rootPassword,
                'ipconfig0' => $cloudInitIpConfig,
                'agent' => 1,
                'cores' => $vmCpu,
                'memory' => $vmMemGb * 1024,
            ]);
            try {
                $proxmox->create("/nodes/$node/qemu/$newVmid/cloudinit", []);
            } catch (\Throwable) {
                // cloud-init drive may already exist on template
            }
        } else {
            $rootPassword = '';
            $proxmox->set("/nodes/$node/qemu/$newVmid/config", [
                'cores' => $vmCpu,
                'memory' => $vmMemGb * 1024,
                'agent' => 1,
            ]);
        }

        if ($networkMode === self::NETWORK_MODE_IPV6_ONLY) {
            $this->configureVmIpv6OnlyNetwork($proxmox, $node, $newVmid);
        }

        $this->resizePrimaryDisk($proxmox, $node, $newVmid, $diskSize);

        $proxmox->set('/access/acl', [
            'path' => '/vms/' . $newVmid,
            'users' => $userid,
            'roles' => 'PVEVMUser',
        ]);

        $startResponse = $proxmox->create("/nodes/$node/qemu/$newVmid/status/start", []);
        if (!empty($startResponse['data'])) {
            $this->waitForTask($proxmox, $node, $startResponse['data']);
        }

        if (!$this->isWindowsTemplate($osSlug)) {
            $this->waitForGuestAgentReady($proxmox, $node, $newVmid, 120);
            $this->expandFilesystemWithCloudInit($proxmox, $node, $newVmid);
            $this->enforceRootPasswordWithGuestAgent($proxmox, $node, $newVmid, $rootPassword);
        }

        $ipAddress = $this->waitForVmIpAddress(
            $proxmox,
            $node,
            $newVmid,
            180,
            $networkMode === self::NETWORK_MODE_IPV6_ONLY
        );

        if (
            !$this->isWindowsTemplate($osSlug)
            && $networkMode !== self::NETWORK_MODE_IPV6_ONLY
            && $ipAddress !== null
            && $rootPassword !== ''
        ) {
            $this->enforceRootPasswordViaTemporarySsh($ipAddress, $rootPassword, $newVmid);
        }

        $now = date('Y-m-d H:i:s');
        $record = $this->di['db']->dispense('mod_vps_vm');
        $record->client_id = $clientId;
        $record->order_id = $orderId;
        $record->vmid = $newVmid;
        $record->vm_name = $vmName;
        $record->template_vmid = $templateVmid;
        $record->os_slug = $osSlug;
        $record->disk_size = $diskSize;
        $record->disk_type = $diskType;
        $record->network_mode = (string) ($request['network_mode'] ?? self::NETWORK_MODE_PUBLIC);
        $record->monthly_cost = $monthlyCost;
        $record->ip_address = $ipAddress;
        $record->power_state = 'running';
        $record->powered_off_at = null;
        $record->state_synced_at = $now;
        $record->root_password = $rootPassword !== '' ? $this->di['crypt']->encrypt($rootPassword, Config::getProperty('info.salt')) : null;
        $record->created_at = $now;
        $record->updated_at = $now;
        $this->di['db']->store($record);

        $result = [
            'vmid' => $newVmid,
            'name' => $vmName,
            'message' => 'VM provisioned successfully',
        ];

        if ($rootPassword !== '') {
            $result['root_password'] = $rootPassword;
        }
        if ($ipAddress !== null) {
            $result['ip_address'] = $ipAddress;
        }

        return $result;
    }

    public function startVm(int $clientId, int $vmid): bool
    {
        $this->assertClientOwnsVm($clientId, $vmid);
        $proxmox = $this->getRootProxmox();
        $node = $this->getVmNode($proxmox, $vmid);
        $response = $proxmox->create("/nodes/$node/qemu/$vmid/status/start", []);

        if (!empty($response['data'])) {
            $this->waitForTask($proxmox, $node, $response['data'], 120);
        }

        return true;
    }

    public function stopVm(int $clientId, int $vmid): bool
    {
        $this->assertClientOwnsVm($clientId, $vmid);
        $proxmox = $this->getRootProxmox();
        $node = $this->getVmNode($proxmox, $vmid);
        $response = $proxmox->create("/nodes/$node/qemu/$vmid/status/stop", []);

        if (!empty($response['data'])) {
            $this->waitForTask($proxmox, $node, $response['data'], 120);
        }

        return true;
    }

    public function rebootVm(int $clientId, int $vmid): bool
    {
        $this->assertClientOwnsVm($clientId, $vmid);
        $proxmox = $this->getRootProxmox();
        $node = $this->getVmNode($proxmox, $vmid);
        $response = $proxmox->create("/nodes/$node/qemu/$vmid/status/reboot", []);

        if (!empty($response['data'])) {
            $this->waitForTask($proxmox, $node, $response['data'], 120);
        }

        return true;
    }

    public function startContainer(int $clientId, int $ctid): bool
    {
        $this->assertClientOwnsContainer($clientId, $ctid);
        $proxmox = $this->getRootProxmox();
        $node = $this->getContainerNode($proxmox, $ctid);
        $response = $proxmox->create("/nodes/$node/lxc/$ctid/status/start", []);
        if (!empty($response['data'])) {
            $this->waitForTask($proxmox, $node, (string) $response['data'], 120);
        }

        $this->di['db']->exec(
            'UPDATE mod_vps_container SET power_state = :state, updated_at = :updated_at WHERE client_id = :client_id AND ctid = :ctid',
            [
                ':state' => 'running',
                ':updated_at' => date('Y-m-d H:i:s'),
                ':client_id' => $clientId,
                ':ctid' => $ctid,
            ]
        );

        return true;
    }

    public function stopContainer(int $clientId, int $ctid): bool
    {
        $this->assertClientOwnsContainer($clientId, $ctid);
        $proxmox = $this->getRootProxmox();
        $node = $this->getContainerNode($proxmox, $ctid);
        $response = $proxmox->create("/nodes/$node/lxc/$ctid/status/stop", []);
        if (!empty($response['data'])) {
            $this->waitForTask($proxmox, $node, (string) $response['data'], 120);
        }

        $this->di['db']->exec(
            'UPDATE mod_vps_container SET power_state = :state, updated_at = :updated_at WHERE client_id = :client_id AND ctid = :ctid',
            [
                ':state' => 'stopped',
                ':updated_at' => date('Y-m-d H:i:s'),
                ':client_id' => $clientId,
                ':ctid' => $ctid,
            ]
        );

        return true;
    }

    public function rebootContainer(int $clientId, int $ctid): bool
    {
        $this->assertClientOwnsContainer($clientId, $ctid);
        $proxmox = $this->getRootProxmox();
        $node = $this->getContainerNode($proxmox, $ctid);
        $response = $proxmox->create("/nodes/$node/lxc/$ctid/status/reboot", []);
        if (!empty($response['data'])) {
            $this->waitForTask($proxmox, $node, (string) $response['data'], 120);
        }

        return true;
    }

    public function deleteContainer(int $clientId, int $ctid): bool
    {
        $this->assertClientOwnsContainer($clientId, $ctid);
        $proxmox = $this->getRootProxmox();
        $node = null;

        try {
            try {
                $node = $this->getContainerNode($proxmox, $ctid);
            } catch (\Throwable) {
                $node = null;
            }

            if ($node !== null) {
                try {
                    $stopTask = $proxmox->create("/nodes/$node/lxc/$ctid/status/stop", []);
                    if (!empty($stopTask['data'])) {
                        $this->waitForTask($proxmox, $node, (string) $stopTask['data'], 90);
                    }
                } catch (\Throwable) {
                    // Container may already be stopped/unavailable.
                }

                try {
                    $deleteTask = $proxmox->delete("/nodes/$node/lxc/$ctid", [
                        'purge' => 1,
                    ]);
                } catch (\Throwable $e) {
                    if (str_contains($e->getMessage(), "Unexpected content for method 'DELETE'")) {
                        try {
                            $query = http_build_query(['purge' => 1]);
                            $deleteTask = $proxmox->delete("/nodes/$node/lxc/$ctid?$query", []);
                        } catch (\Throwable $e2) {
                            if (str_contains($e2->getMessage(), "Unexpected content for method 'DELETE'")) {
                                // Last resort: delete without optional purge flags.
                                $deleteTask = $proxmox->delete("/nodes/$node/lxc/$ctid", []);
                            } else {
                                throw $e2;
                            }
                        }
                    } else {
                        throw $e;
                    }
                }
                if (!empty($deleteTask['data'])) {
                    $this->waitForTask($proxmox, $node, (string) $deleteTask['data'], 180);
                }
            }

            $stillExists = $this->resourceIdExists($proxmox, $ctid);
            if ($stillExists) {
                throw new InformationException('Container still exists in Proxmox after delete attempt', [], 500);
            }
        } catch (\Throwable $e) {
            throw new InformationException('Failed to delete container in Proxmox: ' . $e->getMessage(), [], 500);
        }

        $record = $this->di['db']->findOne(
            'mod_vps_container',
            'client_id = :client_id AND ctid = :ctid',
            [':client_id' => $clientId, ':ctid' => $ctid]
        );
        if ($record) {
            $this->di['db']->trash($record);
        }

        return true;
    }

    public function resetContainerRootPassword(int $clientId, int $ctid): array
    {
        $this->assertClientOwnsContainer($clientId, $ctid);
        $newPassword = $this->generatePassword(16);
        $proxmox = $this->getRootProxmox();
        $node = $this->getContainerNode($proxmox, $ctid);
        $proxmox->set("/nodes/$node/lxc/$ctid/config", [
            'password' => $newPassword,
        ]);

        $ipAddress = $this->waitForContainerIpAddress($proxmox, $node, $ctid, 180);
        $this->di['db']->exec(
            'UPDATE mod_vps_container
             SET root_password = :root_password, ip_address = :ip_address, updated_at = :updated_at
             WHERE client_id = :client_id AND ctid = :ctid',
            [
                ':root_password' => $this->di['crypt']->encrypt($newPassword, Config::getProperty('info.salt')),
                ':ip_address' => $ipAddress,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':client_id' => $clientId,
                ':ctid' => $ctid,
            ]
        );

        return [
            'ctid' => $ctid,
            'root_password' => $newPassword,
            'ip_address' => $ipAddress,
        ];
    }

    public function duplicateVm(int $clientId, int $sourceVmid, ?string $requestedName = null): array
    {
        $this->ensureBillingSchema();
        $this->assertClientOwnsVm($clientId, $sourceVmid);
        $client = $this->di['db']->findOne('Client', 'id = :id', [':id' => $clientId]);
        if (!$client instanceof \Model_Client) {
            throw new InformationException('Client account was not found', [], 404);
        }

        $proxmox = $this->getRootProxmox();
        $node = $this->getVmNode($proxmox, $sourceVmid);
        $sourceConfig = $proxmox->get("/nodes/$node/qemu/$sourceVmid/config");
        $sourceName = (string) ($sourceConfig['data']['name'] ?? ('vm-' . $sourceVmid));
        $newName = trim((string) $requestedName);
        if ($newName === '') {
            $newName = $sourceName . '-copy';
        }
        $sourceRecord = $this->di['db']->getRow(
            'SELECT * FROM mod_vps_vm WHERE client_id = :client_id AND vmid = :vmid LIMIT 1',
            [':client_id' => $clientId, ':vmid' => $sourceVmid]
        );
        $sourceMonthlyCost = (float) ($sourceRecord['monthly_cost'] ?? 0);
        if ($sourceMonthlyCost > 0) {
            $this->assertProvisionEligibility($client, $sourceMonthlyCost, 'duplicate');
        }
        $sourceDiskType = (string) ($sourceRecord['disk_type'] ?? 'sata');
        $targetStorage = $this->resolveStorageForDiskType($sourceDiskType);

        $newVmid = $this->allocateVmid($proxmox);
        $cloneResponse = $proxmox->create("/nodes/$node/qemu/$sourceVmid/clone", [
            'newid' => $newVmid,
            'name' => $newName,
            'full' => 1,
            'target' => $node,
            'storage' => $targetStorage,
        ]);

        $upid = $cloneResponse['data'] ?? null;
        if (!$upid) {
            throw new InformationException('Failed to start VM duplicate task', [], 500);
        }
        $this->waitForTask($proxmox, $node, $upid);

        $proxmox->set('/access/acl', [
            'path' => '/vms/' . $newVmid,
            'users' => $this->getProxmoxUserId($clientId),
            'roles' => 'PVEVMUser',
        ]);

        $now = date('Y-m-d H:i:s');
        $record = $this->di['db']->dispense('mod_vps_vm');
        $record->client_id = $clientId;
        $record->order_id = !empty($sourceRecord['order_id']) ? (int) $sourceRecord['order_id'] : $this->resolveClientVpsOrderId($clientId);
        $record->vmid = $newVmid;
        $record->vm_name = $newName;
        $record->template_vmid = (int) ($sourceRecord['template_vmid'] ?? $sourceVmid);
        $record->os_slug = (string) ($sourceRecord['os_slug'] ?? 'custom');
        $record->disk_size = (int) ($sourceRecord['disk_size'] ?? 0);
        $record->disk_type = (string) ($sourceRecord['disk_type'] ?? 'sata');
        $record->monthly_cost = (float) ($sourceRecord['monthly_cost'] ?? 0);
        $record->power_state = 'unknown';
        $record->powered_off_at = null;
        $record->state_synced_at = $now;
        $record->root_password = null;
        $record->created_at = $now;
        $record->updated_at = $now;
        $this->di['db']->store($record);

        return [
            'vmid' => $newVmid,
            'name' => $newName,
            'message' => 'VM duplicated successfully',
        ];
    }

    public function deleteVm(int $clientId, int $vmid): bool
    {
        $this->assertClientOwnsVm($clientId, $vmid);
        $proxmox = $this->getRootProxmox();
        $node = null;

        try {
            try {
                $node = $this->getVmNode($proxmox, $vmid);
            } catch (\Throwable) {
                $node = null;
            }

            if ($node !== null) {
                try {
                    $stopTask = $proxmox->create("/nodes/$node/qemu/$vmid/status/stop", []);
                    if (!empty($stopTask['data'])) {
                        $this->waitForTask($proxmox, $node, $stopTask['data'], 90);
                    }
                } catch (\Throwable) {
                    // VM may already be stopped/unavailable.
                }

                try {
                    $deleteTask = $proxmox->delete("/nodes/$node/qemu/$vmid", [
                        'purge' => 1,
                        'destroy-unreferenced-disks' => 1,
                    ]);
                } catch (\Throwable $e) {
                    if (str_contains($e->getMessage(), "Unexpected content for method 'DELETE'")) {
                        try {
                            // Retry with query-string params and empty body.
                            $query = http_build_query([
                                'purge' => 1,
                                'destroy-unreferenced-disks' => 1,
                            ]);
                            $deleteTask = $proxmox->delete("/nodes/$node/qemu/$vmid?$query", []);
                        } catch (\Throwable $e2) {
                            if (str_contains($e2->getMessage(), "Unexpected content for method 'DELETE'")) {
                                // Last resort: delete VM without optional purge flags.
                                $deleteTask = $proxmox->delete("/nodes/$node/qemu/$vmid", []);
                            } else {
                                throw $e2;
                            }
                        }
                    } else {
                        throw $e;
                    }
                }
                if (!empty($deleteTask['data'])) {
                    $this->waitForTask($proxmox, $node, $deleteTask['data'], 180);
                }
            }

            $stillExists = $this->vmidExists($proxmox, $vmid);
            if ($stillExists) {
                throw new InformationException('VM still exists in Proxmox after delete attempt', [], 500);
            }
        } catch (\Throwable $e) {
            throw new InformationException('Failed to delete VM in Proxmox: ' . $e->getMessage(), [], 500);
        }

        $record = $this->di['db']->findOne(
            'mod_vps_vm',
            'client_id = :client_id AND vmid = :vmid',
            [':client_id' => $clientId, ':vmid' => $vmid]
        );
        if ($record) {
            $this->di['db']->trash($record);
        }
        $this->di['db']->exec(
            'UPDATE mod_vps_snapshot
             SET status = :status, updated_at = :updated_at
             WHERE client_id = :client_id AND vmid = :vmid AND status = :active_status',
            [
                ':status' => 'deleted',
                ':updated_at' => date('Y-m-d H:i:s'),
                ':client_id' => $clientId,
                ':vmid' => $vmid,
                ':active_status' => 'active',
            ]
        );

        return true;
    }

    private function createVmSnapshot(int $clientId, int $vmid, string $snapshotName): array
    {
        $this->assertClientOwnsVm($clientId, $vmid);
        if ($snapshotName === '') {
            throw new InformationException('Snapshot name is required', [], 400);
        }

        $proxmox = $this->getRootProxmox();
        $node = $this->getVmNode($proxmox, $vmid);
        $response = $proxmox->create("/nodes/$node/qemu/$vmid/snapshot", [
            'snapname' => $snapshotName,
            'description' => 'FOSSBilling snapshot ' . date('Y-m-d H:i:s'),
            'vmstate' => 0,
        ]);
        if (!empty($response['data'])) {
            $this->waitForTask($proxmox, $node, (string) $response['data'], 900);
        }

        $sizeGb = $this->estimateSnapshotSizeGb($vmid);
        $this->upsertSnapshotRecord($clientId, $vmid, $snapshotName, $sizeGb);

        return [
            'vmid' => $vmid,
            'snapshot_name' => $snapshotName,
            'size_gb' => $sizeGb,
            'monthly_cost' => round($sizeGb * 0.2, 2),
        ];
    }

    private function restoreVmSnapshot(int $clientId, int $vmid, string $snapshotName): array
    {
        $this->assertClientOwnsVm($clientId, $vmid);
        if ($snapshotName === '') {
            throw new InformationException('Snapshot name is required', [], 400);
        }

        $proxmox = $this->getRootProxmox();
        $node = $this->getVmNode($proxmox, $vmid);
        $response = $proxmox->create("/nodes/$node/qemu/$vmid/snapshot/" . rawurlencode($snapshotName) . '/rollback', []);
        if (!empty($response['data'])) {
            $this->waitForTask($proxmox, $node, (string) $response['data'], 900);
        }

        $this->di['db']->exec(
            'UPDATE mod_vps_snapshot
             SET last_restored_at = :last_restored_at, updated_at = :updated_at
             WHERE client_id = :client_id AND vmid = :vmid AND snapshot_name = :snapshot_name',
            [
                ':last_restored_at' => date('Y-m-d H:i:s'),
                ':updated_at' => date('Y-m-d H:i:s'),
                ':client_id' => $clientId,
                ':vmid' => $vmid,
                ':snapshot_name' => $snapshotName,
            ]
        );

        return [
            'vmid' => $vmid,
            'snapshot_name' => $snapshotName,
            'restored' => true,
        ];
    }

    public function getAdminVmList(): array
    {
        $rows = $this->di['db']->getAll(
            'SELECT v.*, c.email AS client_email, c.first_name, c.last_name
             FROM mod_vps_vm v
             LEFT JOIN client c ON c.id = v.client_id
             ORDER BY v.created_at DESC'
        );

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'id' => $row['id'],
                'client_id' => $row['client_id'],
                'client_email' => $row['client_email'],
                'client_name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
                'vmid' => $row['vmid'],
                'vm_name' => $row['vm_name'],
                'os_slug' => $row['os_slug'],
                'disk_size' => $row['disk_size'],
                'disk_type' => $row['disk_type'],
                'monthly_cost' => $row['monthly_cost'],
                'created_at' => $row['created_at'],
            ];
        }

        return $list;
    }

    public function getBillingReport(int $runLimit = 10): array
    {
        $this->ensureBillingSchema();

        $uninvoicedTotals = $this->di['db']->getAll(
            "SELECT DATE_FORMAT(period_hour, '%Y-%m') AS period_month,
                    client_id,
                    order_id,
                    COUNT(*) AS row_count,
                    SUM(hours) AS total_hours,
                    SUM(amount) AS total_amount
             FROM mod_vps_usage_hourly
             WHERE invoiced_at IS NULL
               AND order_id IS NOT NULL
               AND order_id > 0
             GROUP BY DATE_FORMAT(period_hour, '%Y-%m'), client_id, order_id
             ORDER BY period_month DESC, total_amount DESC
             LIMIT 100"
        );

        $missingOrderRows = $this->di['db']->getAll(
            'SELECT u.id, u.client_id, u.vmid, u.period_hour, u.state, u.hours, u.rate, u.amount, v.order_id AS vm_order_id
             FROM mod_vps_usage_hourly u
             LEFT JOIN mod_vps_vm v ON v.vmid = u.vmid
             WHERE u.invoiced_at IS NULL
               AND (u.order_id IS NULL OR u.order_id = 0)
             ORDER BY u.period_hour DESC, u.id DESC
             LIMIT 100'
        );

        $recentGeneratedInvoices = $this->di['db']->getAll(
            "SELECT u.invoice_id,
                    MAX(u.invoiced_at) AS invoiced_at,
                    MIN(u.period_hour) AS period_start,
                    MAX(u.period_hour) AS period_end,
                    COUNT(*) AS usage_rows,
                    SUM(u.amount) AS amount,
                    MAX(i.status) AS invoice_status,
                    MAX(i.client_id) AS client_id
             FROM mod_vps_usage_hourly u
             LEFT JOIN invoice i ON i.id = u.invoice_id
             WHERE u.invoice_id IS NOT NULL
             GROUP BY u.invoice_id
             ORDER BY MAX(u.invoiced_at) DESC
             LIMIT 25"
        );

        $safeRunLimit = max(1, min(100, $runLimit));
        $recentRuns = $this->di['db']->getAll(
            'SELECT id, run_at, metered_rows, inserted_rows, updated_rows, missing_order_rows,
                    invoiced_groups, invoiced_usage_rows, generated_invoice_ids, notes
             FROM mod_vps_billing_run
             ORDER BY run_at DESC, id DESC
             LIMIT ' . $safeRunLimit
        );

        $parsedRuns = [];
        foreach ($recentRuns as $run) {
            $invoiceIds = json_decode((string) ($run['generated_invoice_ids'] ?? '[]'), true);
            $parsedRuns[] = [
                'id' => (int) $run['id'],
                'run_at' => (string) $run['run_at'],
                'metered_rows' => (int) $run['metered_rows'],
                'inserted_rows' => (int) $run['inserted_rows'],
                'updated_rows' => (int) $run['updated_rows'],
                'missing_order_rows' => (int) $run['missing_order_rows'],
                'invoiced_groups' => (int) $run['invoiced_groups'],
                'invoiced_usage_rows' => (int) $run['invoiced_usage_rows'],
                'generated_invoice_ids' => is_array($invoiceIds) ? array_values(array_map('intval', $invoiceIds)) : [],
                'notes' => (string) ($run['notes'] ?? ''),
            ];
        }

        return [
            'uninvoiced_totals' => array_map(static function (array $row): array {
                return [
                    'period_month' => (string) ($row['period_month'] ?? ''),
                    'client_id' => (int) ($row['client_id'] ?? 0),
                    'order_id' => (int) ($row['order_id'] ?? 0),
                    'row_count' => (int) ($row['row_count'] ?? 0),
                    'total_hours' => round((float) ($row['total_hours'] ?? 0), 2),
                    'total_amount' => round((float) ($row['total_amount'] ?? 0), 4),
                ];
            }, $uninvoicedTotals),
            'missing_order_rows' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'client_id' => (int) ($row['client_id'] ?? 0),
                    'vmid' => (int) ($row['vmid'] ?? 0),
                    'period_hour' => (string) ($row['period_hour'] ?? ''),
                    'state' => (string) ($row['state'] ?? ''),
                    'hours' => round((float) ($row['hours'] ?? 0), 2),
                    'rate' => round((float) ($row['rate'] ?? 0), 6),
                    'amount' => round((float) ($row['amount'] ?? 0), 6),
                    'vm_order_id' => isset($row['vm_order_id']) ? (int) $row['vm_order_id'] : null,
                ];
            }, $missingOrderRows),
            'recent_generated_invoices' => array_map(static function (array $row): array {
                return [
                    'invoice_id' => (int) ($row['invoice_id'] ?? 0),
                    'invoiced_at' => (string) ($row['invoiced_at'] ?? ''),
                    'period_start' => (string) ($row['period_start'] ?? ''),
                    'period_end' => (string) ($row['period_end'] ?? ''),
                    'usage_rows' => (int) ($row['usage_rows'] ?? 0),
                    'amount' => round((float) ($row['amount'] ?? 0), 4),
                    'invoice_status' => (string) ($row['invoice_status'] ?? ''),
                    'client_id' => (int) ($row['client_id'] ?? 0),
                ];
            }, $recentGeneratedInvoices),
            'recent_runs' => $parsedRuns,
        ];
    }

    public function getClientUsageSummary(int $clientId): array
    {
        $this->ensureBillingSchema();

        $rows = $this->di['db']->getAll(
            'SELECT u.vmid,
                    COALESCE(v.vm_name, CONCAT("VM #", u.vmid)) AS vm_name,
                    SUM(u.hours) AS total_hours,
                    SUM(u.amount) AS total_amount,
                    MIN(u.period_hour) AS period_start,
                    MAX(u.period_hour) AS period_end
             FROM mod_vps_usage_hourly u
             LEFT JOIN mod_vps_vm v ON v.vmid = u.vmid AND v.client_id = :client_id
             WHERE u.client_id = :client_id
               AND u.invoiced_at IS NULL
             GROUP BY u.vmid, vm_name
             ORDER BY total_amount DESC, u.vmid ASC',
            [':client_id' => $clientId]
        );

        $summaryRows = [];
        $containerSummaryRows = [];
        $totalAmount = 0.0;
        $totalHours = 0.0;
        $containerTotalAmount = 0.0;
        $containerTotalHours = 0.0;
        $periodStart = null;
        $periodEnd = null;

        foreach ($rows as $row) {
            $hours = (float) ($row['total_hours'] ?? 0);
            $amount = (float) ($row['total_amount'] ?? 0);
            $start = !empty($row['period_start']) ? (string) $row['period_start'] : null;
            $end = !empty($row['period_end']) ? (string) $row['period_end'] : null;

            $summaryRows[] = [
                'vmid' => (int) ($row['vmid'] ?? 0),
                'vm_name' => (string) ($row['vm_name'] ?? ('VM #' . (int) ($row['vmid'] ?? 0))),
                'hours' => round($hours, 2),
                'amount' => round($amount, 4),
                'period_start' => $start,
                'period_end' => $end,
            ];

            $totalHours += $hours;
            $totalAmount += $amount;
            if ($start !== null && ($periodStart === null || strcmp($start, $periodStart) < 0)) {
                $periodStart = $start;
            }
            if ($end !== null && ($periodEnd === null || strcmp($end, $periodEnd) > 0)) {
                $periodEnd = $end;
            }
        }

        $containerRows = $this->di['db']->getAll(
            'SELECT u.ctid,
                    COALESCE(c.container_name, CONCAT("CT #", u.ctid)) AS container_name,
                    SUM(u.hours) AS total_hours,
                    SUM(u.amount) AS total_amount,
                    MIN(u.period_hour) AS period_start,
                    MAX(u.period_hour) AS period_end
             FROM mod_vps_container_usage_hourly u
             LEFT JOIN mod_vps_container c ON c.ctid = u.ctid AND c.client_id = :client_id
             WHERE u.client_id = :client_id
               AND u.invoiced_at IS NULL
             GROUP BY u.ctid, container_name
             ORDER BY total_amount DESC, u.ctid ASC',
            [':client_id' => $clientId]
        );
        foreach ($containerRows as $row) {
            $hours = (float) ($row['total_hours'] ?? 0);
            $amount = (float) ($row['total_amount'] ?? 0);
            $start = !empty($row['period_start']) ? (string) $row['period_start'] : null;
            $end = !empty($row['period_end']) ? (string) $row['period_end'] : null;

            $containerSummaryRows[] = [
                'ctid' => (int) ($row['ctid'] ?? 0),
                'container_name' => (string) ($row['container_name'] ?? ('CT #' . (int) ($row['ctid'] ?? 0))),
                'hours' => round($hours, 2),
                'amount' => round($amount, 4),
                'period_start' => $start,
                'period_end' => $end,
            ];
            $containerTotalHours += $hours;
            $containerTotalAmount += $amount;
            if ($start !== null && ($periodStart === null || strcmp($start, $periodStart) < 0)) {
                $periodStart = $start;
            }
            if ($end !== null && ($periodEnd === null || strcmp($end, $periodEnd) > 0)) {
                $periodEnd = $end;
            }
        }

        return [
            'rows' => $summaryRows,
            'vm_count' => count($summaryRows),
            'total_hours' => round($totalHours, 2),
            'total_amount' => round($totalAmount, 4),
            'container_rows' => $containerSummaryRows,
            'container_count' => count($containerSummaryRows),
            'container_total_hours' => round($containerTotalHours, 2),
            'container_total_amount' => round($containerTotalAmount, 4),
            'grand_total_hours' => round($totalHours + $containerTotalHours, 2),
            'grand_total_amount' => round($totalAmount + $containerTotalAmount, 4),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }

    public function runBillingCycleNow(): array
    {
        $this->ensureBillingSchema();
        $syncVm = $this->syncVmRuntimeState();
        $syncContainers = $this->syncContainerRuntimeState();
        $metering = $this->runHourlyUsageMetering();
        $invoicing = $this->generateMonthlyUsageInvoices();
        $this->recordBillingRun($metering, $invoicing);

        return [
            'ok' => true,
            'message' => 'Billing cycle executed',
            'sync' => [
                'vms' => $syncVm,
                'containers' => $syncContainers,
            ],
            'metering' => $metering,
            'invoicing' => $invoicing,
        ];
    }

    public function getClientVmOverview(int $clientId): array
    {
        $this->ensureBillingSchema();

        $rows = $this->di['db']->getAll(
            'SELECT vmid, vm_name, os_slug, disk_size, disk_type, monthly_cost, ip_address, created_at
             FROM mod_vps_vm
             WHERE client_id = :client_id
             ORDER BY vmid ASC',
            [':client_id' => $clientId]
        );

        $overview = [];
        foreach ($rows as $row) {
            $vmid = (int) $row['vmid'];
            $overview[$vmid] = [
                'vmid' => $vmid,
                'name' => (string) ($row['vm_name'] ?? ('vm-' . $vmid)),
                'os_slug' => (string) ($row['os_slug'] ?? ''),
                'disk_size' => (int) ($row['disk_size'] ?? 0),
                'disk_type' => (string) ($row['disk_type'] ?? ''),
                'monthly_cost' => (float) ($row['monthly_cost'] ?? 0),
                'ip_address' => !empty($row['ip_address']) ? (string) $row['ip_address'] : null,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'status' => 'unknown',
                'uptime_hours' => null,
                'vcpu' => null,
                'memory_mb' => null,
                'interfaces' => [],
                'snapshots' => [],
                'snapshot_total_size_gb' => 0.0,
                'snapshot_monthly_cost' => 0.0,
            ];
        }

        if ($overview === []) {
            return [];
        }

        try {
            $proxmox = $this->getRootProxmox();
            $resources = $proxmox->get('/cluster/resources');
            foreach ($resources['data'] ?? [] as $resource) {
                if (($resource['type'] ?? '') !== 'qemu') {
                    continue;
                }

                $vmid = (int) ($resource['vmid'] ?? 0);
                if (!isset($overview[$vmid])) {
                    continue;
                }

                if (!empty($resource['name'])) {
                    $overview[$vmid]['name'] = (string) $resource['name'];
                }
                if (isset($resource['status'])) {
                    $overview[$vmid]['status'] = (string) $resource['status'];
                }
                if (isset($resource['uptime'])) {
                    $overview[$vmid]['uptime_hours'] = round(((int) $resource['uptime']) / 3600, 2);
                }
                if (isset($resource['maxcpu'])) {
                    $overview[$vmid]['vcpu'] = (int) $resource['maxcpu'];
                } elseif (isset($resource['cpus'])) {
                    $overview[$vmid]['vcpu'] = max(1, (int) round((float) $resource['cpus']));
                }
                if (isset($resource['maxmem'])) {
                    $overview[$vmid]['memory_mb'] = (int) round(((int) $resource['maxmem']) / 1048576);
                }

                // Keep index page fast: avoid per-VM API calls here.
                // For legacy rows missing disk metadata, derive once from cluster resource size.
                if ($overview[$vmid]['disk_size'] <= 0 && isset($resource['maxdisk'])) {
                    $overview[$vmid]['disk_size'] = (int) max(0, round(((int) $resource['maxdisk']) / 1073741824));
                }
            }
        } catch (\Throwable $e) {
            $this->di['logger']->warning('VPS: unable to load live VM overview data: ' . $e->getMessage());
        }

        $snapshotRows = $this->di['db']->getAll(
            'SELECT vmid, snapshot_name, size_gb, status, created_at, last_restored_at
             FROM mod_vps_snapshot
             WHERE client_id = :client_id
               AND status = :status
             ORDER BY vmid ASC, created_at DESC',
            [
                ':client_id' => $clientId,
                ':status' => 'active',
            ]
        );
        foreach ($snapshotRows as $snapshot) {
            $vmid = (int) ($snapshot['vmid'] ?? 0);
            if (!isset($overview[$vmid])) {
                continue;
            }

            $sizeGb = round((float) ($snapshot['size_gb'] ?? 0), 2);
            $overview[$vmid]['snapshots'][] = [
                'name' => (string) ($snapshot['snapshot_name'] ?? ''),
                'size_gb' => $sizeGb,
                'monthly_cost' => round($sizeGb * 0.2, 2),
                'created_at' => (string) ($snapshot['created_at'] ?? ''),
                'last_restored_at' => (string) ($snapshot['last_restored_at'] ?? ''),
            ];
            $overview[$vmid]['snapshot_total_size_gb'] = round((float) $overview[$vmid]['snapshot_total_size_gb'] + $sizeGb, 2);
            $overview[$vmid]['snapshot_monthly_cost'] = round((float) $overview[$vmid]['snapshot_monthly_cost'] + ($sizeGb * 0.2), 2);
        }

        return array_values($overview);
    }

    public function syncVmRuntimeState(): array
    {
        $this->ensureBillingSchema();
        $rows = $this->di['db']->getAll(
            'SELECT id, vmid, power_state, powered_off_at FROM mod_vps_vm ORDER BY id ASC'
        );
        if ($rows === []) {
            return ['total' => 0, 'updated' => 0];
        }

        $resourcesByVmid = [];
        try {
            $resources = $this->getRootProxmox()->get('/cluster/resources');
            foreach (($resources['data'] ?? []) as $resource) {
                if (($resource['type'] ?? '') !== 'qemu') {
                    continue;
                }
                $vmid = (int) ($resource['vmid'] ?? 0);
                if ($vmid > 0) {
                    $resourcesByVmid[$vmid] = $resource;
                }
            }
        } catch (\Throwable $e) {
            $this->di['logger']->warning('VPS state sync: unable to load cluster resources: ' . $e->getMessage());
        }

        $updated = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $vmid = (int) $row['vmid'];
            $resource = $resourcesByVmid[$vmid] ?? null;
            $newState = (string) (($resource['status'] ?? '') ?: 'missing');
            $oldState = (string) ($row['power_state'] ?? 'unknown');
            $poweredOffAt = (string) ($row['powered_off_at'] ?? '');

            if ($newState === $oldState) {
                continue;
            }

            $nextPoweredOffAt = $poweredOffAt;
            if ($newState === 'running') {
                $nextPoweredOffAt = null;
            } elseif ($oldState === 'running' || $poweredOffAt === '') {
                $nextPoweredOffAt = $now;
            }

            $this->di['db']->exec(
                'UPDATE mod_vps_vm
                 SET power_state = :power_state, powered_off_at = :powered_off_at, state_synced_at = :state_synced_at, updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':power_state' => $newState,
                    ':powered_off_at' => $nextPoweredOffAt,
                    ':state_synced_at' => $now,
                    ':updated_at' => $now,
                    ':id' => (int) $row['id'],
                ]
            );
            ++$updated;
        }

        return [
            'total' => count($rows),
            'updated' => $updated,
        ];
    }

    public function syncContainerRuntimeState(): array
    {
        $this->ensureBillingSchema();
        $rows = $this->di['db']->getAll(
            'SELECT id, ctid, power_state FROM mod_vps_container ORDER BY id ASC'
        );
        if ($rows === []) {
            return ['total' => 0, 'updated' => 0];
        }

        $resourcesByCtid = [];
        try {
            $resources = $this->getRootProxmox()->get('/cluster/resources');
            foreach (($resources['data'] ?? []) as $resource) {
                if (($resource['type'] ?? '') !== 'lxc') {
                    continue;
                }
                $ctid = (int) ($resource['vmid'] ?? 0);
                if ($ctid > 0) {
                    $resourcesByCtid[$ctid] = $resource;
                }
            }
        } catch (\Throwable $e) {
            $this->di['logger']->warning('VPS container state sync: unable to load cluster resources: ' . $e->getMessage());
        }

        $updated = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $ctid = (int) $row['ctid'];
            $resource = $resourcesByCtid[$ctid] ?? null;
            $newState = (string) (($resource['status'] ?? '') ?: 'missing');
            $oldState = (string) ($row['power_state'] ?? 'unknown');
            if ($newState === $oldState) {
                continue;
            }

            $this->di['db']->exec(
                'UPDATE mod_vps_container
                 SET power_state = :power_state, state_synced_at = :state_synced_at, updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':power_state' => $newState,
                    ':state_synced_at' => $now,
                    ':updated_at' => $now,
                    ':id' => (int) $row['id'],
                ]
            );
            ++$updated;
        }

        return [
            'total' => count($rows),
            'updated' => $updated,
        ];
    }

    public function resetVmRootPassword(int $clientId, int $vmid): array
    {
        $this->assertClientOwnsVm($clientId, $vmid);
        $record = $this->di['db']->findOne(
            'mod_vps_vm',
            'client_id = :client_id AND vmid = :vmid',
            [':client_id' => $clientId, ':vmid' => $vmid]
        );
        if (!$record) {
            throw new InformationException('VM record not found', [], 404);
        }
        $osSlug = (string) ($record->os_slug ?? '');
        if ($this->isWindowsTemplate($osSlug)) {
            throw new InformationException('Root password reset via cloud-init is only available for Linux/Unix templates', [], 400);
        }

        $newPassword = $this->generatePassword(16);
        $networkMode = $this->normalizeNetworkMode((string) ($record->network_mode ?? self::NETWORK_MODE_PUBLIC));
        $cloudInitIpConfig = $networkMode === self::NETWORK_MODE_IPV6_ONLY ? 'ip=manual,ip6=dhcp' : 'ip=dhcp';
        $proxmox = $this->getRootProxmox();
        $node = $this->getVmNode($proxmox, $vmid);
        $proxmox->set("/nodes/$node/qemu/$vmid/config", [
            'ciuser' => 'root',
            'cipassword' => $newPassword,
            'ipconfig0' => $cloudInitIpConfig,
            'agent' => 1,
        ]);
        if ($networkMode === self::NETWORK_MODE_IPV6_ONLY) {
            $this->configureVmIpv6OnlyNetwork($proxmox, $node, $vmid);
        }
        try {
            $proxmox->create("/nodes/$node/qemu/$vmid/cloudinit", []);
        } catch (\Throwable) {
        }

        $status = $proxmox->get("/nodes/$node/qemu/$vmid/status/current");
        $isRunning = (($status['data']['status'] ?? '') === 'running');
        if ($isRunning) {
            $task = $proxmox->create("/nodes/$node/qemu/$vmid/status/reboot", []);
        } else {
            $task = $proxmox->create("/nodes/$node/qemu/$vmid/status/start", []);
        }
        if (!empty($task['data'])) {
            $this->waitForTask($proxmox, $node, $task['data'], 240);
        }

        $this->waitForGuestAgentReady($proxmox, $node, $vmid, 180);
        $this->enforceRootPasswordWithGuestAgent($proxmox, $node, $vmid, $newPassword);
        $ipAddress = $this->waitForVmIpAddress($proxmox, $node, $vmid, 180, $networkMode === self::NETWORK_MODE_IPV6_ONLY);
        if ($ipAddress !== null && $networkMode !== self::NETWORK_MODE_IPV6_ONLY) {
            $this->enforceRootPasswordViaTemporarySsh($ipAddress, $newPassword, $vmid);
        }

        $now = date('Y-m-d H:i:s');
        $record->root_password = $this->di['crypt']->encrypt($newPassword, Config::getProperty('info.salt'));
        if ($ipAddress !== null) {
            $record->ip_address = $ipAddress;
        }
        $record->updated_at = $now;
        $this->di['db']->store($record);

        return [
            'vmid' => $vmid,
            'root_password' => $newPassword,
            'ip_address' => $ipAddress,
        ];
    }

    public function getClientVmFolders(int $clientId): array
    {
        return $this->loadClientFolderState($clientId);
    }

    public function createClientVmFolder(int $clientId, string $name): array
    {
        $folderName = trim($name);
        if ($folderName === '') {
            throw new InformationException('Folder name is required', [], 400);
        }
        $state = $this->loadClientFolderState($clientId);
        $id = (int) ($state['next_id'] ?? 1);
        $state['next_id'] = $id + 1;
        $state['folders'][] = [
            'id' => $id,
            'name' => $folderName,
            'collapsed' => false,
            'container_collapsed' => false,
        ];
        $this->saveClientFolderState($clientId, $state);

        return $state;
    }

    public function renameClientVmFolder(int $clientId, int $folderId, string $name): array
    {
        $folderName = trim($name);
        if ($folderName === '') {
            throw new InformationException('Folder name is required', [], 400);
        }
        $state = $this->loadClientFolderState($clientId);
        $found = false;
        foreach ($state['folders'] as &$folder) {
            if ((int) ($folder['id'] ?? 0) === $folderId) {
                $folder['name'] = $folderName;
                $found = true;
                break;
            }
        }
        unset($folder);
        if (!$found) {
            throw new InformationException('Folder not found', [], 404);
        }
        $this->saveClientFolderState($clientId, $state);

        return $state;
    }

    public function deleteClientVmFolder(int $clientId, int $folderId): array
    {
        $state = $this->loadClientFolderState($clientId);
        $state['folders'] = array_values(array_filter(
            $state['folders'],
            static fn (array $folder): bool => (int) ($folder['id'] ?? 0) !== $folderId
        ));
        foreach ($state['vm_assignments'] as $vmid => $assignedFolderId) {
            if ((int) $assignedFolderId === $folderId) {
                unset($state['vm_assignments'][$vmid]);
            }
        }
        foreach ($state['container_assignments'] as $ctid => $assignedFolderId) {
            if ((int) $assignedFolderId === $folderId) {
                unset($state['container_assignments'][$ctid]);
            }
        }
        $this->saveClientFolderState($clientId, $state);

        return $state;
    }

    public function assignVmToFolder(int $clientId, int $vmid, ?int $folderId): array
    {
        $this->assertClientOwnsVm($clientId, $vmid);
        $state = $this->loadClientFolderState($clientId);
        if ($folderId === null || $folderId <= 0) {
            unset($state['vm_assignments'][(string) $vmid]);
            $this->saveClientFolderState($clientId, $state);
            return $state;
        }

        $exists = false;
        foreach ($state['folders'] as $folder) {
            if ((int) ($folder['id'] ?? 0) === $folderId) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            throw new InformationException('Folder not found', [], 404);
        }
        $state['vm_assignments'][(string) $vmid] = $folderId;
        $this->saveClientFolderState($clientId, $state);

        return $state;
    }

    public function assignContainerToFolder(int $clientId, int $ctid, ?int $folderId): array
    {
        $this->assertClientOwnsContainer($clientId, $ctid);
        $state = $this->loadClientFolderState($clientId);
        if ($folderId === null || $folderId <= 0) {
            unset($state['container_assignments'][(string) $ctid]);
            $this->saveClientFolderState($clientId, $state);
            return $state;
        }

        $exists = false;
        foreach ($state['folders'] as $folder) {
            if ((int) ($folder['id'] ?? 0) === $folderId) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            throw new InformationException('Folder not found', [], 404);
        }
        $state['container_assignments'][(string) $ctid] = $folderId;
        $this->saveClientFolderState($clientId, $state);

        return $state;
    }

    public function setFolderCollapsed(int $clientId, int $folderId, bool $collapsed, string $scope = 'vm'): array
    {
        $state = $this->loadClientFolderState($clientId);
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['vm', 'container'], true)) {
            $scope = 'vm';
        }
        $found = false;
        foreach ($state['folders'] as &$folder) {
            if ((int) ($folder['id'] ?? 0) === $folderId) {
                if ($scope === 'container') {
                    $folder['container_collapsed'] = $collapsed;
                } else {
                    $folder['collapsed'] = $collapsed;
                }
                $found = true;
                break;
            }
        }
        unset($folder);
        if (!$found) {
            throw new InformationException('Folder not found', [], 404);
        }
        $this->saveClientFolderState($clientId, $state);

        return $state;
    }

    public function verifyConnection(): array
    {
        $checks = [];
        $isOk = true;

        try {
            $proxmox = $this->getRootProxmox();
            $checks[] = [
                'id' => 'auth',
                'label' => 'Authentication',
                'ok' => true,
                'message' => 'Authenticated successfully',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Authentication failed',
                'checks' => [[
                    'id' => 'auth',
                    'label' => 'Authentication',
                    'ok' => false,
                    'message' => $e->getMessage(),
                ]],
            ];
        }

        $runCheck = function (string $id, string $label, callable $callback) use (&$checks, &$isOk): void {
            try {
                $message = (string) $callback();
                $checks[] = [
                    'id' => $id,
                    'label' => $label,
                    'ok' => true,
                    'message' => $message !== '' ? $message : 'OK',
                ];
            } catch (\Throwable $e) {
                $isOk = false;
                $checks[] = [
                    'id' => $id,
                    'label' => $label,
                    'ok' => false,
                    'message' => $e->getMessage(),
                ];
            }
        };

        $node = $this->getProxmoxNode();
        $runCheck('version', 'API version', static function () use ($proxmox): string {
            $version = $proxmox->get('/version');
            return 'Connected to Proxmox ' . (($version['data']['release'] ?? '') ?: 'API');
        });

        $runCheck('node_exists', 'Configured node exists', static function () use ($proxmox, $node): string {
            $nodes = $proxmox->get('/nodes');
            foreach ($nodes['data'] ?? [] as $n) {
                if (($n['node'] ?? '') === $node) {
                    return 'Node "' . $node . '" is reachable';
                }
            }
            throw new InformationException('Configured node "' . $node . '" was not found', [], 500);
        });

        $runCheck('cluster_resources', 'Cluster resources endpoint', static function () use ($proxmox): string {
            $resources = $proxmox->get('/cluster/resources');
            $count = count($resources['data'] ?? []);
            return 'Returned ' . $count . ' resource records';
        });

        $runCheck('node_qemu', 'Node VM listing endpoint', static function () use ($proxmox, $node): string {
            $vms = $proxmox->get('/nodes/' . $node . '/qemu');
            $count = count($vms['data'] ?? []);
            return 'Returned ' . $count . ' VM records';
        });

        $runCheck('access_users', 'Access users endpoint', static function () use ($proxmox): string {
            $users = $proxmox->get('/access/users');
            $count = count($users['data'] ?? []);
            return 'Returned ' . $count . ' users';
        });

        $runCheck('access_acl', 'Access ACL endpoint', static function () use ($proxmox): string {
            $acls = $proxmox->get('/access/acl');
            $count = count($acls['data'] ?? []);
            return 'Returned ' . $count . ' ACL entries';
        });

        $runCheck('templates', 'Provisioning templates exist', function () use ($proxmox): string {
            $resources = $proxmox->get('/cluster/resources');
            $seen = [];
            foreach ($resources['data'] ?? [] as $resource) {
                if (($resource['type'] ?? '') === 'qemu') {
                    $seen[(int) ($resource['vmid'] ?? 0)] = true;
                }
            }

            $requiredTemplates = [];
            foreach ($this->getTemplateMap('sata') as $slug => $vmid) {
                $requiredTemplates[$slug . ':sata'] = (int) $vmid;
            }
            foreach (self::TEMPLATE_MAP_SSD as $slug => $vmid) {
                $requiredTemplates[$slug . ':ssd'] = (int) $vmid;
            }

            $missing = [];
            foreach ($requiredTemplates as $templateLabel => $vmid) {
                if (!isset($seen[(int) $vmid])) {
                    $missing[] = $templateLabel . ' (' . $vmid . ')';
                }
            }

            if ($missing !== []) {
                throw new InformationException('Missing templates: ' . implode(', ', $missing), [], 500);
            }

            return 'All configured template VM IDs were found';
        });

        $adminIdentity = $this->normalizeAuthIdentity(
            (string) ($this->getModuleConfig()['proxmox_root_user'] ?? self::DEFAULT_PROXMOX_ROOT_USER),
            $this->getProxmoxAuthRealm()
        );
        $runCheck('permissions', 'Permissions endpoint', static function () use ($proxmox, $adminIdentity): string {
            $permissions = $proxmox->get('/access/permissions', ['userid' => $adminIdentity['username'] . '@' . $adminIdentity['realm']]);
            if (!isset($permissions['data']) || !is_array($permissions['data'])) {
                throw new InformationException('Permissions response is invalid', [], 500);
            }

            return 'Permissions query succeeded';
        });

        return [
            'ok' => $isOk,
            'message' => $isOk ? 'All required checks passed' : 'One or more checks failed',
            'checks' => $checks,
        ];
    }

    public function getVmNode(Proxmox $proxmox, int $vmid): string
    {
        $resources = $proxmox->get('/cluster/resources');
        foreach ($resources['data'] ?? [] as $resource) {
            if (($resource['type'] ?? '') === 'qemu' && (int) ($resource['vmid'] ?? 0) === $vmid) {
                return $resource['node'];
            }
        }

        throw new InformationException('Unable to locate VM node', [], 404);
    }

    public function getContainerNode(Proxmox $proxmox, int $ctid): string
    {
        $resources = $proxmox->get('/cluster/resources');
        foreach ($resources['data'] ?? [] as $resource) {
            if (($resource['type'] ?? '') === 'lxc' && (int) ($resource['vmid'] ?? 0) === $ctid) {
                return (string) ($resource['node'] ?? '');
            }
        }

        throw new InformationException('Unable to locate container node', [], 404);
    }

    private function assertClientOwnsVm(int $clientId, int $vmid): void
    {
        if (!$this->clientOwnsVm($clientId, $vmid)) {
            throw new InformationException('You do not have access to this VM', [], 403);
        }
    }

    private function assertClientOwnsContainer(int $clientId, int $ctid): void
    {
        if (!$this->clientOwnsContainer($clientId, $ctid)) {
            throw new InformationException('You do not have access to this container', [], 403);
        }
    }

    private function getClientVmIdsFromDb(int $clientId): array
    {
        $rows = $this->di['db']->getAll(
            'SELECT vmid FROM mod_vps_vm WHERE client_id = :client_id ORDER BY vmid ASC',
            [':client_id' => $clientId]
        );

        return array_map(static fn ($row) => (string) $row['vmid'], $rows);
    }

    private function allocateVmid(Proxmox $proxmox): int
    {
        $resources = $proxmox->get('/cluster/resources');
        $maxVmid = self::VM_ID_START - 1;

        foreach ($resources['data'] ?? [] as $resource) {
            if (($resource['type'] ?? '') !== 'qemu') {
                continue;
            }
            $vmid = (int) ($resource['vmid'] ?? 0);
            if ($vmid > $maxVmid) {
                $maxVmid = $vmid;
            }
        }

        $next = max(self::VM_ID_START, $maxVmid + 1);
        while ($this->vmidExists($proxmox, $next)) {
            ++$next;
        }

        return $next;
    }

    private function allocateGuestId(Proxmox $proxmox): int
    {
        $resources = $proxmox->get('/cluster/resources');
        $maxId = self::VM_ID_START - 1;

        foreach ($resources['data'] ?? [] as $resource) {
            $type = (string) ($resource['type'] ?? '');
            if ($type !== 'qemu' && $type !== 'lxc') {
                continue;
            }
            $resourceId = (int) ($resource['vmid'] ?? 0);
            if ($resourceId > $maxId) {
                $maxId = $resourceId;
            }
        }

        $next = max(self::VM_ID_START, $maxId + 1);
        while ($this->resourceIdExists($proxmox, $next)) {
            ++$next;
        }

        return $next;
    }

    private function vmidExists(Proxmox $proxmox, int $vmid): bool
    {
        foreach ($proxmox->get('/cluster/resources')['data'] ?? [] as $resource) {
            if (($resource['type'] ?? '') === 'qemu' && (int) ($resource['vmid'] ?? 0) === $vmid) {
                return true;
            }
        }

        return false;
    }

    private function resourceIdExists(Proxmox $proxmox, int $resourceId): bool
    {
        foreach ($proxmox->get('/cluster/resources')['data'] ?? [] as $resource) {
            $type = (string) ($resource['type'] ?? '');
            if ($type !== 'qemu' && $type !== 'lxc') {
                continue;
            }
            if ((int) ($resource['vmid'] ?? 0) === $resourceId) {
                return true;
            }
        }

        return false;
    }

    private function waitForTask(Proxmox $proxmox, string $node, string $upid, int $timeout = 900): void
    {
        $start = time();
        while (time() - $start < $timeout) {
            $status = $proxmox->get("/nodes/$node/tasks/" . rawurlencode($upid) . '/status');
            $exit = $status['data']['exitstatus'] ?? null;
            if ($exit !== null) {
                if ($this->isTaskExitStatusSuccessful((string) $exit)) {
                    if (stripos((string) $exit, 'WARNINGS:') === 0) {
                        $this->di['logger']->warning('Proxmox task %s completed with warnings: %s', $upid, (string) $exit);
                    }
                    return;
                }

                throw new InformationException('Proxmox task failed: ' . $exit, [], 500);
            }
            sleep(2);
        }

        throw new InformationException('Proxmox task timed out', [], 504);
    }

    private function isTaskExitStatusSuccessful(string $exitStatus): bool
    {
        $normalized = strtoupper(trim($exitStatus));
        if ($normalized === 'OK') {
            return true;
        }

        // Proxmox may report warning-only completion as "WARNINGS: N".
        return str_starts_with($normalized, 'WARNINGS:');
    }

    private function resizePrimaryDisk(Proxmox $proxmox, string $node, int $vmid, int $diskSizeGb): void
    {
        $config = $proxmox->get("/nodes/$node/qemu/$vmid/config");
        $diskKey = null;
        foreach (['scsi0', 'virtio0', 'sata0', 'ide0'] as $candidate) {
            if (!empty($config['data'][$candidate])) {
                $diskKey = $candidate;
                break;
            }
        }

        if ($diskKey === null) {
            return;
        }

        try {
            $proxmox->set("/nodes/$node/qemu/$vmid/resize", [
                'disk' => $diskKey,
                'size' => $diskSizeGb . 'G',
            ]);
        } catch (\Throwable $e) {
            $this->di['logger']->warning('Unable to resize VM disk: ' . $e->getMessage());
        }
    }

    private function isWindowsTemplate(string $osSlug): bool
    {
        return str_starts_with($osSlug, 'win');
    }

    private function waitForVmIpAddress(Proxmox $proxmox, string $node, int $vmid, int $timeoutSeconds = 60, bool $preferIpv6 = false): ?string
    {
        $start = time();
        while (time() - $start < $timeoutSeconds) {
            $ip = $this->getVmIpAddress($proxmox, $node, $vmid, $preferIpv6);
            if ($ip !== null) {
                return $ip;
            }
            sleep(3);
        }

        return null;
    }

    private function getVmIpAddress(Proxmox $proxmox, string $node, int $vmid, bool $preferIpv6 = false): ?string
    {
        try {
            $response = $proxmox->get("/nodes/$node/qemu/$vmid/agent/network-get-interfaces");
            $interfaces = $response['data']['result'] ?? [];
            if ($preferIpv6) {
                $ipv6 = $this->extractPrimaryIpv6(is_array($interfaces) ? $interfaces : []);
                if ($ipv6 !== null) {
                    return $ipv6;
                }
            }

            return $this->extractPrimaryIpv4(is_array($interfaces) ? $interfaces : []);
        } catch (\Throwable) {
            // Guest agent may not be ready yet (or not installed).
            return null;
        }
    }

    private function extractPrimaryIpv6(array $interfaces): ?string
    {
        foreach ($interfaces as $interface) {
            if (!is_array($interface)) {
                continue;
            }
            $name = (string) ($interface['name'] ?? '');
            if ($name === 'lo') {
                continue;
            }

            foreach (($interface['ip-addresses'] ?? []) as $ipInfo) {
                if (!is_array($ipInfo)) {
                    continue;
                }
                $ip = $this->sanitizeIpv6Address((string) (
                    $ipInfo['ip-address']
                    ?? $ipInfo['address']
                    ?? $ipInfo['local']
                    ?? $ipInfo['ip']
                    ?? ''
                ));
                $type = strtolower((string) ($ipInfo['ip-address-type'] ?? ''));
                if ($type === 'ipv6' && $ip !== null) {
                    return $ip;
                }
            }
        }

        return null;
    }

    private function getContainerIpAddress(Proxmox $proxmox, string $node, int $ctid): ?string
    {
        try {
            $response = $proxmox->get("/nodes/$node/lxc/$ctid/interfaces");
            $interfaces = $response['data'] ?? ($response['data']['result'] ?? []);
            $ip = $this->extractPrimaryIpv4(is_array($interfaces) ? $interfaces : []);
            if ($ip !== null) {
                return $ip;
            }
        } catch (\Throwable) {
            // Ignore; fallback paths below.
        }

        try {
            $status = $proxmox->get("/nodes/$node/lxc/$ctid/status/current");
            $statusIp = $this->sanitizeIpv4Address((string) ($status['data']['ip'] ?? ''));
            if ($statusIp !== null) {
                return $statusIp;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function extractPrimaryIpv4(array $interfaces): ?string
    {
        foreach ($interfaces as $interface) {
            if (!is_array($interface)) {
                continue;
            }
            $name = (string) ($interface['name'] ?? '');
            if ($name === 'lo') {
                continue;
            }

            $legacyInterfaceIp = $this->sanitizeIpv4Address((string) ($interface['inet'] ?? ''));
            if ($legacyInterfaceIp !== null) {
                return $legacyInterfaceIp;
            }

            foreach (($interface['ip-addresses'] ?? []) as $ipInfo) {
                if (!is_array($ipInfo)) {
                    continue;
                }
                $ip = $this->sanitizeIpv4Address((string) (
                    $ipInfo['ip-address']
                    ?? $ipInfo['address']
                    ?? $ipInfo['local']
                    ?? $ipInfo['ip']
                    ?? ''
                ));
                $type = strtolower((string) ($ipInfo['ip-address-type'] ?? ''));
                if ($type === 'ipv4' && $ip !== null) {
                    return $ip;
                }
                if ($type === '' && $ip !== null) {
                    return $ip;
                }
            }

            $deepScanIp = $this->findIpv4Deep($interface);
            if ($deepScanIp !== null) {
                return $deepScanIp;
            }
        }

        $fallbackIp = $this->findIpv4Deep($interfaces);
        if ($fallbackIp !== null) {
            return $fallbackIp;
        }

        return null;
    }

    private function findIpv4Deep(mixed $value): ?string
    {
        if (is_string($value)) {
            if (preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})(?:\/\d{1,2})?\b/', $value, $matches)) {
                return $this->sanitizeIpv4Address((string) ($matches[0] ?? ''));
            }

            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $child) {
            $ip = $this->findIpv4Deep($child);
            if ($ip !== null) {
                return $ip;
            }
        }

        return null;
    }

    private function sanitizeIpv4Address(string $rawValue): ?string
    {
        $value = trim($rawValue);
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '/')) {
            $value = (string) strstr($value, '/', true);
        }
        if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        if (str_starts_with($value, '127.')) {
            return null;
        }

        return $value;
    }

    private function sanitizeIpv6Address(string $rawValue): ?string
    {
        $value = trim($rawValue);
        if ($value === '') {
            return null;
        }

        if (str_contains($value, '/')) {
            $value = (string) strstr($value, '/', true);
        }
        if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return null;
        }
        if ($value === '::1' || str_starts_with(strtolower($value), 'fe80:')) {
            return null;
        }

        return $value;
    }

    private function waitForContainerIpAddress(Proxmox $proxmox, string $node, int $ctid, int $timeoutSeconds = 120): ?string
    {
        $start = time();
        while (time() - $start < $timeoutSeconds) {
            $ip = $this->getContainerIpAddress($proxmox, $node, $ctid);
            if ($ip !== null) {
                return $ip;
            }
            sleep(3);
        }

        return null;
    }

    private function extractDiskInfoFromVmConfig(array $configData): array
    {
        foreach (['scsi0', 'virtio0', 'sata0', 'ide0'] as $key) {
            if (empty($configData[$key]) || !is_string($configData[$key])) {
                continue;
            }

            $sizeGb = $this->parseDiskSizeToGb((string) $configData[$key]);
            if ($sizeGb <= 0) {
                continue;
            }

            $diskType = '';
            if (str_starts_with($key, 'sata')) {
                $diskType = 'sata';
            } elseif (str_starts_with($key, 'virtio') || str_starts_with($key, 'scsi')) {
                $diskType = 'ssd';
            }

            return [
                'size_gb' => $sizeGb,
                'disk_type' => $diskType,
            ];
        }

        return [
            'size_gb' => 0,
            'disk_type' => '',
        ];
    }

    private function parseDiskSizeToGb(string $configValue): int
    {
        if (!preg_match('/size=([0-9]+(?:\.[0-9]+)?)([KMGTP])/i', $configValue, $matches)) {
            return 0;
        }

        $value = (float) $matches[1];
        $unit = strtoupper($matches[2]);
        switch ($unit) {
            case 'T':
                $value *= 1024;
                break;
            case 'G':
                break;
            case 'M':
                $value /= 1024;
                break;
            case 'K':
                $value /= (1024 * 1024);
                break;
            case 'P':
                $value *= (1024 * 1024);
                break;
        }

        return max(1, (int) round($value));
    }

    private function generatePassword(int $length = 16): string
    {
        return bin2hex(random_bytes((int) ceil($length / 2)));
    }

    private function calculateMonthlyCost(int $vmCpu, int $vmMemGb, int $diskSizeGb, string $diskType, string $networkMode = self::NETWORK_MODE_PUBLIC): float
    {
        $diskRate = $this->getStorageMonthlyRate($diskType);
        $diskCost = $diskSizeGb * $diskRate;
        $cpuCost = $vmCpu * $this->getCpuHourlyRate() * 720;
        $memCost = $vmMemGb * $this->getMemoryHourlyRate() * 720;
        $ipCost = $this->getIpHourlyRate($networkMode) * 720;

        return round($diskCost + $cpuCost + $memCost + $ipCost, 2);
    }

    private function runHourlyUsageMetering(): array
    {
        $this->ensureBillingSchema();
        $rows = $this->di['db']->getAll(
            'SELECT id, client_id, order_id, vmid, disk_size, disk_type, monthly_cost, power_state FROM mod_vps_vm'
        );
        if ($rows === []) {
            return [
                'metered_rows' => 0,
                'inserted_rows' => 0,
                'updated_rows' => 0,
                'missing_order_rows' => 0,
            ];
        }

        $resourceByVmid = [];
        try {
            $resources = $this->getRootProxmox()->get('/cluster/resources');
            foreach (($resources['data'] ?? []) as $resource) {
                if (($resource['type'] ?? '') !== 'qemu') {
                    continue;
                }
                $vmid = (int) ($resource['vmid'] ?? 0);
                if ($vmid > 0) {
                    $resourceByVmid[$vmid] = $resource;
                }
            }
        } catch (\Throwable $e) {
            $this->di['logger']->warning('VPS metering: unable to load cluster resources: ' . $e->getMessage());
        }

        $periodHour = date('Y-m-d H:00:00');
        $created = 0;
        $updated = 0;
        $missingOrderRows = 0;
        $snapshotSizesByVmid = [];
        $snapshotTotals = $this->di['db']->getAll(
            'SELECT vmid, SUM(size_gb) AS total_size_gb
             FROM mod_vps_snapshot
             WHERE status = :status
             GROUP BY vmid',
            [':status' => 'active']
        );
        foreach ($snapshotTotals as $snapshotRow) {
            $snapshotSizesByVmid[(int) ($snapshotRow['vmid'] ?? 0)] = (float) ($snapshotRow['total_size_gb'] ?? 0);
        }

        foreach ($rows as $row) {
            $vmid = (int) $row['vmid'];
            $status = (string) (($resourceByVmid[$vmid]['status'] ?? ($row['power_state'] ?? 'missing')));
            $isRunning = $status === 'running';
            $resolvedOrderId = !empty($row['order_id']) ? (int) $row['order_id'] : $this->resolveClientVpsOrderId((int) $row['client_id']);
            if (empty($row['order_id']) && $resolvedOrderId !== null) {
                $this->di['db']->exec(
                    'UPDATE mod_vps_vm SET order_id = :order_id, updated_at = :updated_at WHERE id = :id',
                    [
                        ':order_id' => $resolvedOrderId,
                        ':updated_at' => date('Y-m-d H:i:s'),
                        ':id' => (int) $row['id'],
                    ]
                );
            }
            if ($resolvedOrderId === null || $resolvedOrderId <= 0) {
                ++$missingOrderRows;
            }

            $hourlyRate = $this->calculateHourlyRateForState($row, $isRunning, (float) ($snapshotSizesByVmid[$vmid] ?? 0));
            $hours = $isRunning ? 1.0 : 0.0;

            $existing = $this->di['db']->getCell(
                'SELECT id FROM mod_vps_usage_hourly WHERE vmid = :vmid AND period_hour = :period_hour',
                [
                    ':vmid' => $vmid,
                    ':period_hour' => $periodHour,
                ]
            );

            if ($existing) {
                $this->di['db']->exec(
                    'UPDATE mod_vps_usage_hourly
                     SET state = :state, hours = :hours, rate = :rate, amount = :amount, order_id = :order_id, updated_at = :updated_at
                     WHERE id = :id',
                    [
                        ':state' => $status,
                        ':hours' => $hours,
                        ':rate' => $hourlyRate,
                        ':amount' => $hourlyRate,
                        ':order_id' => $resolvedOrderId,
                        ':updated_at' => date('Y-m-d H:i:s'),
                        ':id' => (int) $existing,
                    ]
                );
                ++$updated;
                continue;
            }

            $now = date('Y-m-d H:i:s');
            $this->di['db']->exec(
                'INSERT INTO mod_vps_usage_hourly
                    (client_id, order_id, vmid, period_hour, state, hours, rate, amount, created_at, updated_at)
                 VALUES
                    (:client_id, :order_id, :vmid, :period_hour, :state, :hours, :rate, :amount, :created_at, :updated_at)',
                [
                    ':client_id' => (int) $row['client_id'],
                    ':order_id' => $resolvedOrderId,
                    ':vmid' => $vmid,
                    ':period_hour' => $periodHour,
                    ':state' => $status,
                    ':hours' => $hours,
                    ':rate' => $hourlyRate,
                    ':amount' => $hourlyRate,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
            ++$created;
        }

        if ($created > 0) {
            $this->di['logger']->info('VPS metering: recorded %s hourly usage rows', $created);
        }

        $containerMetering = $this->runContainerHourlyUsageMetering($periodHour);

        $containerMetered = (int) ($containerMetering['metered_rows'] ?? 0);
        $containerInserted = (int) ($containerMetering['inserted_rows'] ?? 0);
        $containerUpdated = (int) ($containerMetering['updated_rows'] ?? 0);
        $containerMissingOrder = (int) ($containerMetering['missing_order_rows'] ?? 0);

        return [
            'metered_rows' => count($rows) + $containerMetered,
            'inserted_rows' => $created + $containerInserted,
            'updated_rows' => $updated + $containerUpdated,
            'missing_order_rows' => $missingOrderRows + $containerMissingOrder,
            'container_metered_rows' => (int) ($containerMetering['metered_rows'] ?? 0),
            'container_inserted_rows' => (int) ($containerMetering['inserted_rows'] ?? 0),
            'container_updated_rows' => (int) ($containerMetering['updated_rows'] ?? 0),
            'container_missing_order_rows' => (int) ($containerMetering['missing_order_rows'] ?? 0),
        ];
    }

    private function runContainerHourlyUsageMetering(?string $periodHour = null): array
    {
        $rows = $this->di['db']->getAll(
            'SELECT id, client_id, order_id, ctid, storage, disk_size, disk_type, network_mode, monthly_cost, power_state
             FROM mod_vps_container'
        );
        if ($rows === []) {
            return [
                'metered_rows' => 0,
                'inserted_rows' => 0,
                'updated_rows' => 0,
                'missing_order_rows' => 0,
            ];
        }

        $resourceByCtid = [];
        try {
            $resources = $this->getRootProxmox()->get('/cluster/resources');
            foreach (($resources['data'] ?? []) as $resource) {
                if (($resource['type'] ?? '') !== 'lxc') {
                    continue;
                }
                $ctid = (int) ($resource['vmid'] ?? 0);
                if ($ctid > 0) {
                    $resourceByCtid[$ctid] = $resource;
                }
            }
        } catch (\Throwable $e) {
            $this->di['logger']->warning('VPS container metering: unable to load cluster resources: ' . $e->getMessage());
        }

        $meterPeriodHour = $periodHour ?: date('Y-m-d H:00:00');
        $created = 0;
        $updated = 0;
        $missingOrderRows = 0;

        foreach ($rows as $row) {
            $ctid = (int) ($row['ctid'] ?? 0);
            $resource = $resourceByCtid[$ctid] ?? [];
            $status = (string) (($resource['status'] ?? ($row['power_state'] ?? 'missing')));
            $isRunning = $status === 'running';
            $resolvedOrderId = !empty($row['order_id']) ? (int) $row['order_id'] : $this->resolveClientVpsOrderId((int) $row['client_id']);
            if (empty($row['order_id']) && $resolvedOrderId !== null) {
                $this->di['db']->exec(
                    'UPDATE mod_vps_container SET order_id = :order_id, updated_at = :updated_at WHERE id = :id',
                    [
                        ':order_id' => $resolvedOrderId,
                        ':updated_at' => date('Y-m-d H:i:s'),
                        ':id' => (int) $row['id'],
                    ]
                );
            }
            if ($resolvedOrderId === null || $resolvedOrderId <= 0) {
                ++$missingOrderRows;
            }

            $diskType = (string) ($row['disk_type'] ?? '');
            if ($diskType === '') {
                $diskType = stripos((string) ($row['storage'] ?? ''), 'fast') !== false ? 'ssd' : 'sata';
            }
            $diskSize = (int) ($row['disk_size'] ?? 0);
            if ($diskSize <= 0 && isset($resource['maxdisk']) && (int) $resource['maxdisk'] > 0) {
                $diskSize = (int) max(1, round(((int) $resource['maxdisk']) / 1073741824));
            }
            $monthlyCost = (float) ($row['monthly_cost'] ?? 0);
            if ($monthlyCost <= 0) {
                $monthlyCost = $this->calculateContainerMonthlyCost(
                    max(1, $diskSize),
                    $diskType,
                    (string) ($row['network_mode'] ?? self::NETWORK_MODE_PUBLIC)
                );
                $this->di['db']->exec(
                    'UPDATE mod_vps_container
                     SET disk_size = :disk_size, disk_type = :disk_type, monthly_cost = :monthly_cost, updated_at = :updated_at
                     WHERE id = :id',
                    [
                        ':disk_size' => max(1, $diskSize),
                        ':disk_type' => $diskType,
                        ':monthly_cost' => $monthlyCost,
                        ':updated_at' => date('Y-m-d H:i:s'),
                        ':id' => (int) $row['id'],
                    ]
                );
            }

            $hourlyRate = round(max(0.0, $monthlyCost) / 720, 6);
            $hours = $isRunning ? 1.0 : 0.0;
            $amount = $isRunning ? $hourlyRate : 0.0;

            $existing = $this->di['db']->getCell(
                'SELECT id
                 FROM mod_vps_container_usage_hourly
                 WHERE ctid = :ctid AND period_hour = :period_hour',
                [
                    ':ctid' => $ctid,
                    ':period_hour' => $meterPeriodHour,
                ]
            );

            if ($existing) {
                $this->di['db']->exec(
                    'UPDATE mod_vps_container_usage_hourly
                     SET state = :state, hours = :hours, rate = :rate, amount = :amount, order_id = :order_id, updated_at = :updated_at
                     WHERE id = :id',
                    [
                        ':state' => $status,
                        ':hours' => $hours,
                        ':rate' => $hourlyRate,
                        ':amount' => $amount,
                        ':order_id' => $resolvedOrderId,
                        ':updated_at' => date('Y-m-d H:i:s'),
                        ':id' => (int) $existing,
                    ]
                );
                ++$updated;
                continue;
            }

            $now = date('Y-m-d H:i:s');
            $this->di['db']->exec(
                'INSERT INTO mod_vps_container_usage_hourly
                    (client_id, order_id, ctid, period_hour, state, hours, rate, amount, created_at, updated_at)
                 VALUES
                    (:client_id, :order_id, :ctid, :period_hour, :state, :hours, :rate, :amount, :created_at, :updated_at)',
                [
                    ':client_id' => (int) $row['client_id'],
                    ':order_id' => $resolvedOrderId,
                    ':ctid' => $ctid,
                    ':period_hour' => $meterPeriodHour,
                    ':state' => $status,
                    ':hours' => $hours,
                    ':rate' => $hourlyRate,
                    ':amount' => $amount,
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]
            );
            ++$created;
        }

        if ($created > 0) {
            $this->di['logger']->info('VPS container metering: recorded %s hourly usage rows', $created);
        }

        return [
            'metered_rows' => count($rows),
            'inserted_rows' => $created,
            'updated_rows' => $updated,
            'missing_order_rows' => $missingOrderRows,
        ];
    }

    private function generateMonthlyUsageInvoices(): array
    {
        $this->ensureBillingSchema();
        $monthStart = date('Y-m-01 00:00:00');
        $vmRows = $this->di['db']->getAll(
            'SELECT id, client_id, order_id, vmid, period_hour, amount, hours
             FROM mod_vps_usage_hourly
             WHERE invoiced_at IS NULL
               AND period_hour < :month_start
             ORDER BY client_id ASC, order_id ASC, period_hour ASC, vmid ASC',
            [':month_start' => $monthStart]
        );
        $containerRows = $this->di['db']->getAll(
            'SELECT id, client_id, order_id, ctid, period_hour, amount, hours
             FROM mod_vps_container_usage_hourly
             WHERE invoiced_at IS NULL
               AND period_hour < :month_start
             ORDER BY client_id ASC, order_id ASC, period_hour ASC, ctid ASC',
            [':month_start' => $monthStart]
        );
        if ($vmRows === [] && $containerRows === []) {
            return [
                'invoiced_groups' => 0,
                'invoiced_usage_rows' => 0,
                'generated_invoice_ids' => [],
            ];
        }

        $groups = [];
        foreach ($vmRows as $row) {
            $orderId = isset($row['order_id']) ? (int) $row['order_id'] : 0;
            if ($orderId <= 0) {
                continue;
            }
            $periodMonth = substr((string) $row['period_hour'], 0, 7);
            $key = (int) $row['client_id'] . ':' . $orderId . ':' . $periodMonth;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'client_id' => (int) $row['client_id'],
                    'order_id' => $orderId,
                    'period_month' => $periodMonth,
                    'vm_total_amount' => 0.0,
                    'vm_running_hours' => 0.0,
                    'vm_usage_ids' => [],
                    'container_total_amount' => 0.0,
                    'container_running_hours' => 0.0,
                    'container_usage_ids' => [],
                ];
            }
            $groups[$key]['vm_total_amount'] += (float) $row['amount'];
            $groups[$key]['vm_running_hours'] += (float) $row['hours'];
            $groups[$key]['vm_usage_ids'][] = (int) $row['id'];
        }

        foreach ($containerRows as $row) {
            $orderId = isset($row['order_id']) ? (int) $row['order_id'] : 0;
            if ($orderId <= 0) {
                continue;
            }
            $periodMonth = substr((string) $row['period_hour'], 0, 7);
            $key = (int) $row['client_id'] . ':' . $orderId . ':' . $periodMonth;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'client_id' => (int) $row['client_id'],
                    'order_id' => $orderId,
                    'period_month' => $periodMonth,
                    'vm_total_amount' => 0.0,
                    'vm_running_hours' => 0.0,
                    'vm_usage_ids' => [],
                    'container_total_amount' => 0.0,
                    'container_running_hours' => 0.0,
                    'container_usage_ids' => [],
                ];
            }
            $groups[$key]['container_total_amount'] += (float) $row['amount'];
            $groups[$key]['container_running_hours'] += (float) $row['hours'];
            $groups[$key]['container_usage_ids'][] = (int) $row['id'];
        }

        $invoiceService = $this->di['mod_service']('Invoice');
        $generatedCount = 0;
        $invoicedUsageRows = 0;
        $generatedInvoiceIds = [];

        foreach ($groups as $group) {
            $vmAmount = round((float) ($group['vm_total_amount'] ?? 0), 2);
            $containerAmount = round((float) ($group['container_total_amount'] ?? 0), 2);
            if ($vmAmount <= 0 && $containerAmount <= 0) {
                continue;
            }

            $client = $this->di['db']->findOne('Client', 'id = :id', [':id' => $group['client_id']]);
            if (!$client instanceof \Model_Client) {
                $this->di['logger']->warning('VPS billing: client not found for usage group (client_id=' . $group['client_id'] . ')');
                continue;
            }

            $order = $this->di['db']->findOne(
                'ClientOrder',
                'id = :id AND client_id = :client_id AND service_type = :service_type',
                [
                    ':id' => $group['order_id'],
                    ':client_id' => $group['client_id'],
                    ':service_type' => 'vps',
                ]
            );
            if (!$order instanceof \Model_ClientOrder) {
                $this->di['logger']->warning('VPS billing: order not found for usage group (order_id=' . $group['order_id'] . ')');
                continue;
            }

            $invoiceItems = [];
            if ($vmAmount > 0) {
                $invoiceItems[] = [
                    'title' => sprintf(
                        'VPS hourly usage for %s (order #%d, running hours %.2f)',
                        $group['period_month'],
                        $group['order_id'],
                        (float) ($group['vm_running_hours'] ?? 0)
                    ),
                    'price' => $vmAmount,
                    'quantity' => 1,
                    'type' => \Model_InvoiceItem::TYPE_CUSTOM,
                    'task' => \Model_InvoiceItem::TASK_VOID,
                    'taxed' => false,
                ];
            }
            if ($containerAmount > 0) {
                $invoiceItems[] = [
                    'title' => sprintf(
                        'Containers hourly usage for %s (order #%d, running hours %.2f)',
                        $group['period_month'],
                        $group['order_id'],
                        (float) ($group['container_running_hours'] ?? 0)
                    ),
                    'price' => $containerAmount,
                    'quantity' => 1,
                    'type' => \Model_InvoiceItem::TYPE_CUSTOM,
                    'task' => \Model_InvoiceItem::TASK_VOID,
                    'taxed' => false,
                ];
            }
            if ($invoiceItems === []) {
                continue;
            }

            $invoice = $invoiceService->prepareInvoice($client, [
                'items' => $invoiceItems,
            ]);
            $invoiceService->approveInvoice($invoice, ['id' => $invoice->id, 'use_credits' => true]);

            $now = date('Y-m-d H:i:s');
            foreach (($group['vm_usage_ids'] ?? []) as $usageId) {
                $this->di['db']->exec(
                    'UPDATE mod_vps_usage_hourly
                     SET invoice_id = :invoice_id, invoiced_at = :invoiced_at, updated_at = :updated_at
                     WHERE id = :id AND invoiced_at IS NULL',
                    [
                        ':invoice_id' => (int) $invoice->id,
                        ':invoiced_at' => $now,
                        ':updated_at' => $now,
                        ':id' => $usageId,
                    ]
                );
                ++$invoicedUsageRows;
            }
            foreach (($group['container_usage_ids'] ?? []) as $usageId) {
                $this->di['db']->exec(
                    'UPDATE mod_vps_container_usage_hourly
                     SET invoice_id = :invoice_id, invoiced_at = :invoiced_at, updated_at = :updated_at
                     WHERE id = :id AND invoiced_at IS NULL',
                    [
                        ':invoice_id' => (int) $invoice->id,
                        ':invoiced_at' => $now,
                        ':updated_at' => $now,
                        ':id' => $usageId,
                    ]
                );
                ++$invoicedUsageRows;
            }
            $generatedInvoiceIds[] = (int) $invoice->id;
            ++$generatedCount;
        }

        if ($generatedCount > 0) {
            $this->di['logger']->info('VPS billing: generated %s monthly usage invoices', $generatedCount);
        }

        return [
            'invoiced_groups' => $generatedCount,
            'invoiced_usage_rows' => $invoicedUsageRows,
            'generated_invoice_ids' => array_values(array_unique($generatedInvoiceIds)),
        ];
    }

    private function recordBillingRun(array $metering, array $invoicing): void
    {
        $this->ensureBillingSchema();
        $now = date('Y-m-d H:i:s');
        $noteParts = [];
        if (($metering['missing_order_rows'] ?? 0) > 0) {
            $noteParts[] = 'usage rows without resolved order_id detected';
        }
        if (($invoicing['invoiced_groups'] ?? 0) > 0) {
            $noteParts[] = 'monthly usage invoices generated';
        }

        $this->di['db']->exec(
            'INSERT INTO mod_vps_billing_run
                (run_at, metered_rows, inserted_rows, updated_rows, missing_order_rows, invoiced_groups, invoiced_usage_rows, generated_invoice_ids, notes, created_at, updated_at)
             VALUES
                (:run_at, :metered_rows, :inserted_rows, :updated_rows, :missing_order_rows, :invoiced_groups, :invoiced_usage_rows, :generated_invoice_ids, :notes, :created_at, :updated_at)',
            [
                ':run_at' => $now,
                ':metered_rows' => (int) ($metering['metered_rows'] ?? 0),
                ':inserted_rows' => (int) ($metering['inserted_rows'] ?? 0),
                ':updated_rows' => (int) ($metering['updated_rows'] ?? 0),
                ':missing_order_rows' => (int) ($metering['missing_order_rows'] ?? 0),
                ':invoiced_groups' => (int) ($invoicing['invoiced_groups'] ?? 0),
                ':invoiced_usage_rows' => (int) ($invoicing['invoiced_usage_rows'] ?? 0),
                ':generated_invoice_ids' => json_encode(array_values(array_map('intval', $invoicing['generated_invoice_ids'] ?? []))),
                ':notes' => implode('; ', $noteParts),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
    }

    private function calculateHourlyRateForState(array $vmRow, bool $isRunning, float $snapshotSizeGb = 0.0): float
    {
        $monthlyCost = (float) ($vmRow['monthly_cost'] ?? 0);
        $diskSizeGb = (int) ($vmRow['disk_size'] ?? 0);
        $diskType = (string) ($vmRow['disk_type'] ?? 'sata');

        $diskMonthly = $diskSizeGb * $this->getStorageMonthlyRate($diskType);
        $computeMonthly = max(0.0, $monthlyCost - $diskMonthly);
        // Snapshot storage is billed using SATA disk rates for as long as snapshots exist.
        $snapshotMonthly = max(0.0, $snapshotSizeGb) * $this->getStorageMonthlyRate('sata');
        $effectiveMonthly = $diskMonthly + $snapshotMonthly + ($isRunning ? $computeMonthly : 0.0);

        return round($effectiveMonthly / 720, 6);
    }

    private function estimateSnapshotSizeGb(int $vmid): float
    {
        $vmRow = $this->di['db']->getRow(
            'SELECT disk_size FROM mod_vps_vm WHERE vmid = :vmid LIMIT 1',
            [':vmid' => $vmid]
        );
        $fallbackDiskGb = max(1, (int) ($vmRow['disk_size'] ?? 1));

        try {
            $resources = $this->getRootProxmox()->get('/cluster/resources');
            foreach (($resources['data'] ?? []) as $resource) {
                if (($resource['type'] ?? '') !== 'qemu' || (int) ($resource['vmid'] ?? 0) !== $vmid) {
                    continue;
                }

                if (isset($resource['disk']) && (int) $resource['disk'] > 0) {
                    return round(max(0.1, ((int) $resource['disk']) / 1073741824), 2);
                }
                if (isset($resource['maxdisk']) && (int) $resource['maxdisk'] > 0) {
                    return round(max(0.1, ((int) $resource['maxdisk']) / 1073741824), 2);
                }
                break;
            }
        } catch (\Throwable $e) {
            $this->di['logger']->warning('VPS snapshot size estimate failed for VM ' . $vmid . ': ' . $e->getMessage());
        }

        return round((float) $fallbackDiskGb, 2);
    }

    private function upsertSnapshotRecord(int $clientId, int $vmid, string $snapshotName, float $sizeGb): void
    {
        $orderId = $this->resolveClientVpsOrderId($clientId);
        $existingId = $this->di['db']->getCell(
            'SELECT id
             FROM mod_vps_snapshot
             WHERE client_id = :client_id AND vmid = :vmid AND snapshot_name = :snapshot_name
             LIMIT 1',
            [
                ':client_id' => $clientId,
                ':vmid' => $vmid,
                ':snapshot_name' => $snapshotName,
            ]
        );

        $now = date('Y-m-d H:i:s');
        if ($existingId) {
            $this->di['db']->exec(
                'UPDATE mod_vps_snapshot
                 SET order_id = :order_id, size_gb = :size_gb, status = :status, updated_at = :updated_at
                 WHERE id = :id',
                [
                    ':order_id' => $orderId,
                    ':size_gb' => round(max(0, $sizeGb), 2),
                    ':status' => 'active',
                    ':updated_at' => $now,
                    ':id' => (int) $existingId,
                ]
            );

            return;
        }

        $this->di['db']->exec(
            'INSERT INTO mod_vps_snapshot
                (client_id, order_id, vmid, snapshot_name, size_gb, status, created_at, updated_at)
             VALUES
                (:client_id, :order_id, :vmid, :snapshot_name, :size_gb, :status, :created_at, :updated_at)',
            [
                ':client_id' => $clientId,
                ':order_id' => $orderId,
                ':vmid' => $vmid,
                ':snapshot_name' => $snapshotName,
                ':size_gb' => round(max(0, $sizeGb), 2),
                ':status' => 'active',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]
        );
    }

    private function resolveStorageForDiskType(string $diskType): string
    {
        return strtolower($diskType) === 'ssd' ? self::STORAGE_FAST : self::STORAGE_SLOW;
    }

    private function configureVmIpv6OnlyNetwork(Proxmox $proxmox, string $node, int $vmid): void
    {
        try {
            $configResponse = $proxmox->get("/nodes/$node/qemu/$vmid/config");
        } catch (\Throwable) {
            $configResponse = [];
        }

        $existingNet0 = (string) (($configResponse['data']['net0'] ?? ''));
        $net0 = $this->buildIpv6OnlyVmNet0($existingNet0);

        $proxmox->set("/nodes/$node/qemu/$vmid/config", [
            'net0' => $net0,
            'ipconfig0' => 'ip=manual,ip6=dhcp',
        ]);
    }

    private function buildIpv6OnlyVmNet0(string $existingNet0): string
    {
        $parts = array_filter(array_map('trim', explode(',', $existingNet0)), static fn ($part): bool => $part !== '');
        $filtered = [];
        foreach ($parts as $part) {
            if (str_starts_with($part, 'bridge=')
                || str_starts_with($part, 'tag=')
                || str_starts_with($part, 'ip=')
                || str_starts_with($part, 'ip6=')
            ) {
                continue;
            }
            $filtered[] = $part;
        }

        if ($filtered === []) {
            $filtered[] = 'virtio';
        }
        $filtered[] = 'bridge=vmbr0';
        $filtered[] = 'tag=99';

        return implode(',', $filtered);
    }

    private function resolveTemplateVmid(string $osSlug, string $diskType): int
    {
        $templateMap = $this->getTemplateMap($diskType);
        if (!isset($templateMap[$osSlug])) {
            throw new InformationException('No provisioning template configured for selected OS and disk type', [], 400);
        }

        return (int) $templateMap[$osSlug];
    }

    private function normalizeProvisionRequestData(array $data): array
    {
        $vmName = trim((string) ($data['vm_name'] ?? ''));
        $osSlug = (string) ($data['vm_os'] ?? '');
        $vmCpu = (int) ($data['vm_cpu'] ?? 2);
        $vmMemGb = (int) ($data['vm_mem'] ?? 4);
        $diskSize = (int) ($data['vm_disk_size'] ?? 10);
        $diskType = (string) ($data['vm_disk_type'] ?? 'sata');
        $networkMode = $this->normalizeNetworkMode((string) ($data['network_mode'] ?? self::NETWORK_MODE_PUBLIC));

        if ($vmName === '') {
            throw new InformationException('VM name is required', [], 400);
        }
        $templateMap = $this->getTemplateMap($diskType);
        if (!isset($templateMap[$osSlug])) {
            throw new InformationException('Invalid operating system selected', [], 400);
        }
        if ($diskSize < 10 || $diskSize > 1000) {
            throw new InformationException('Disk size must be between 10 and 1000 GB', [], 400);
        }
        if ($vmCpu < 1 || $vmCpu > 64) {
            throw new InformationException('vCPU must be between 1 and 64', [], 400);
        }
        if ($vmMemGb < 1 || $vmMemGb > 256) {
            throw new InformationException('Memory must be between 1 and 256 GB', [], 400);
        }
        if (!in_array(strtolower($diskType), ['sata', 'ssd'], true)) {
            throw new InformationException('Invalid disk type selected', [], 400);
        }

        return [
            'vm_name' => $vmName,
            'vm_os' => $osSlug,
            'vm_cpu' => $vmCpu,
            'vm_mem' => $vmMemGb,
            'vm_disk_size' => $diskSize,
            'vm_disk_type' => strtolower($diskType),
            'network_mode' => $networkMode,
        ];
    }

    private function normalizeContainerRequestData(array $data): array
    {
        $name = trim((string) ($data['ct_name'] ?? $data['container_name'] ?? ''));
        $template = trim((string) ($data['template'] ?? ''));
        $diskSize = (int) ($data['ct_disk_size'] ?? 10);
        $diskType = strtolower(trim((string) ($data['ct_disk_type'] ?? 'sata')));
        $networkMode = $this->normalizeNetworkMode((string) ($data['network_mode'] ?? self::NETWORK_MODE_PUBLIC));

        if ($name === '') {
            throw new InformationException('Container name is required', [], 400);
        }
        if ($template === '') {
            throw new InformationException('Container template is required', [], 400);
        }
        if ($diskSize < 10 || $diskSize > 1000) {
            throw new InformationException('Container disk size must be between 10 and 1000 GB', [], 400);
        }
        if (!in_array($diskType, ['sata', 'ssd'], true)) {
            throw new InformationException('Invalid container disk type selected', [], 400);
        }
        return [
            'ct_name' => $name,
            'template' => $template,
            'ct_disk_size' => $diskSize,
            'ct_disk_type' => $diskType,
            'network_mode' => $networkMode,
        ];
    }

    private function provisionContainer(\Model_Client $client, array $data): array
    {
        $clientId = (int) $client->id;
        $request = $this->normalizeContainerRequestData($data);
        $monthlyCost = $this->calculateContainerMonthlyCost($request['ct_disk_size'], $request['ct_disk_type'], (string) ($request['network_mode'] ?? self::NETWORK_MODE_PUBLIC));
        $this->assertProvisionEligibility($client, $monthlyCost, 'container provision');

        $this->ensureProxmoxUser($clientId);
        $proxmox = $this->getRootProxmox();
        $node = $this->getProxmoxNode();
        $ctid = $this->allocateGuestId($proxmox);
        $rootPassword = $this->generatePassword(16);
        $storage = $this->resolveStorageForDiskType($request['ct_disk_type']);
        $networkMode = $request['network_mode'];
        $bridge = $networkMode === self::NETWORK_MODE_INTERNAL ? 'intbr0' : 'vmbr0';
        $tag = $networkMode === self::NETWORK_MODE_INTERNAL ? 200 : ($networkMode === self::NETWORK_MODE_IPV6_ONLY ? 110 : 100);
        $ipv4Mode = $networkMode === self::NETWORK_MODE_IPV6_ONLY ? 'manual' : 'dhcp';
        $networkConfig = sprintf('name=eth0,bridge=%s,tag=%d,ip=%s,ip6=dhcp', $bridge, $tag, $ipv4Mode);
        $hostname = preg_replace('/[^a-zA-Z0-9.-]/', '-', strtolower($request['ct_name']));
        $hostname = trim((string) $hostname, '-.');
        if ($hostname === '') {
            $hostname = 'ct-' . $ctid;
        }

        $response = $proxmox->create("/nodes/$node/lxc", [
            'vmid' => $ctid,
            'hostname' => $hostname,
            'ostemplate' => $request['template'],
            'password' => $rootPassword,
            'storage' => $storage,
            'rootfs' => $storage . ':' . $request['ct_disk_size'],
            'net0' => $networkConfig,
            'unprivileged' => 1,
            'onboot' => 1,
            'start' => 1,
        ]);

        if (empty($response['data'])) {
            throw new InformationException('Failed to start container provisioning task', [], 500);
        }
        $this->waitForTask($proxmox, $node, (string) $response['data'], 900);

        $ipAddress = $this->getContainerIpAddress($proxmox, $node, $ctid);
        $now = date('Y-m-d H:i:s');
        $record = $this->di['db']->dispense('mod_vps_container');
        $record->client_id = $clientId;
        $record->order_id = $this->resolveClientVpsOrderId($clientId);
        $record->ctid = $ctid;
        $record->container_name = $request['ct_name'];
        $record->template = $request['template'];
        $record->storage = $storage;
        $record->network_mode = $networkMode;
        $record->ip_address = $ipAddress;
        $record->disk_size = (int) $request['ct_disk_size'];
        $record->disk_type = (string) $request['ct_disk_type'];
        $record->monthly_cost = $monthlyCost;
        $record->power_state = 'running';
        $record->state_synced_at = $now;
        $record->root_password = $this->di['crypt']->encrypt($rootPassword, Config::getProperty('info.salt'));
        $record->created_at = $now;
        $record->updated_at = $now;
        $this->di['db']->store($record);

        $proxmox->set('/access/acl', [
            'path' => '/vms/' . $ctid,
            'users' => $this->getProxmoxUserId($clientId),
            'roles' => 'PVEVMUser',
        ]);

        return [
            'ctid' => $ctid,
            'name' => $request['ct_name'],
            'root_password' => $rootPassword,
            'template' => $request['template'],
            'storage' => $storage,
            'disk_size' => $request['ct_disk_size'],
            'disk_type' => $request['ct_disk_type'],
            'network_mode' => $networkMode,
            'ip_address' => $ipAddress,
            'monthly_cost' => $monthlyCost,
            'access_hints' => $this->buildContainerTemplateAccessHints($request['template'], $ipAddress, $request['template']),
            'message' => 'Container provisioned successfully',
        ];
    }

    private function buildContainerTemplateAccessHints(string $templateLabel, ?string $ipAddress = null, ?string $templateVolid = null): array
    {
        $source = trim($templateVolid ?: $templateLabel);
        $sourceLower = strtolower($source);
        $basename = strtolower((string) preg_replace('/^.*vztmpl\//i', '', $sourceLower));
        $basename = (string) preg_replace('/\.tar(?:\.[a-z0-9]+)?$/i', '', $basename);
        $slug = trim((string) preg_replace('/_[0-9].*$/', '', $basename));

        $likelyWebUrl = null;
        $likelyAdminPath = null;
        $sshCommand = null;
        $usernameHint = null;
        $notes = [];

        $applicationName = $this->detectContainerApplicationName($slug);
        $isLaravel = $applicationName === 'Laravel';
        $looksWebTemplate = preg_match('/(laravel|wordpress|nextcloud|drupal|joomla|gitlab|gitea|mattermost|mediawiki|prestashop)/i', $slug) === 1;
        if (!empty($ipAddress)) {
            $likelyWebUrl = 'http://' . $ipAddress;
            $sshCommand = 'ssh root@' . $ipAddress;
        } else {
            $notes[] = 'IP address is still being detected via DHCP. Refresh Access details in a moment.';
        }
        if ($applicationName === 'WordPress') {
            $likelyAdminPath = '/wp-admin';
        } elseif ($applicationName === 'Nextcloud') {
            $likelyAdminPath = '/login';
        }
        if ($isLaravel && !empty($ipAddress)) {
            $notes[] = 'Use SSH to complete Laravel app/server bootstrap on first login.';
        }

        if (str_contains($slug, 'turnkey')) {
            $usernameHint = 'TurnKey Linux usually keeps root as the shell login.';
            $notes[] = 'TurnKey appliances often generate app login credentials on first boot.';
            $notes[] = 'Check /root/ for credentials files after first login.';
        } elseif (str_contains($slug, 'ubuntu') || str_contains($slug, 'debian') || str_contains($slug, 'alpine') || str_contains($slug, 'centos')) {
            $usernameHint = 'Use root with the password shown in Access details.';
        }

        if ($likelyWebUrl !== null) {
            $notes[] = 'Open the URL below and SSH to root to begin installation/configuration.';
        }

        $likelyAdminUrl = null;
        if ($likelyWebUrl !== null) {
            $likelyAdminUrl = $likelyWebUrl . ($likelyAdminPath ?? '');
        }

        return [
            'template_label' => $templateLabel,
            'template_slug' => $slug,
            'application_name' => $applicationName,
            'likely_web_url' => $likelyWebUrl,
            'likely_admin_path' => $likelyAdminPath,
            'likely_admin_url' => $likelyAdminUrl,
            'ssh_command' => $sshCommand,
            'username_hint' => $usernameHint,
            'notes' => $notes,
        ];
    }

    private function detectContainerApplicationName(string $templateLabel): ?string
    {
        $source = strtolower(trim($templateLabel));
        if ($source === '') {
            return null;
        }

        $knownApps = [
            'wordpress' => 'WordPress',
            'nextcloud' => 'Nextcloud',
            'drupal' => 'Drupal',
            'joomla' => 'Joomla',
            'gitlab' => 'GitLab',
            'gitea' => 'Gitea',
            'mattermost' => 'Mattermost',
            'mediawiki' => 'MediaWiki',
            'prestashop' => 'PrestaShop',
            'laravel' => 'Laravel',
        ];

        $matches = [];
        foreach ($knownApps as $needle => $name) {
            $pos = strpos($source, $needle);
            if ($pos !== false) {
                $matches[$pos] = $name;
            }
        }

        if ($matches === []) {
            return null;
        }

        ksort($matches);

        return reset($matches);
    }

    private function calculateContainerMonthlyCost(int $diskSizeGb, string $diskType, string $networkMode = self::NETWORK_MODE_PUBLIC): float
    {
        $safeSize = max(10, min(1000, $diskSizeGb));
        $diskRate = $this->getStorageMonthlyRate($diskType);
        $networkCost = $this->getNetworkIpMonthlyRate($networkMode);

        return round(($safeSize * $diskRate) + $networkCost, 2);
    }

    private function getStorageMonthlyRate(string $diskType): float
    {
        return round($this->getStorageHourlyRate($diskType) * 720, 6);
    }

    private function getStorageHourlyRate(string $diskType): float
    {
        $config = $this->getModuleConfig();
        $isFast = strtolower($diskType) === 'ssd';
        $defaultRate = $isFast ? 0.0006944444 : 0.0002777778;
        $configuredRate = (float) ($config[$isFast ? 'storage_fast_hourly_rate' : 'storage_slow_hourly_rate'] ?? $defaultRate);

        return max(0.0, $configuredRate);
    }

    private function getCpuHourlyRate(): float
    {
        $config = $this->getModuleConfig();
        $configuredRate = (float) ($config['cpu_hourly_rate'] ?? 0.0018055556);

        return max(0.0, $configuredRate);
    }

    private function getMemoryHourlyRate(): float
    {
        $config = $this->getModuleConfig();
        $configuredRate = (float) ($config['memory_hourly_rate'] ?? 0.0016666667);

        return max(0.0, $configuredRate);
    }

    private function normalizeNetworkMode(string $networkMode): string
    {
        $value = strtolower(trim($networkMode));
        if (in_array($value, ['private', 'internal_network', 'internal-only', 'internal'], true)) {
            return self::NETWORK_MODE_INTERNAL;
        }
        if (in_array($value, ['ipv6', 'ipv6-only', 'ipv6_only'], true)) {
            return self::NETWORK_MODE_IPV6_ONLY;
        }
        if (in_array($value, ['public', 'publicly', 'publicly_accessible', 'publically_accessible'], true)) {
            return self::NETWORK_MODE_PUBLIC;
        }

        return self::NETWORK_MODE_PUBLIC;
    }

    private function getNetworkIpMonthlyRate(string $networkMode = self::NETWORK_MODE_PUBLIC): float
    {
        $config = $this->getModuleConfig();
        $normalizedMode = $this->normalizeNetworkMode($networkMode);
        $legacyDefault = max(0.0, (float) ($config['ip_hourly_rate'] ?? 0.0) * 720);

        $key = match ($normalizedMode) {
            self::NETWORK_MODE_INTERNAL => 'ip_internal_monthly_rate',
            self::NETWORK_MODE_IPV6_ONLY => 'ip_ipv6_only_monthly_rate',
            default => 'ip_public_monthly_rate',
        };
        if (array_key_exists($key, $config)) {
            return max(0.0, (float) $config[$key]);
        }

        // Fallback for instances configured before network-specific pricing existed.
        if ($normalizedMode === self::NETWORK_MODE_INTERNAL && array_key_exists('ip_public_monthly_rate', $config)) {
            return max(0.0, (float) $config['ip_public_monthly_rate']);
        }

        return $legacyDefault;
    }

    public function getIpHourlyRate(string $networkMode = self::NETWORK_MODE_PUBLIC): float
    {
        return round($this->getNetworkIpMonthlyRate($networkMode) / 720, 8);
    }

    public function getPricingRates(): array
    {
        return [
            'storage_slow_hourly_rate' => $this->getStorageHourlyRate('sata'),
            'storage_fast_hourly_rate' => $this->getStorageHourlyRate('ssd'),
            'cpu_hourly_rate' => $this->getCpuHourlyRate(),
            'memory_hourly_rate' => $this->getMemoryHourlyRate(),
            'ip_hourly_rate' => $this->getIpHourlyRate(),
            'ip_public_monthly_rate' => $this->getNetworkIpMonthlyRate(self::NETWORK_MODE_PUBLIC),
            'ip_internal_monthly_rate' => $this->getNetworkIpMonthlyRate(self::NETWORK_MODE_INTERNAL),
            'ip_ipv6_only_monthly_rate' => $this->getNetworkIpMonthlyRate(self::NETWORK_MODE_IPV6_ONLY),
        ];
    }

    private function dispatchProvisionTask(int $taskId, string $workerToken): void
    {
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $basePath = trim(str_replace('\\', '/', dirname($scriptName)), '/');
        $pathPrefix = $basePath !== '' ? '/' . $basePath : '';
        $path = $pathPrefix . '/index.php?_url=/api/guest/vps/run_provision_task';

        $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
        $scheme = $isHttps ? 'https' : 'http';
        $defaultPort = $isHttps ? 443 : 80;

        $parsedHost = parse_url($scheme . '://' . $hostHeader);
        $host = (string) ($parsedHost['host'] ?? '127.0.0.1');
        $port = isset($parsedHost['port']) ? (int) $parsedHost['port'] : $defaultPort;
        $transportHost = ($isHttps ? 'ssl://' : '') . $host;

        $body = json_encode([
            'task_id' => $taskId,
            'worker_token' => $workerToken,
        ]);
        if ($body === false) {
            return;
        }

        $socket = @fsockopen($transportHost, $port, $errno, $errstr, 1.5);
        if (!$socket) {
            $this->di['logger']->warning('VPS: unable to dispatch async provisioning task #%s (%s)', $taskId, $errstr ?: $errno);
            return;
        }

        stream_set_blocking($socket, false);
        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$hostHeader}\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= 'Content-Length: ' . strlen($body) . "\r\n";
        $request .= "Connection: Close\r\n\r\n";
        $request .= $body;
        fwrite($socket, $request);
        fclose($socket);
    }

    private function dispatchDeleteTask(int $taskId, string $workerToken): void
    {
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $basePath = trim(str_replace('\\', '/', dirname($scriptName)), '/');
        $pathPrefix = $basePath !== '' ? '/' . $basePath : '';
        $path = $pathPrefix . '/index.php?_url=/api/guest/vps/run_delete_task';

        $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
        $scheme = $isHttps ? 'https' : 'http';
        $defaultPort = $isHttps ? 443 : 80;

        $parsedHost = parse_url($scheme . '://' . $hostHeader);
        $host = (string) ($parsedHost['host'] ?? '127.0.0.1');
        $port = isset($parsedHost['port']) ? (int) $parsedHost['port'] : $defaultPort;
        $transportHost = ($isHttps ? 'ssl://' : '') . $host;

        $body = json_encode([
            'task_id' => $taskId,
            'worker_token' => $workerToken,
        ]);
        if ($body === false) {
            return;
        }

        $socket = @fsockopen($transportHost, $port, $errno, $errstr, 1.5);
        if (!$socket) {
            $this->di['logger']->warning('VPS: unable to dispatch async delete task #%s (%s)', $taskId, $errstr ?: $errno);
            return;
        }

        stream_set_blocking($socket, false);
        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$hostHeader}\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= 'Content-Length: ' . strlen($body) . "\r\n";
        $request .= "Connection: Close\r\n\r\n";
        $request .= $body;
        fwrite($socket, $request);
        fclose($socket);
    }

    private function dispatchSnapshotTask(int $taskId, string $workerToken): void
    {
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $basePath = trim(str_replace('\\', '/', dirname($scriptName)), '/');
        $pathPrefix = $basePath !== '' ? '/' . $basePath : '';
        $path = $pathPrefix . '/index.php?_url=/api/guest/vps/run_snapshot_task';

        $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
        $scheme = $isHttps ? 'https' : 'http';
        $defaultPort = $isHttps ? 443 : 80;

        $parsedHost = parse_url($scheme . '://' . $hostHeader);
        $host = (string) ($parsedHost['host'] ?? '127.0.0.1');
        $port = isset($parsedHost['port']) ? (int) $parsedHost['port'] : $defaultPort;
        $transportHost = ($isHttps ? 'ssl://' : '') . $host;

        $body = json_encode([
            'task_id' => $taskId,
            'worker_token' => $workerToken,
        ]);
        if ($body === false) {
            return;
        }

        $socket = @fsockopen($transportHost, $port, $errno, $errstr, 1.5);
        if (!$socket) {
            $this->di['logger']->warning('VPS: unable to dispatch async snapshot task #%s (%s)', $taskId, $errstr ?: $errno);
            return;
        }

        stream_set_blocking($socket, false);
        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$hostHeader}\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= 'Content-Length: ' . strlen($body) . "\r\n";
        $request .= "Connection: Close\r\n\r\n";
        $request .= $body;
        fwrite($socket, $request);
        fclose($socket);
    }

    private function dispatchContainerTask(int $taskId, string $workerToken): void
    {
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $basePath = trim(str_replace('\\', '/', dirname($scriptName)), '/');
        $pathPrefix = $basePath !== '' ? '/' . $basePath : '';
        $path = $pathPrefix . '/index.php?_url=/api/guest/vps/run_container_task';

        $hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
        $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
        $scheme = $isHttps ? 'https' : 'http';
        $defaultPort = $isHttps ? 443 : 80;

        $parsedHost = parse_url($scheme . '://' . $hostHeader);
        $host = (string) ($parsedHost['host'] ?? '127.0.0.1');
        $port = isset($parsedHost['port']) ? (int) $parsedHost['port'] : $defaultPort;
        $transportHost = ($isHttps ? 'ssl://' : '') . $host;

        $body = json_encode([
            'task_id' => $taskId,
            'worker_token' => $workerToken,
        ]);
        if ($body === false) {
            return;
        }

        $socket = @fsockopen($transportHost, $port, $errno, $errstr, 1.5);
        if (!$socket) {
            $this->di['logger']->warning('VPS: unable to dispatch async container task #%s (%s)', $taskId, $errstr ?: $errno);
            return;
        }

        stream_set_blocking($socket, false);
        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$hostHeader}\r\n";
        $request .= "Content-Type: application/json\r\n";
        $request .= 'Content-Length: ' . strlen($body) . "\r\n";
        $request .= "Connection: Close\r\n\r\n";
        $request .= $body;
        fwrite($socket, $request);
        fclose($socket);
    }

    private function markProvisionTaskFailed(int $taskId, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $this->di['db']->exec(
            'UPDATE mod_vps_provision_task
             SET status = :status, error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at
             WHERE id = :id',
            [
                ':status' => 'failed',
                ':error_message' => $message,
                ':finished_at' => $now,
                ':updated_at' => $now,
                ':id' => $taskId,
            ]
        );
    }

    private function markDeleteTaskFailed(int $taskId, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $this->di['db']->exec(
            'UPDATE mod_vps_delete_task
             SET status = :status, error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at
             WHERE id = :id',
            [
                ':status' => 'failed',
                ':error_message' => $message,
                ':finished_at' => $now,
                ':updated_at' => $now,
                ':id' => $taskId,
            ]
        );
    }

    private function markSnapshotTaskFailed(int $taskId, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $this->di['db']->exec(
            'UPDATE mod_vps_snapshot_task
             SET status = :status, error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at
             WHERE id = :id',
            [
                ':status' => 'failed',
                ':error_message' => $message,
                ':finished_at' => $now,
                ':updated_at' => $now,
                ':id' => $taskId,
            ]
        );
    }

    private function markContainerTaskFailed(int $taskId, string $message): void
    {
        $now = date('Y-m-d H:i:s');
        $this->di['db']->exec(
            'UPDATE mod_vps_container_task
             SET status = :status, error_message = :error_message, finished_at = :finished_at, updated_at = :updated_at
             WHERE id = :id',
            [
                ':status' => 'failed',
                ':error_message' => $message,
                ':finished_at' => $now,
                ':updated_at' => $now,
                ':id' => $taskId,
            ]
        );
    }

    private function resolveClientVpsOrderId(int $clientId): ?int
    {
        $orderId = $this->di['db']->getCell(
            'SELECT id
             FROM client_order
             WHERE client_id = :client_id
               AND service_type = :service_type
               AND status IN (:status_active, :status_pending)
             ORDER BY (status = :status_active) DESC, updated_at DESC, id DESC
             LIMIT 1',
            [
                ':client_id' => $clientId,
                ':service_type' => 'vps',
                ':status_active' => \Model_ClientOrder::STATUS_ACTIVE,
                ':status_pending' => \Model_ClientOrder::STATUS_PENDING_SETUP,
            ]
        );

        if ($orderId) {
            return (int) $orderId;
        }

        $fallback = $this->di['db']->getCell(
            'SELECT id
             FROM client_order
             WHERE client_id = :client_id
               AND service_type = :service_type
             ORDER BY updated_at DESC, id DESC
             LIMIT 1',
            [
                ':client_id' => $clientId,
                ':service_type' => 'vps',
            ]
        );

        return $fallback ? (int) $fallback : null;
    }

    private function ensureBillingSchema(): void
    {
        $orderColumn = (int) $this->di['db']->getCell(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mod_vps_vm'
               AND COLUMN_NAME = 'order_id'"
        );
        if ($orderColumn === 0) {
            $this->di['db']->exec('ALTER TABLE mod_vps_vm ADD COLUMN order_id bigint(20) DEFAULT NULL AFTER client_id');
        }

        $ipColumn = (int) $this->di['db']->getCell(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mod_vps_vm'
               AND COLUMN_NAME = 'ip_address'"
        );
        if ($ipColumn === 0) {
            $this->di['db']->exec('ALTER TABLE mod_vps_vm ADD COLUMN ip_address varchar(64) DEFAULT NULL AFTER monthly_cost');
        }

        $networkModeColumn = (int) $this->di['db']->getCell(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mod_vps_vm'
               AND COLUMN_NAME = 'network_mode'"
        );
        if ($networkModeColumn === 0) {
            $this->di['db']->exec('ALTER TABLE mod_vps_vm ADD COLUMN network_mode varchar(20) DEFAULT NULL AFTER disk_type');
        }

        $powerStateColumn = (int) $this->di['db']->getCell(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mod_vps_vm'
               AND COLUMN_NAME = 'power_state'"
        );
        if ($powerStateColumn === 0) {
            $this->di['db']->exec('ALTER TABLE mod_vps_vm ADD COLUMN power_state varchar(20) DEFAULT "unknown" AFTER ip_address');
        }

        $poweredOffAtColumn = (int) $this->di['db']->getCell(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mod_vps_vm'
               AND COLUMN_NAME = 'powered_off_at'"
        );
        if ($poweredOffAtColumn === 0) {
            $this->di['db']->exec('ALTER TABLE mod_vps_vm ADD COLUMN powered_off_at varchar(35) DEFAULT NULL AFTER power_state');
        }

        $stateSyncedAtColumn = (int) $this->di['db']->getCell(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mod_vps_vm'
               AND COLUMN_NAME = 'state_synced_at'"
        );
        if ($stateSyncedAtColumn === 0) {
            $this->di['db']->exec('ALTER TABLE mod_vps_vm ADD COLUMN state_synced_at varchar(35) DEFAULT NULL AFTER powered_off_at');
        }

        $taskSql = '
        CREATE TABLE IF NOT EXISTS `mod_vps_provision_task` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `client_id` bigint(20) NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT "queued",
            `payload` text NOT NULL,
            `result` text DEFAULT NULL,
            `error_message` text DEFAULT NULL,
            `vmid` int(11) DEFAULT NULL,
            `worker_token` varchar(64) NOT NULL,
            `started_at` datetime DEFAULT NULL,
            `finished_at` datetime DEFAULT NULL,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_worker_token` (`worker_token`),
            KEY `idx_client_status` (`client_id`,`status`),
            KEY `idx_vmid` (`vmid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';
        $this->di['db']->exec($taskSql);

        $deleteTaskSql = '
        CREATE TABLE IF NOT EXISTS `mod_vps_delete_task` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `client_id` bigint(20) NOT NULL,
            `vmid` int(11) NOT NULL,
            `vm_name` varchar(255) DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT "queued",
            `result` text DEFAULT NULL,
            `error_message` text DEFAULT NULL,
            `worker_token` varchar(64) NOT NULL,
            `started_at` datetime DEFAULT NULL,
            `finished_at` datetime DEFAULT NULL,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_delete_worker_token` (`worker_token`),
            KEY `idx_delete_client_status` (`client_id`,`status`),
            KEY `idx_delete_vmid` (`vmid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';
        $this->di['db']->exec($deleteTaskSql);

        $snapshotSql = '
        CREATE TABLE IF NOT EXISTS `mod_vps_snapshot` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `client_id` bigint(20) NOT NULL,
            `order_id` bigint(20) DEFAULT NULL,
            `vmid` int(11) NOT NULL,
            `snapshot_name` varchar(128) NOT NULL,
            `size_gb` decimal(10,2) NOT NULL DEFAULT 0.00,
            `status` varchar(20) NOT NULL DEFAULT "active",
            `last_restored_at` datetime DEFAULT NULL,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_vmid_snapshot` (`vmid`,`snapshot_name`),
            KEY `idx_snapshot_client_vmid` (`client_id`,`vmid`),
            KEY `idx_snapshot_order_status` (`order_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';
        $this->di['db']->exec($snapshotSql);

        $snapshotTaskSql = '
        CREATE TABLE IF NOT EXISTS `mod_vps_snapshot_task` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `client_id` bigint(20) NOT NULL,
            `vmid` int(11) NOT NULL,
            `snapshot_name` varchar(128) NOT NULL,
            `action` varchar(20) NOT NULL,
            `payload` text DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT "queued",
            `result` text DEFAULT NULL,
            `error_message` text DEFAULT NULL,
            `worker_token` varchar(64) NOT NULL,
            `started_at` datetime DEFAULT NULL,
            `finished_at` datetime DEFAULT NULL,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_snapshot_worker_token` (`worker_token`),
            KEY `idx_snapshot_task_client_status` (`client_id`,`status`),
            KEY `idx_snapshot_task_vmid` (`vmid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';
        $this->di['db']->exec($snapshotTaskSql);

        $containerSql = '
        CREATE TABLE IF NOT EXISTS `mod_vps_container` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `client_id` bigint(20) NOT NULL,
            `order_id` bigint(20) DEFAULT NULL,
            `ctid` int(11) NOT NULL,
            `container_name` varchar(255) DEFAULT NULL,
            `template` varchar(255) DEFAULT NULL,
            `storage` varchar(64) DEFAULT NULL,
            `network_mode` varchar(20) DEFAULT NULL,
            `ip_address` varchar(64) DEFAULT NULL,
            `disk_size` int(11) DEFAULT NULL,
            `disk_type` varchar(20) DEFAULT NULL,
            `monthly_cost` decimal(10,2) DEFAULT NULL,
            `power_state` varchar(20) DEFAULT "unknown",
            `state_synced_at` varchar(35) DEFAULT NULL,
            `root_password` varchar(255) DEFAULT NULL,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ctid` (`ctid`),
            KEY `idx_container_client` (`client_id`),
            KEY `idx_container_order` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';
        $this->di['db']->exec($containerSql);

        $containerDiskSizeColumn = (int) $this->di['db']->getCell(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mod_vps_container'
               AND COLUMN_NAME = 'disk_size'"
        );
        if ($containerDiskSizeColumn === 0) {
            $this->di['db']->exec('ALTER TABLE mod_vps_container ADD COLUMN disk_size int(11) DEFAULT NULL AFTER ip_address');
        }

        $containerDiskTypeColumn = (int) $this->di['db']->getCell(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mod_vps_container'
               AND COLUMN_NAME = 'disk_type'"
        );
        if ($containerDiskTypeColumn === 0) {
            $this->di['db']->exec('ALTER TABLE mod_vps_container ADD COLUMN disk_type varchar(20) DEFAULT NULL AFTER disk_size');
        }

        $containerMonthlyCostColumn = (int) $this->di['db']->getCell(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mod_vps_container'
               AND COLUMN_NAME = 'monthly_cost'"
        );
        if ($containerMonthlyCostColumn === 0) {
            $this->di['db']->exec('ALTER TABLE mod_vps_container ADD COLUMN monthly_cost decimal(10,2) DEFAULT NULL AFTER disk_type');
        }

        $containerStateSyncedAtColumn = (int) $this->di['db']->getCell(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'mod_vps_container'
               AND COLUMN_NAME = 'state_synced_at'"
        );
        if ($containerStateSyncedAtColumn === 0) {
            $this->di['db']->exec('ALTER TABLE mod_vps_container ADD COLUMN state_synced_at varchar(35) DEFAULT NULL AFTER power_state');
        }

        $containerTaskSql = '
        CREATE TABLE IF NOT EXISTS `mod_vps_container_task` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `client_id` bigint(20) NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT "queued",
            `payload` text NOT NULL,
            `result` text DEFAULT NULL,
            `error_message` text DEFAULT NULL,
            `ctid` int(11) DEFAULT NULL,
            `worker_token` varchar(64) NOT NULL,
            `started_at` datetime DEFAULT NULL,
            `finished_at` datetime DEFAULT NULL,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_container_worker_token` (`worker_token`),
            KEY `idx_container_client_status` (`client_id`,`status`),
            KEY `idx_container_ctid` (`ctid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';
        $this->di['db']->exec($containerTaskSql);

        $usageSql = '
        CREATE TABLE IF NOT EXISTS `mod_vps_usage_hourly` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `client_id` bigint(20) NOT NULL,
            `order_id` bigint(20) DEFAULT NULL,
            `vmid` int(11) NOT NULL,
            `period_hour` datetime NOT NULL,
            `state` varchar(32) DEFAULT NULL,
            `hours` decimal(8,2) NOT NULL DEFAULT 0.00,
            `rate` decimal(12,6) NOT NULL DEFAULT 0.000000,
            `amount` decimal(12,6) NOT NULL DEFAULT 0.000000,
            `invoice_id` bigint(20) DEFAULT NULL,
            `invoiced_at` datetime DEFAULT NULL,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_vmid_period_hour` (`vmid`,`period_hour`),
            KEY `idx_client_order_period` (`client_id`,`order_id`,`period_hour`),
            KEY `idx_invoiced_at` (`invoiced_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';
        $this->di['db']->exec($usageSql);

        $containerUsageSql = '
        CREATE TABLE IF NOT EXISTS `mod_vps_container_usage_hourly` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `client_id` bigint(20) NOT NULL,
            `order_id` bigint(20) DEFAULT NULL,
            `ctid` int(11) NOT NULL,
            `period_hour` datetime NOT NULL,
            `state` varchar(32) DEFAULT NULL,
            `hours` decimal(8,2) NOT NULL DEFAULT 0.00,
            `rate` decimal(12,6) NOT NULL DEFAULT 0.000000,
            `amount` decimal(12,6) NOT NULL DEFAULT 0.000000,
            `invoice_id` bigint(20) DEFAULT NULL,
            `invoiced_at` datetime DEFAULT NULL,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_ctid_period_hour` (`ctid`,`period_hour`),
            KEY `idx_container_client_order_period` (`client_id`,`order_id`,`period_hour`),
            KEY `idx_container_invoiced_at` (`invoiced_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';
        $this->di['db']->exec($containerUsageSql);

        $runSql = '
        CREATE TABLE IF NOT EXISTS `mod_vps_billing_run` (
            `id` bigint(20) NOT NULL AUTO_INCREMENT,
            `run_at` datetime NOT NULL,
            `metered_rows` int(11) NOT NULL DEFAULT 0,
            `inserted_rows` int(11) NOT NULL DEFAULT 0,
            `updated_rows` int(11) NOT NULL DEFAULT 0,
            `missing_order_rows` int(11) NOT NULL DEFAULT 0,
            `invoiced_groups` int(11) NOT NULL DEFAULT 0,
            `invoiced_usage_rows` int(11) NOT NULL DEFAULT 0,
            `generated_invoice_ids` text DEFAULT NULL,
            `notes` text DEFAULT NULL,
            `created_at` varchar(35) DEFAULT NULL,
            `updated_at` varchar(35) DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_run_at` (`run_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';
        $this->di['db']->exec($runSql);
    }

    private function getProvisionEligibility(\Model_Client $client, float $monthlyCost): array
    {
        if ($this->isProvisionBillingBypassed()) {
            return [
                'can_provision' => true,
                'reason' => 'bypass',
                'balance' => null,
                'has_active_subscription' => false,
            ];
        }

        $balanceService = $this->di['mod_service']('Client', 'Balance');
        $balance = (float) $balanceService->getClientBalance($client);
        $hasSubscription = $this->hasActiveVpsSubscription((int) $client->id);

        return [
            'can_provision' => $balance >= $monthlyCost || $hasSubscription,
            'reason' => $hasSubscription ? 'subscription' : ($balance >= $monthlyCost ? 'balance' : 'insufficient'),
            'balance' => $balance,
            'has_active_subscription' => $hasSubscription,
        ];
    }

    private function assertProvisionEligibility(\Model_Client $client, float $monthlyCost, string $action): void
    {
        $eligibility = $this->getProvisionEligibility($client, $monthlyCost);
        if ($eligibility['can_provision']) {
            return;
        }

        $this->di['logger']->warning(
            'VPS: blocked %s for client #%s due to eligibility failure (cost=%s, balance=%s, has_subscription=%s)',
            $action,
            (int) $client->id,
            number_format($monthlyCost, 2, '.', ''),
            number_format((float) ($eligibility['balance'] ?? 0), 2, '.', ''),
            !empty($eligibility['has_active_subscription']) ? 'yes' : 'no'
        );

        throw new InformationException(
            'Insufficient account balance for provisioning this VM. Add funds or maintain an active VPS subscription.',
            [],
            403
        );
    }

    private function expandFilesystemWithCloudInit(Proxmox $proxmox, string $node, int $vmid): void
    {
        $command = 'cloud-init status --wait || true; '
            . 'cloud-init single --name growpart --frequency always || true; '
            . 'cloud-init single --name resizefs --frequency always || true';

        try {
            $result = $proxmox->create("/nodes/$node/qemu/$vmid/agent/exec", [
                'command' => '/bin/sh',
                'extra-args' => ['-lc', $command],
            ]);
            $pid = $result['data']['pid'] ?? null;
            if ($pid === null) {
                return;
            }

            $start = time();
            while (time() - $start < 40) {
                $status = $proxmox->get("/nodes/$node/qemu/$vmid/agent/exec-status", ['pid' => $pid]);
                if (!empty($status['data']['exited'])) {
                    return;
                }
                sleep(2);
            }
        } catch (\Throwable $e) {
            $this->di['logger']->warning('VPS: filesystem expansion command was not completed: ' . $e->getMessage());
        }
    }

    private function enforceRootPasswordWithGuestAgent(Proxmox $proxmox, string $node, int $vmid, string $rootPassword): void
    {
        if ($rootPassword === '') {
            return;
        }

        $attempts = 8;

        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            try {
                // Preferred: dedicated QEMU guest-agent password API using plaintext input.
                // For crypted=0, QGA expects plaintext password (not base64 text).
                $proxmox->create("/nodes/$node/qemu/$vmid/agent/set-user-password", [
                    'username' => 'root',
                    'password' => $rootPassword,
                    'crypted' => 0,
                ]);
                $this->di['logger']->info('VPS: guest-agent set-user-password applied for VM ' . $vmid . ' using plaintext mode');

                return;
            } catch (\Throwable $e) {
                // Fallback: some builds only accept pre-hashed Unix password strings with crypted=1.
                if (str_contains($e->getMessage(), 'Parameter verification failed')) {
                    try {
                        $salt = '$6$' . substr(bin2hex(random_bytes(8)), 0, 16) . '$';
                        $hashedPassword = crypt($rootPassword, $salt);
                        $proxmox->create("/nodes/$node/qemu/$vmid/agent/set-user-password", [
                            'username' => 'root',
                            'password' => $hashedPassword,
                            'crypted' => 1,
                        ]);
                        $this->di['logger']->info('VPS: guest-agent set-user-password applied for VM ' . $vmid . ' using crypted mode');

                        return;
                    } catch (\Throwable $e2) {
                        $this->di['logger']->warning('VPS: guest-agent set-user-password crypted attempt failed for VM ' . $vmid . ': ' . $e2->getMessage());
                    }
                }

                // Guest agent may still be initializing; retry briefly.
                if ($attempt === $attempts) {
                    $this->di['logger']->warning('VPS: guest-agent set-user-password failed for VM ' . $vmid . ': ' . $e->getMessage());
                }
            }

            sleep(3);
        }

        $this->di['logger']->warning('VPS: guest-agent fallback could not enforce root password for VM ' . $vmid);
    }

    private function enforceRootPasswordViaTemporarySsh(string $ipAddress, string $rootPassword, int $vmid): void
    {
        $sshpassPath = $this->resolveExecutablePath('sshpass', ['/usr/bin/sshpass', '/bin/sshpass', '/usr/local/bin/sshpass']);
        if ($sshpassPath === '') {
            $this->di['logger']->warning('VPS: sshpass is not available; skipping SSH password fallback for VM ' . $vmid);
            return;
        }
        $sshPath = $this->resolveExecutablePath('ssh', ['/usr/bin/ssh', '/bin/ssh', '/usr/local/bin/ssh']);
        if ($sshPath === '') {
            $this->di['logger']->warning('VPS: ssh binary is not available; skipping SSH password fallback for VM ' . $vmid);
            return;
        }

        $tempPassword = self::TEMP_ROOT_PASSWORD;
        $setPasswordCommand = 'cloud-init status --wait || true; echo ' . escapeshellarg('root:' . $rootPassword) . ' | chpasswd';
        $sshOptions = '-o ConnectTimeout=6 -o ConnectionAttempts=1 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            . '-o PreferredAuthentications=password -o PubkeyAuthentication=no -o NumberOfPasswordPrompts=1';
        $remote = 'root@' . $ipAddress;

        // First prove that the temporary password still works.
        $probeCmd = escapeshellcmd($sshpassPath)
            . ' -p ' . escapeshellarg($tempPassword)
            . ' ' . escapeshellcmd($sshPath) . ' ' . $sshOptions . ' '
            . escapeshellarg($remote) . ' '
            . escapeshellarg('true')
            . ' 2>&1';
        $probeOutput = [];
        $probeExitCode = 1;
        exec($probeCmd, $probeOutput, $probeExitCode);
        if ($probeExitCode !== 0) {
            $this->di['logger']->warning('VPS: temporary-password SSH probe failed for VM ' . $vmid . ': ' . implode(' ', $probeOutput));
            return;
        }

        // Temporary password works, rotate it to generated password immediately.
        $rotateCmd = escapeshellcmd($sshpassPath)
            . ' -p ' . escapeshellarg($tempPassword)
            . ' ' . escapeshellcmd($sshPath) . ' ' . $sshOptions . ' '
            . escapeshellarg($remote) . ' '
            . escapeshellarg($setPasswordCommand)
            . ' 2>&1';
        $rotateOutput = [];
        $rotateExitCode = 1;
        exec($rotateCmd, $rotateOutput, $rotateExitCode);
        if ($rotateExitCode !== 0) {
            $this->di['logger']->warning('VPS: temporary-password SSH fallback failed to rotate root password for VM ' . $vmid . ': ' . implode(' ', $rotateOutput));
            return;
        }

        $this->di['logger']->info('VPS: rotated root password via temporary SSH fallback for VM ' . $vmid);
    }

    private function waitForGuestAgentReady(Proxmox $proxmox, string $node, int $vmid, int $timeoutSeconds = 90): void
    {
        $start = time();
        while (time() - $start < $timeoutSeconds) {
            try {
                $proxmox->get("/nodes/$node/qemu/$vmid/agent/ping");
                return;
            } catch (\Throwable) {
                sleep(2);
            }
        }

        $this->di['logger']->warning('VPS: guest agent did not become ready in time for VM ' . $vmid);
    }

    private function resolveExecutablePath(string $binaryName, array $knownPaths = []): string
    {
        foreach ($knownPaths as $path) {
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        $output = [];
        $exitCode = 1;
        exec('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin command -v ' . escapeshellarg($binaryName) . ' 2>/dev/null', $output, $exitCode);
        if ($exitCode === 0 && !empty($output[0])) {
            return trim((string) $output[0]);
        }

        return '';
    }

    private function loadClientFolderState(int $clientId): array
    {
        $raw = $this->getClientMetaValue($clientId, self::CLIENT_FOLDER_META_KEY);
        $state = [];
        if ($raw !== null && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        }

        $folders = [];
        foreach (($state['folders'] ?? []) as $folder) {
            if (!is_array($folder) || !isset($folder['id'])) {
                continue;
            }
            $folders[] = [
                'id' => (int) $folder['id'],
                'name' => trim((string) ($folder['name'] ?? 'Folder')),
                'collapsed' => !empty($folder['collapsed']),
                'container_collapsed' => array_key_exists('container_collapsed', $folder)
                    ? !empty($folder['container_collapsed'])
                    : !empty($folder['collapsed']),
            ];
        }

        $legacyAssignments = [];
        foreach (($state['assignments'] ?? []) as $vmid => $folderId) {
            $legacyAssignments[(string) (int) $vmid] = (int) $folderId;
        }
        $vmAssignments = [];
        foreach (($state['vm_assignments'] ?? $legacyAssignments) as $vmid => $folderId) {
            $vmAssignments[(string) (int) $vmid] = (int) $folderId;
        }
        $containerAssignments = [];
        foreach (($state['container_assignments'] ?? []) as $ctid => $folderId) {
            $containerAssignments[(string) (int) $ctid] = (int) $folderId;
        }

        $maxId = 0;
        foreach ($folders as $folder) {
            $maxId = max($maxId, (int) $folder['id']);
        }

        return [
            'next_id' => max($maxId + 1, (int) ($state['next_id'] ?? 1)),
            'folders' => $folders,
            'vm_assignments' => $vmAssignments,
            'container_assignments' => $containerAssignments,
        ];
    }

    private function saveClientFolderState(int $clientId, array $state): void
    {
        $payload = [
            'next_id' => (int) ($state['next_id'] ?? 1),
            'folders' => array_values($state['folders'] ?? []),
            // Keep "assignments" for backward compatibility with older UI code.
            'assignments' => (object) ($state['vm_assignments'] ?? []),
            'vm_assignments' => (object) ($state['vm_assignments'] ?? []),
            'container_assignments' => (object) ($state['container_assignments'] ?? []),
        ];

        $this->setClientMetaValue($clientId, self::CLIENT_FOLDER_META_KEY, json_encode($payload));
    }

    private function getClientMetaValue(int $clientId, string $key): ?string
    {
        $meta = $this->di['db']->findOne(
            'extension_meta',
            'extension = :ext AND client_id = :client_id AND meta_key = :meta_key',
            [
                ':ext' => 'mod_vps',
                ':client_id' => $clientId,
                ':meta_key' => $key,
            ]
        );
        if (!$meta || !isset($meta->meta_value)) {
            return null;
        }

        return (string) $meta->meta_value;
    }

    private function setClientMetaValue(int $clientId, string $key, string $value): void
    {
        $meta = $this->di['db']->findOne(
            'extension_meta',
            'extension = :ext AND client_id = :client_id AND meta_key = :meta_key',
            [
                ':ext' => 'mod_vps',
                ':client_id' => $clientId,
                ':meta_key' => $key,
            ]
        );
        if (!$meta) {
            $meta = $this->di['db']->dispense('extension_meta');
            $meta->extension = 'mod_vps';
            $meta->client_id = $clientId;
            $meta->rel_type = 'vps';
            $meta->rel_id = (string) $clientId;
            $meta->meta_key = $key;
            $meta->created_at = date('Y-m-d H:i:s');
        }

        $meta->meta_value = $value;
        $meta->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($meta);
    }

    private function getStoredApiToken(int $clientId): ?array
    {
        $meta = $this->di['db']->findOne(
            'extension_meta',
            'extension = :ext AND client_id = :client_id AND meta_key = :meta_key',
            [
                ':ext' => 'mod_vps',
                ':client_id' => $clientId,
                ':meta_key' => 'proxmox_api_token',
            ]
        );

        if (!$meta || empty($meta->meta_value)) {
            return null;
        }

        $decoded = json_decode($meta->meta_value, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!empty($decoded['secret'])) {
            try {
                $decoded['secret'] = $this->di['crypt']->decrypt((string) $decoded['secret'], Config::getProperty('info.salt'));
            } catch (\Throwable) {
                // Backwards compatibility for previously unencrypted values.
            }
        }

        return $decoded;
    }

    public function getRootPasswordForTicketing(): string
    {
        $password = $this->getEffectiveRootPassword($this->getModuleConfig());

        return trim($password);
    }

    private function getEffectiveRootTokenSecret(array $config): string
    {
        $tokenId = $this->normalizeTokenId(trim((string) ($config['proxmox_root_token_id'] ?? '')));
        if ($tokenId === '') {
            return '';
        }

        $secret = $this->getModuleSecret(self::ROOT_TOKEN_SECRET_META_KEY);
        if ($secret !== '') {
            return $secret;
        }

        $legacy = trim((string) ($config['proxmox_root_token_secret'] ?? ''));
        if ($legacy !== '') {
            $this->storeModuleSecret(self::ROOT_TOKEN_SECRET_META_KEY, $legacy);
            return $legacy;
        }

        return '';
    }

    private function getEffectiveRootPassword(array $config): string
    {
        $password = $this->getModuleSecret(self::ROOT_PASSWORD_META_KEY);
        if ($password !== '') {
            return $password;
        }

        $legacy = trim((string) ($config['proxmox_root_password'] ?? ''));
        if ($legacy !== '') {
            $this->storeModuleSecret(self::ROOT_PASSWORD_META_KEY, $legacy);
            return $legacy;
        }

        return '';
    }

    private function getModuleSecret(string $key): string
    {
        $meta = $this->di['db']->findOne(
            'extension_meta',
            'extension = :ext AND client_id IS NULL AND meta_key = :meta_key',
            [
                ':ext' => 'mod_vps',
                ':meta_key' => $key,
            ]
        );
        if (!$meta || empty($meta->meta_value)) {
            return '';
        }

        try {
            return (string) $this->di['crypt']->decrypt((string) $meta->meta_value, Config::getProperty('info.salt'));
        } catch (\Throwable) {
            // Backwards compatibility for previously unencrypted values.
            return (string) $meta->meta_value;
        }
    }

    private function storeModuleSecret(string $key, string $value): void
    {
        $meta = $this->di['db']->findOne(
            'extension_meta',
            'extension = :ext AND client_id IS NULL AND meta_key = :meta_key',
            [
                ':ext' => 'mod_vps',
                ':meta_key' => $key,
            ]
        );
        if (!$meta) {
            if ($value === '') {
                return;
            }
            $meta = $this->di['db']->dispense('extension_meta');
            $meta->extension = 'mod_vps';
            $meta->client_id = null;
            $meta->rel_type = 'module';
            $meta->rel_id = '0';
            $meta->meta_key = $key;
            $meta->created_at = date('Y-m-d H:i:s');
        }

        if ($value === '') {
            $this->di['db']->trash($meta);
            return;
        }

        $meta->meta_value = $this->di['crypt']->encrypt($value, Config::getProperty('info.salt'));
        $meta->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($meta);
    }

    private function storeApiToken(int $clientId, array $token): void
    {
        $meta = $this->di['db']->findOne(
            'extension_meta',
            'extension = :ext AND client_id = :client_id AND meta_key = :meta_key',
            [
                ':ext' => 'mod_vps',
                ':client_id' => $clientId,
                ':meta_key' => 'proxmox_api_token',
            ]
        );

        if (!$meta) {
            $meta = $this->di['db']->dispense('extension_meta');
            $meta->extension = 'mod_vps';
            $meta->client_id = $clientId;
            $meta->rel_type = 'proxmox';
            $meta->rel_id = (string) $clientId;
            $meta->meta_key = 'proxmox_api_token';
            $meta->created_at = date('Y-m-d H:i:s');
        }

        $tokenToStore = $token;
        if (!empty($tokenToStore['secret'])) {
            $tokenToStore['secret'] = $this->di['crypt']->encrypt((string) $tokenToStore['secret'], Config::getProperty('info.salt'));
        }

        $meta->meta_value = json_encode($tokenToStore);
        $meta->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($meta);
    }
}
