<?php

use App\Models\Tool;
use App\Models\ToolAction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('requires both the production environment and disabled effective debug configuration', function ($environment, $debug, $expected) {
    expect(config('database.default'))->toBe('sqlite')->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    Http::preventStrayRequests();
    $original = app()->environment();
    $originalDebug = config('app.debug');
    try {
        app()->instance('env', $environment);
        config(['app.debug' => $debug]);
        Artisan::call('metkurd:production-preflight', ['--production' => true]);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($output['environment'])->toBe($environment)
            ->and($output['checks']['production_environment'])->toBe($expected);
    } finally {
        app()->instance('env', $original);
        config(['app.debug' => $originalDebug]);
    }
    Http::assertNothingSent();
})->with([['production', false, true], ['production', true, false], ['local', false, false]]);

it('requires every current product and rejects missing or inactive new service identities without repairing them', function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(DB::connection()->getDatabaseName())->toBe(':memory:');
    Http::preventStrayRequests();
    $this->seed();

    // The production baseline retains these identities; development seeders do not.
    foreach (['xomni.generate', 'clone_xomni.generate'] as $code) {
        [$toolCode, $actionCode] = explode('.', $code);
        Tool::updateOrCreate(['code' => $toolCode], ['name' => $toolCode, 'is_active' => true]);
        ToolAction::updateOrCreate(['full_code' => $code], ['tool_code' => $toolCode,
            'action_code' => $actionCode, 'name' => $code, 'is_active' => true]);
    }
    $expected = ['xomni.generate', 'xomni-v2.generate', 'clone_xomni.generate',
        'vector-v2.generate', 'zeta.generate', 'theta.generate', 'leo.transcribe',
        'caption.standard', 'ocr.standard', 'harakat.diacritize', 'stem.sep2', 'stem.sep4'];
    expect(Artisan::call('metkurd:production-preflight'))->toBe(0);
    $checks = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'];
    expect(array_keys(array_filter($checks, fn ($key) => str_starts_with($key, 'action:'), ARRAY_FILTER_USE_KEY)))
        ->toEqualCanonicalizing(array_map(fn ($code) => 'action:'.$code, $expected));

    foreach (['zeta.generate', 'theta.generate', 'harakat.diacritize'] as $code) {
        $action = ToolAction::where('full_code', $code)->firstOrFail();
        $tool = Tool::where('code', $action->tool_code)->firstOrFail();
        foreach (['missing_identity', 'inactive_action', 'inactive_tool'] as $condition) {
            // Roll back fixture mutations so each failure is independently tested.
            DB::beginTransaction();
            try {
                match ($condition) {
                    'missing_identity' => DB::table('tool_actions')->where('id', $action->id)->update(['full_code' => 'fixture.missing']),
                    'inactive_action' => DB::table('tool_actions')->where('id', $action->id)->update(['is_active' => false]),
                    'inactive_tool' => DB::table('tools')->where('id', $tool->id)->update(['is_active' => false]),
                };
                $beforeAction = $action->fresh()->getAttributes();
                $beforeTool = $tool->fresh()->getAttributes();
                expect(Artisan::call('metkurd:production-preflight'))->toBe(1);
                $failed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['checks'];
                expect($failed['action:'.$code])->toBeFalse()
                    ->and($action->fresh()->getAttributes())->toBe($beforeAction)
                    ->and($tool->fresh()->getAttributes())->toBe($beforeTool);
            } finally {
                DB::rollBack();
            }
        }
    }
    expect(Artisan::call('metkurd:production-preflight'))->toBe(0);
    Http::assertNothingSent();
});
