<?php

namespace App\Services\Billing\Cutover;

use App\Services\Billing\PaymentHistoryResetRefused;
use Illuminate\Support\Facades\DB;

class CutoverIdentity
{
    public function inspect(string $target): array
    {
        $context = $this->context();
        $expected = config('billing_cutover');
        $policy = match ($target) {
            'local-rehearsal' => new LocalRehearsalIdentityPolicy,
            'production' => new ProductionIdentityPolicy,
            default => throw new PaymentHistoryResetRefused('Explicit --target=local-rehearsal or --target=production is required.'),
        };
        if (($expected['enabled'] ?? false) !== true || ($expected['target'] ?? null) !== $target
            || ! is_string($expected['expected_host'] ?? null) || trim($expected['expected_host']) === ''
            || ! is_string($expected['expected_database'] ?? null) || trim($expected['expected_database']) === ''
            || ! ctype_digit((string) ($expected['expected_port'] ?? '')) || (int) $expected['expected_port'] < 1
            || (int) $expected['expected_port'] > 65535) {
            throw new PaymentHistoryResetRefused('Cutover is disabled or its target/expected host/schema/port assertions are incomplete.');
        }
        if ($context['driver'] !== 'mysql' || $context['split'] || $context['prefix'] || $context['socket']
            || $context['host'] !== $expected['expected_host'] || $context['configured_schema'] !== $expected['expected_database']
            || (string) $context['configured_port'] !== (string) $expected['expected_port']) {
            throw new PaymentHistoryResetRefused('Laravel connection does not match the expected cutover identity, or uses unsupported split/prefix/socket configuration.');
        }
        $policy->configuration($context); // Refuse invalid deployment assertions before opening SQL.
        $server = $this->server();
        if ($server['schema'] !== $expected['expected_database'] || (int) $server['port'] !== (int) $expected['expected_port']
            || (int) $server['foreign_keys'] !== 1 || (int) $server['read_only'] !== 0 || (int) $server['super_read_only'] !== 0) {
            throw new PaymentHistoryResetRefused('Actual SQL schema/port/FK/writable identity failed; read replicas and read-only databases are refused.');
        }
        $policy->server($server);

        return ['target' => $target, ...$context, 'server' => $server, 'timezone' => config('app.timezone')];
    }

    protected function context(): array
    {
        $configured = config('database.connections.'.DB::getDefaultConnection(), []);
        if (! empty($configured['read']) || ! empty($configured['write'])) {
            throw new PaymentHistoryResetRefused('Split database connections are refused before connecting.');
        }
        $db = DB::connection();

        return ['environment' => app()->environment(), 'driver' => $db->getDriverName(), 'host' => $db->getConfig('host'),
            'configured_port' => $db->getConfig('port'), 'configured_schema' => $db->getDatabaseName(),
            'split' => (bool) ($configured['read'] ?? false) || (bool) ($configured['write'] ?? false) || (bool) $db->getConfig('read') || (bool) $db->getConfig('write'),
            'prefix' => (bool) $db->getConfig('prefix'), 'socket' => (bool) $db->getConfig('unix_socket')];
    }

    protected function server(): array
    {
        $facts = (array) DB::selectOne('SELECT DATABASE() AS `schema`, VERSION() AS version, @@version_comment AS version_comment, @@hostname AS hostname, @@port AS port, @@foreign_key_checks AS foreign_keys, @@global.read_only AS read_only');
        $facts['engine_family'] = str_contains(strtolower($facts['version']), 'mariadb') ? 'mariadb'
            : (preg_match('/mysql|source distribution/i', $facts['version_comment']) ? 'mysql' : 'unknown');
        $facts['super_read_only'] = 0;
        $facts['server_uuid'] = null;
        $facts['replica_channels'] = null;
        if ($facts['engine_family'] === 'mysql') {
            $facts = array_merge($facts, (array) DB::selectOne('SELECT @@global.super_read_only AS super_read_only, @@server_uuid AS server_uuid'));
            // Discard all channel details; only absence/presence is part of the manifest.
            $facts['replica_channels'] = count(DB::select('SHOW REPLICA STATUS'));
        }

        return $facts;
    }
}
