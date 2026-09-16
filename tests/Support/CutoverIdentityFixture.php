<?php

namespace Tests\Support;

use App\Services\Billing\Cutover\CutoverIdentity;

/** Server facts only are simulated. The real policies and shared mutation algorithm still run. */
class CutoverIdentityFixture extends CutoverIdentity
{
    public array $connectionFacts = [];

    public array $serverFacts = [];

    public static function install(string $target = 'local-rehearsal'): self
    {
        $fixture = new self;
        $host = $target === 'production' ? 'cutover-fixture.example.us-east-1.rds.amazonaws.com' : '127.0.0.1';
        $fixture->connectionFacts = ['environment' => $target === 'production' ? 'production' : 'local', 'driver' => 'mysql',
            'host' => $host, 'configured_port' => 3306, 'configured_schema' => 'cutover_fixture',
            'split' => false, 'prefix' => false, 'socket' => false];
        $fixture->serverFacts = ['schema' => 'cutover_fixture', 'version' => '8.4.8', 'engine_family' => 'mysql',
            'version_comment' => 'MySQL Community Server - GPL', 'hostname' => $target === 'production' ? 'remote-fixture' : gethostname(),
            'port' => 3306, 'foreign_keys' => 1, 'read_only' => 0, 'super_read_only' => 0, 'replica_channels' => 0, 'server_uuid' => 'fixture-server-identity'];
        config(['billing_cutover.enabled' => true, 'billing_cutover.target' => $target,
            'billing_cutover.expected_host' => $host, 'billing_cutover.expected_database' => 'cutover_fixture',
            'billing_cutover.expected_port' => 3306, 'billing_cutover.backup_reference' => 'fixture-backup',
            'billing_cutover.restore_reference' => 'fixture-restore']);
        app()->instance(CutoverIdentity::class, $fixture);

        return $fixture;
    }

    protected function context(): array
    {
        return $this->connectionFacts;
    }

    protected function server(): array
    {
        return $this->serverFacts;
    }
}
