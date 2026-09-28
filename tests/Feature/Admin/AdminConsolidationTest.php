<?php

use App\Models\AdminAuditEvent;
use App\Models\AdminOperation;
use App\Models\User;
use App\Models\Voice;
use App\Services\Admin\AdminOperations;
use App\Support\Admin\AdminNavigation;
use App\Support\Admin\AdminServiceWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    expect(config('database.default'))->toBe('sqlite')->and(config('database.connections.sqlite.database'))->toBe(':memory:');
    Http::fake();
    $this->seed();
    $this->operator = User::forceCreate(['name' => 'Consolidation fixture', 'email' => Str::uuid().'@example.test', 'password' => 'fixture', 'status' => 1, 'admin_capabilities' => ['admin.read']]);
    $this->actingAs($this->operator, 'admin');
});

it('inventories every Admin route and has no duplicate sidebar destinations', function () {
    $inventory = file_get_contents(base_path('docs/metkurd/ADMIN-FINAL-CONSOLIDATION.md'));
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->getName() ?? '', 'admin.')) {
            expect($inventory)->toContain('`'.$route->getName().'`');
        }
    }
    $items = collect(AdminNavigation::groups())->flatten(1);
    $destinations = $items->map(fn ($item) => $item['route'].json_encode($item['query']));
    expect($destinations->unique())->toHaveCount($items->count());
    expect(collect(AdminNavigation::groups()['developer'])->pluck('query.section'))->toContain('jobs', 'api', 'mcp');
    expect($items->pluck('route'))->not->toContain('admin.services.rules');
});

it('uses section-specific titles and clearly labels retained coupon controls', function (string $locale) {
    app()->setLocale($locale);
    foreach (['jobs', 'api', 'mcp', 'reservations', 'files', 'audit', 'payments'] as $section) {
        $html = $this->get(route('admin.operations', ['locale' => $locale, 'section' => $section]))->assertOk()->getContent();
        expect($html)->toContain('<title>'.e(__('admin_p2.'.$section)).' |');
        expect($html)->not->toContain('admin_shell.', 'admin_developer.');
    }
    $this->get(route('admin.payments.coupons', ['locale' => $locale]))->assertOk()->assertSee(__('admin_shell.legacy_coupons_help'));
    foreach (['addons' => 'addons', 'storage' => 'storage_plans', 'plans' => 'plans', 'methods' => 'methods', 'currencies' => 'currencies'] as $page => $label) {
        $html = $this->get(route('admin.payments.'.$page, ['locale' => $locale]))->assertOk()->getContent();
        expect($html)->toContain('<title>'.e(__('admin_shell.'.$label)).' |', '<h4 class="mb-sm-0">'.e(__('admin_shell.'.$label)).'</h4>');
    }
    Http::assertNothingSent();
})->with(['en', 'ar', 'ku']);

it('uses terminal status colors without changing persisted states', function () {
    foreach (['revoked' => 'danger', 'delete_failed' => 'danger', 'deleted' => 'secondary', 'settled' => 'success', 'released' => 'success', 'queued' => 'warning', 'done' => 'success'] as $status => $tone) {
        $html = view('components.admin-operation-status', ['row' => ['status' => $status]])->render();
        expect($html)->toContain('text-'.$tone, e(__('admin_p2.'.$status)));
    }
});

it('projects audit operation status without per-row operation queries or private operation data', function () {
    foreach (range(1, 25) as $n) {
        $operation = AdminOperation::create(['id' => (string) Str::uuid(), 'admin_id' => $this->operator->id, 'action' => 'fixture.read', 'payload_hash' => str_repeat('a', 64), 'requested' => ['secret' => 'PRIVATE_OPERATION'], 'reason' => 'Fixture reason', 'status' => 'completed', 'result' => ['body' => 'PRIVATE_RESULT']]);
        AdminAuditEvent::create(['admin_id' => $this->operator->id, 'operation_id' => $operation->id, 'action' => 'fixture.read', 'target_type' => AdminOperation::class, 'target_id' => $operation->id]);
    }
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = $query->sql;
    });
    $reader = app(AdminOperations::class);
    $rows = $reader->query('audit')->limit(25)->get()->map(fn ($row) => $reader->row($row));
    expect($rows->pluck('operation_status')->unique()->all())->toBe(['completed']);
    expect(json_encode($rows))->not->toContain('PRIVATE_');
    expect(collect($sql)->filter(fn ($query) => str_contains($query, 'from "admin_operations"')))->toHaveCount(1);
});

it('resolves Omni plan availability once per page rather than once per voice', function () {
    $voices = collect(range(1, 25))->map(fn ($n) => (new Voice)->forceFill(['id' => $n, 'code' => 'voice-'.$n, 'is_active' => true, 'meta' => ['engine' => 'xomni']]));
    $planCount = \App\Models\ServicePlan::count();
    $this->mock(\App\Services\MetKurd\Omni\OmniSpeakerCatalog::class)->shouldReceive('forCustomer')->times($planCount)->andReturn([]);
    expect(app(AdminServiceWorkspace::class)->voiceSummaries($voices))->toHaveCount(25);
});
