<?php

namespace App\Console\Commands;

use App\Services\Billing\CutoverInventoryReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class CutoverInventory extends Command
{
    protected $signature = 'billing:cutover-inventory {--details : Include allowlisted internal review IDs, never payloads or credentials}';

    protected $description = 'Read-only billing obligation inventory; never resets history or contacts a provider.';

    public function handle(): int
    {
        $db = DB::connection();
        $pdo = null;
        $snapshot = false;
        try {
            $host = $db->getConfig('host');
            $identity = [
                'laravel_environment' => app()->environment(),
                'driver' => $db->getDriverName(), 'host' => $host,
                'port' => $db->getConfig('port'), 'configured_schema' => $db->getDatabaseName(),
                'application_timezone' => config('app.timezone'), 'code_revision' => $this->revision(),
                'revision_note' => 'Git HEAD only; uncommitted or untracked source is not represented by this revision.',
                'target_hint' => self::targetHint($db->getDriverName(), $host),
                'production_identity' => 'Operator must confirm endpoint/schema; APP_ENV alone is not proof.',
            ];
            $this->line('Database identity');
            $this->line(json_encode($identity, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
            if ($db->getConfig('read') || $db->getConfig('write') || is_array($host) || $db->getConfig('prefix')) {
                throw new \RuntimeException('Unsupported split, multi-host or prefixed connection.');
            }
            $pdo = $db->getPdo();
            if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
                if ($pdo->inTransaction()) {
                    throw new \RuntimeException('Inventory requires its own read-only snapshot.');
                }
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
                $snapshot = true;
                $server = $db->selectOne('SELECT VERSION() AS server_version, DATABASE() AS selected_schema, CURRENT_TIMESTAMP AS database_time, @@hostname AS server_hostname, @@port AS server_port, @@session.time_zone AS database_timezone', [], false);
            } elseif ($db->getDriverName() === 'sqlite' && app()->environment('testing')) {
                $server = $db->selectOne("SELECT sqlite_version() AS server_version, datetime('now') AS database_time", [], false);
            } else {
                throw new \RuntimeException('Supported targets: MySQL/MariaDB or isolated SQLite tests.');
            }
            $this->line(json_encode($server, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
            if ($snapshot && ($server->selected_schema !== $db->getDatabaseName()
                || $db->select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'", [], false))) {
                throw new \RuntimeException('Schema identity or transactional snapshot check failed.');
            }
            $report = (new CutoverInventoryReader($db))->inspect((bool) $this->option('details'));
            if ($snapshot && ($db->getPdo() !== $pdo || ! $pdo->inTransaction())) {
                throw new \RuntimeException('Read-only snapshot was lost.');
            }
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE));
            $this->line('Wallets and ledgers are retained. This verdict does not authorize deletion or verify remote provider state.');
            if ($report['blockers']) {
                $this->error('BLOCKED — current/unknown paid obligations require review');

                return self::FAILURE;
            }
            $this->info('PASS — no confirmed current paid obligations found');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            // Query exceptions may contain credentials, bindings or provider payloads.
            $this->error('Inventory unavailable: connection/schema/read check failed ('.class_basename($error).'). No acceptance verdict can be established.');
            $this->error('BLOCKED — current/unknown paid obligations require review');

            return self::FAILURE;
        } finally {
            if ($snapshot && $pdo?->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public static function targetHint(string $driver, mixed $host): string
    {
        if ($driver === 'sqlite' || in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            return 'Local endpoint (a tunnel can target a remote server; confirm server identity).';
        }
        if (is_string($host) && preg_match('/\.rds\.amazonaws\.com(?:\.cn)?$/i', $host)) {
            return 'Amazon RDS endpoint; production role is not independently verified.';
        }

        return 'Other/unverified database endpoint.';
    }

    private function revision(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', 'HEAD'], base_path());
            $process->setTimeout(3)->run();
            $revision = trim($process->getOutput());

            return $process->isSuccessful() && preg_match('/^[a-f0-9]{40,64}$/D', $revision) ? $revision : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
