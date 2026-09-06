<?php

use App\Models\Customer;
use App\Support\AreaJsonTranslations;
use App\Support\CustomerFacingError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

it('resolves the same customer translation area for V1 and V2 routes', function (string $locale) {
    app()->setLocale($locale);
    foreach (["/{$locale}/app", "/{$locale}/app-v2", "/{$locale}/app-v2/storage"] as $path) {
        app()->instance('request', Request::create($path));
        expect(AreaJsonTranslations::get('Audio uploaded'))
            ->toBe(AreaJsonTranslations::get('Audio uploaded', 'app', $locale));
    }
})->with(['en', 'ar', 'ku']);

it('localizes known errors and hides arbitrary provider details', function (string $locale) {
    app()->setLocale($locale);
    $catalog = AreaJsonTranslations::all('app', $locale);
    expect(CustomerFacingError::message('That saved reference voice is no longer available.'))
        ->toBe($catalog['That saved reference voice is no longer available.']);
    expect(CustomerFacingError::message($catalog['That saved reference voice is no longer available.']))
        ->toBe($catalog['That saved reference voice is no longer available.']);
    expect(CustomerFacingError::message('RunPod did not return a provider job ID.'))
        ->toBe($catalog['RunPod did not return a provider job ID.']);
    foreach (['RunPod request failed: private diagnostic', 'Unexpected storage exception', ''] as $message) {
        expect(CustomerFacingError::message($message))
            ->toBe($catalog['Processing could not be completed. Please try again.']);
    }
})->with(['en', 'ar', 'ku']);

it('covers literal V2 copy and configured service labels in every customer locale', function () {
    $pattern = <<<'REGEX'
~__\(\s*'((?:\\.|[^'\\])*)'~
REGEX;
    $keys = [];
    foreach (File::allFiles(resource_path('views/app/v2')) as $file) {
        preg_match_all($pattern, $file->getContents(), $matches);
        foreach ($matches[1] as $key) {
            $keys[] = str_replace("\\'", "'", $key);
        }
    }
    foreach (config('metkurd_v2.services') as $service) {
        $keys[] = $service['name'];
        $keys[] = $service['description'];
        foreach ($service['tools'] as $tool) {
            $keys[] = $tool['name'];
        }
    }
    foreach (['en', 'ar', 'ku'] as $locale) {
        $catalog = AreaJsonTranslations::all('app', $locale);
        expect(array_values(array_diff(array_unique($keys), array_keys($catalog))))->toBe([]);
        foreach ($catalog as $value) {
            expect($value)->toBeString()->not->toMatch('/runpod|PH_\d+__/i');
        }
    }
});

it('renders the V2 notification assets and translated settings with locale direction', function (string $locale, string $direction) {
    $this->seed();
    $customer = Customer::create([
        'username' => "locale_{$locale}", 'email' => "locale-{$locale}@example.com",
        'password' => 'Secret123!', 'status' => 1, 'email_verify' => true, 'phone_verify' => true,
    ]);
    config()->set('metkurd_v2.enabled', true);
    $this->actingAs($customer, 'app')->get(route('app.v2.home', ['locale' => $locale]))
        ->assertOk()
        ->assertSee('dir="'.$direction.'"', false)
        ->assertSee('app/libs/sweetalert2/sweetalert2.min.js', false)
        ->assertSee('window.metkurdV2Alerts', false)
        ->assertSee(AreaJsonTranslations::get('Create with MetKurd AI', 'app', $locale));
})->with([['en', 'ltr'], ['ar', 'rtl'], ['ku', 'rtl']]);

it('does not expose provider exceptions on customer download or JSON error responses even in debug mode', function () {
    config()->set('app.debug', true);
    Illuminate\Support\Facades\Route::get('/en/app-v2/test-provider-error', fn () => throw new RuntimeException('RunPod private diagnostic and endpoint details'));
    $this->get('/en/app-v2/test-provider-error')->assertStatus(500)->assertDontSee('RunPod')->assertDontSee('private diagnostic');
    $this->getJson('/en/app-v2/test-provider-error')->assertStatus(500)->assertJson(['message' => CustomerFacingError::message(null)])->assertDontSee('RunPod');
});

it('localizes inline required upload type and size validation in every locale', function (string $locale) {
    app()->setLocale($locale);
    $messages = require resource_path("lang/{$locale}/validation.php");
    $required = Illuminate\Support\Facades\Validator::make([], ['audioFile' => 'required']);
    expect($required->errors()->first('audioFile'))->toBe(str_replace(':attribute', $messages['attributes']['audioFile'], $messages['required']));
    $file = Illuminate\Http\UploadedFile::fake()->create('document.txt', 110000, 'text/plain');
    $invalid = Illuminate\Support\Facades\Validator::make(['documentFile' => $file], ['documentFile' => 'file|mimes:pdf|max:102400']);
    expect($invalid->errors()->get('documentFile'))->toContain(
        str_replace([':attribute', ':values'], [$messages['attributes']['documentFile'], 'pdf'], $messages['mimes']),
        str_replace([':attribute', ':max'], [$messages['attributes']['documentFile'], '102400'], $messages['max']['file'])
    );
})->with(['en', 'ar', 'ku']);

it('renders the OCR workspace and keeps required upload validation inline', function (string $locale) {
    $this->seed();
    $customer = Customer::create(['username' => 'ocr-locale-'.$locale, 'email' => 'ocr-locale-'.$locale.'@example.com', 'password' => 'Secret123!', 'status' => 1]);
    app()->setLocale($locale);
    Illuminate\Support\Facades\Lang::addJsonPath(resource_path('lang/app'));
    $messages = require resource_path("lang/{$locale}/validation.php");
    Livewire\Livewire::actingAs($customer, 'app')->test('app::v2.pages.tools.app-ocr')
        ->assertSee(AreaJsonTranslations::get('Scan Document', 'app', $locale))
        ->call('submitOcr')->assertHasErrors(['documentFile' => 'required'])
        ->assertSee(str_replace(':attribute', $messages['attributes']['documentFile'], $messages['required']));
})->with(['en', 'ar', 'ku']);
