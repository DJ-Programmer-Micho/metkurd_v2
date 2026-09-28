<?php

use App\Models\AdminAuditEvent;
use App\Models\LandingToolPage;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\User;
use App\Support\Landing\LandingToolPageCatalog;
use App\Support\Landing\PublicProductCatalog;
use App\Support\MetKurdV2ToolCatalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::preventStrayRequests();
    Http::fake();
    Storage::fake('local');
    Storage::fake('s3');
    Storage::fake('public');
    Cache::flush();
    $this->admin = User::forceCreate(['name' => 'Visibility Admin', 'email' => 'visibility@example.test',
        'password' => 'fixture', 'status' => 1, 'admin_capabilities' => ['admin.read', 'admin.catalog']]);
    $this->admin->profile()->create(['first_name' => 'Visibility', 'last_name' => 'Admin']);
    $this->actingAs($this->admin, 'admin');
    foreach (app(MetKurdV2ToolCatalog::class)->services() as $family) {
        foreach ($family['tools'] as $definition) {
            if (! isset($definition['legacy_action'])) {
                continue;
            }
            Tool::firstOrCreate(['code' => $definition['legacy_tool']], ['name' => $definition['name'], 'is_active' => true]);
            ToolAction::firstOrCreate(['full_code' => $definition['legacy_action']], [
                'tool_code' => $definition['legacy_tool'], 'action_code' => explode('.', $definition['legacy_action'])[1],
                'name' => $definition['name'], 'is_active' => true,
            ]);
        }
    }
});

it('keeps a stale active Translation row but never labels or filters it as public', function () {
    $row = LandingToolPage::create(['slug' => 'translation', 'is_active' => true, 'content' => ['en' => ['title' => 'Historical Translation']]]);
    expect($row->publicVisibilityStatus())->toBe('legacy');
    $component = Livewire::test('admin::pages.landing.adm-landing-tools')->set('search', 'translation')
        ->assertSee('data-public-status="legacy"', false)->assertDontSee('data-public-status="active"', false);
    $component->set('statusFilter', 'active')->assertDontSee('Historical Translation')
        ->set('statusFilter', 'inactive')->assertDontSee('Historical Translation')
        ->set('statusFilter', 'legacy')->assertSee('Historical Translation');
    expect($row->fresh()->is_active)->toBeTrue(); // Reads must not silently rewrite editorial data.
    expect(app(PublicProductCatalog::class)->family('translation'))->toBe([]);
    $this->get('/en/tools/translation')->assertNotFound();
});

it('shows each current family as Public Active and agrees with public discovery', function () {
    $catalog = app(PublicProductCatalog::class);
    expect($catalog->currentFamilySlugs())->toBe(['tts', 'ctts', 'asr', 'ocr', 'stem']);
    foreach ($catalog->currentFamilySlugs() as $slug) {
        $page = LandingToolPage::create(['slug' => $slug, 'is_active' => true]);
        expect($page->publicVisibilityStatus())->toBe('active');
    }
    $component = Livewire::test('admin::pages.landing.adm-landing-tools')->set('statusFilter', 'active');
    expect($component->get('toolPages')->pluck('slug')->sort()->values()->all())->toBe(collect($catalog->publicFamilySlugs())->sort()->values()->all());
    expect($component->get('topStats'))->toMatchArray(['active' => 5, 'inactive' => 0, 'legacy' => 0]);
    foreach ($catalog->currentFamilySlugs() as $slug) {
        $this->get('/en/tools/'.$slug)->assertOk();
    }
});

it('marks current families Disabled when publication or all parent tools or actions are inactive', function (string $cause) {
    $page = LandingToolPage::create(['slug' => 'tts', 'is_active' => true, 'content' => ['en' => ['title' => 'Disabled Fixture']]]);
    $products = app(PublicProductCatalog::class)->family('tts');
    if ($cause === 'publication') {
        $page->update(['is_active' => false]);
    } else {
        foreach ($products as $product) {
            ($cause === 'parent' ? Tool::where('code', $product['tool_code'])->firstOrFail()
                : ToolAction::findOrFail($product['action_id']))->update(['is_active' => false]);
        }
    }
    expect($page->fresh()->publicVisibilityStatus())->toBe('inactive')
        ->and(app(PublicProductCatalog::class)->family('tts'))->toBe([]);
    Livewire::test('admin::pages.landing.adm-landing-tools')->set('statusFilter', 'active')
        ->assertDontSee('Disabled Fixture')->set('statusFilter', 'inactive')
        ->assertSee('Disabled Fixture')->assertSee('data-public-status="inactive"', false);
    $this->get('/en/tools/tts')->assertNotFound();
})->with(['publication', 'parent', 'action']);

it('keeps a family public when at least one current product remains active', function () {
    $page = LandingToolPage::create(['slug' => 'tts', 'is_active' => true]);
    ToolAction::where('full_code', 'xomni.generate')->firstOrFail()->update(['is_active' => false]);
    expect($page->publicVisibilityStatus())->toBe('active')
        ->and(LandingToolPage::query()->withPublicStatus('active')->pluck('slug')->all())->toBe(['tts']);
});

it('imports only the five current families and preserves disabled and legacy editorial rows', function () {
    $retired = ['translation', 'delta', 'neo', 'apollo-1-0', 'vector-1-0', 'unknown-legacy'];
    foreach ($retired as $slug) {
        LandingToolPage::create(['slug' => $slug, 'is_active' => false, 'content' => ['en' => ['title' => 'Keep '.$slug]]]);
    }
    $disabled = LandingToolPage::create(['slug' => 'tts', 'is_active' => false, 'content' => ['en' => ['title' => 'Preserve current draft']]]);
    $before = DB::table('landing_tool_pages')->orderBy('id')->get()->keyBy('id');
    Livewire::test('admin::pages.landing.adm-landing-tools')
        ->set('adminChangeReason', 'Verify current-family-only import')->call('importDefaultTools')->assertHasNoErrors();
    expect(LandingToolPage::count())->toBe(11);
    foreach ($before as $id => $attributes) {
        expect((array) DB::table('landing_tool_pages')->find($id))->toBe((array) $attributes);
    }
    expect($disabled->fresh()->publicVisibilityStatus())->toBe('inactive');
    expect(app(LandingToolPageCatalog::class)->importFallbackDefaults())->toBe(0);
    LandingToolPage::whereIn('slug', $retired)->delete();
    expect(app(LandingToolPageCatalog::class)->importFallbackDefaults())->toBe(0)
        ->and(LandingToolPage::pluck('slug')->sort()->values()->all())->toBe(['asr', 'ctts', 'ocr', 'stem', 'tts']);
});

it('rejects direct attempts to reactivate a retired page but preserves editing access', function () {
    $page = LandingToolPage::create(['slug' => 'translation', 'is_active' => false]);
    $component = Livewire::test('admin::pages.landing.adm-landing-tools')
        ->set('adminChangeReason', 'Attempt to republish a retired page');
    $component->call('toggleToolPageStatus', $page->id)->assertHasErrors(['toolStatus']);
    $component->call('openToolEditModal', $page->id)->assertSet('toolStatus', 'inactive')
        ->set('toolStatus', 'active')->call('saveToolPage')->assertHasErrors(['toolStatus']);
    expect($page->fresh()->is_active)->toBeFalse();
});

it('retains Admin capability checks on imports and status mutations', function () {
    $page = LandingToolPage::create(['slug' => 'translation', 'is_active' => false]);
    $component = Livewire::test('admin::pages.landing.adm-landing-tools')->set('adminChangeReason', 'No implicit catalog authorization');
    $this->admin->forceFill(['admin_capabilities' => ['admin.read']])->save();
    $component->call('importDefaultTools')->assertForbidden();
    Livewire::test('admin::pages.landing.adm-landing-tools')->set('adminChangeReason', 'No implicit catalog authorization')
        ->call('toggleToolPageStatus', $page->id)->assertForbidden();
    expect($page->fresh()->is_active)->toBeFalse();
});

it('corrects only Translation publication with an idempotent audited data migration', function () {
    $page = LandingToolPage::create(['slug' => 'translation', 'is_active' => true,
        'content' => ['en' => ['title' => 'Historical content']], 'demo_config' => ['legacy' => 'retain'], 'sort_order' => 17]);
    $current = LandingToolPage::create(['slug' => 'tts', 'is_active' => true]);
    $before = (array) DB::table('landing_tool_pages')->find($page->id);
    $other = (array) DB::table('landing_tool_pages')->find($current->id);
    $migration = require database_path('migrations/2026_09_27_120000_retire_translation_landing_publication.php');
    $migration->up();
    $before['is_active'] = 0;
    expect((array) DB::table('landing_tool_pages')->find($page->id))->toBe($before)
        ->and((array) DB::table('landing_tool_pages')->find($current->id))->toBe($other);
    $migration->up();
    $migration->down();
    expect($page->fresh()->is_active)->toBeFalse();
    $events = AdminAuditEvent::where('action', 'landing.translation.retired')->get();
    expect($events)->toHaveCount(1)
        ->and($events[0]->admin_id)->toBeNull()
        ->and($events[0]->target_type)->toBe(LandingToolPage::class)
        ->and($events[0]->before_state)->toBe(['slug' => 'translation', 'is_active' => true])
        ->and($events[0]->after_state)->toBe(['slug' => 'translation', 'is_active' => false]);
});
