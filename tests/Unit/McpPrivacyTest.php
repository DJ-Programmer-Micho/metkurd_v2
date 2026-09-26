<?php

namespace Tests\Unit;

use App\Services\Mcp\PrivateSessionStore;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Uid\Uuid;
use Tests\TestCase;

class McpPrivacyTest extends TestCase
{
    public function test_sdk_sessions_retain_handshake_but_drop_arbitrary_payloads(): void
    {
        $store = new PrivateSessionStore(Cache::store('array'), 'privacy-test-');
        $id = Uuid::v4();
        $store->write($id, json_encode(['initialized' => true, 'protocol_version' => '2025-11-25',
            '_mcp' => ['active_request_meta' => ['conversation' => 'PRIVATE'], 'responses' => ['PRIVATE'], 'outgoing_queue' => ['ENCRYPTED_RESULT']]], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('PRIVATE', $store->read($id));
        $this->assertStringContainsString('ENCRYPTED_RESULT', $store->read($id));
        $this->assertStringNotContainsString('ENCRYPTED_RESULT', Cache::store('array')->get('privacy-test-'.$id));
        $this->assertTrue(json_decode($store->read($id), true)['initialized']);
        $this->assertSame('2025-11-25', json_decode($store->read($id), true)['protocol_version']);
    }

    public function test_mcp_locale_keys_and_placeholders_match(): void
    {
        $english = require resource_path('lang/en/mcp.php');
        foreach (['ar', 'ku'] as $locale) {
            $translated = require resource_path('lang/'.$locale.'/mcp.php');
            $this->assertSame(array_keys($english), array_keys($translated));
            foreach ($translated as $value) {
                $this->assertNotSame('', $value);
                $this->assertStringNotContainsString('RunPod', $value);
            }
        }
    }
}
