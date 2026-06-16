<?php

namespace App\Services\Mobile;

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use App\Support\AppToolCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class MobileAppCatalog
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected const APPS = [
        'tts' => [
            'name' => 'METKURD - TTS',
            'tool_codes' => ['tts', 'ftts'],
            'job_kinds' => ['tts', 'ftts'],
            'file_tool_codes' => ['tts', 'ftts'],
            'primary_tool_code' => 'tts',
            'job_submission_enabled' => true,
            'upload' => ['enabled' => false],
        ],
        'ctts' => [
            'name' => 'METKURD - CTTS',
            'tool_codes' => ['clone_tts'],
            'job_kinds' => ['clone_tts'],
            'file_tool_codes' => ['clone_tts'],
            'primary_tool_code' => 'clone_tts',
            'job_submission_enabled' => true,
            'upload' => [
                'enabled' => true,
                'field' => 'file',
                'rules' => [
                    'required',
                    'file',
                    'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac',
                    'max:51200',
                ],
                'purpose' => 'reference_audio',
            ],
        ],
        'asr' => [
            'name' => 'METKURD - ASR',
            'tool_codes' => ['asr', 'wasr', 'qasr', 'caption'],
            'job_kinds' => ['asr', 'wasr', 'qasr', 'caption'],
            'file_tool_codes' => ['asr', 'wasr', 'qasr', 'caption'],
            'primary_tool_code' => 'wasr',
            'job_submission_enabled' => true,
            'upload' => [
                'enabled' => true,
                'field' => 'file',
                'rules' => [
                    'required',
                    'file',
                    'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac',
                    'max:102400',
                ],
                'purpose' => 'input_audio',
            ],
        ],
        'stem' => [
            'name' => 'METKURD - STEM',
            'tool_codes' => ['stem'],
            'job_kinds' => ['stem'],
            'file_tool_codes' => ['stem'],
            'primary_tool_code' => 'stem',
            'job_submission_enabled' => true,
            'upload' => [
                'enabled' => true,
                'field' => 'file',
                'rules' => [
                    'required',
                    'file',
                    'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp3,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/webm,audio/flac,audio/x-flac',
                    'max:102400',
                ],
                'purpose' => 'input_audio',
            ],
        ],
        'ocr' => [
            'name' => 'METKURD - OCR',
            'tool_codes' => ['ocr'],
            'job_kinds' => ['ocr'],
            'file_tool_codes' => ['ocr'],
            'primary_tool_code' => 'ocr',
            'job_submission_enabled' => true,
            'upload' => [
                'enabled' => true,
                'field' => 'file',
                'rules' => [
                    'required',
                    'file',
                    'mimes:pdf',
                    'max:204800',
                ],
                'purpose' => 'input_document',
            ],
        ],
        'tran' => [
            'name' => 'METKURD - TRAN',
            'tool_codes' => ['tran'],
            'job_kinds' => ['tran'],
            'file_tool_codes' => ['tran'],
            'primary_tool_code' => 'tran',
            'job_submission_enabled' => true,
            'upload' => ['enabled' => false],
        ],
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return collect(array_keys(self::APPS))
            ->map(fn (string $slug) => $this->for($slug))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function for(string $slug): ?array
    {
        $slug = strtolower(trim($slug));

        if (! array_key_exists($slug, self::APPS)) {
            return null;
        }

        return array_merge(['slug' => $slug], self::APPS[$slug]);
    }

    public function customerCanAccess(Customer $customer, string $slug): bool
    {
        $app = $this->for($slug);

        if (! $app) {
            return false;
        }

        return $customer->canAccessAnyTool((array) ($app['tool_codes'] ?? []));
    }

    public function tokenCanAccessApp(Request $request, string $slug): bool
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token) {
            return false;
        }

        return $token->can('mobile:*') || $token->can('mobile:'.strtolower(trim($slug)));
    }

    /**
     * @return array<int, string>
     */
    public function abilitiesFor(?string $slug = null): array
    {
        $slug = strtolower(trim((string) $slug));

        if ($slug === '') {
            return ['mobile', 'mobile:*'];
        }

        return ['mobile', 'mobile:'.$slug];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function appsForCustomer(Customer $customer): array
    {
        return collect($this->all())
            ->filter(fn (array $app) => $this->customerCanAccess($customer, (string) $app['slug']))
            ->map(fn (array $app) => [
                'slug' => (string) $app['slug'],
                'name' => (string) $app['name'],
                'tool_codes' => array_values((array) ($app['tool_codes'] ?? [])),
                'job_submission_enabled' => (bool) ($app['job_submission_enabled'] ?? false),
                'upload_enabled' => (bool) data_get($app, 'upload.enabled', false),
            ])
            ->values()
            ->all();
    }

    public function jobsQuery(Customer $customer, string $slug): Builder
    {
        $app = $this->for($slug);
        $toolIds = app(AppToolCatalog::class)->toolIds((array) ($app['tool_codes'] ?? []));
        $jobKinds = array_values((array) ($app['job_kinds'] ?? []));

        return MlJob::query()
            ->where('customer_id', (int) $customer->id)
            ->where(function (Builder $query) use ($jobKinds, $toolIds) {
                $applied = false;

                if ($jobKinds !== []) {
                    $query->whereIn('job_kind', $jobKinds);
                    $applied = true;
                }

                if ($toolIds !== []) {
                    if ($applied) {
                        $query->orWhereIn('tool_id', $toolIds);
                    } else {
                        $query->whereIn('tool_id', $toolIds);
                        $applied = true;
                    }
                }

                if (! $applied) {
                    $query->whereRaw('1 = 0');
                }
            });
    }

    public function filesQuery(Customer $customer, string $slug): Builder
    {
        $app = $this->for($slug);
        $toolCodes = array_values((array) ($app['file_tool_codes'] ?? []));

        return CustomerFile::query()
            ->where('customer_id', (int) $customer->id)
            ->where('status', 'active')
            ->whereIn('tool_code', $toolCodes);
    }

    /**
     * @return array<string, mixed>
     */
    public function uploadConfig(string $slug): array
    {
        return (array) data_get($this->for($slug), 'upload', []);
    }

    public function uploadEnabled(string $slug): bool
    {
        return (bool) data_get($this->for($slug), 'upload.enabled', false);
    }

    public function appSlugForJob(MlJob $job): ?string
    {
        $job->loadMissing('tool:id,code');
        $toolCode = strtolower(trim((string) ($job->tool?->code ?? '')));
        $jobKind = strtolower(trim((string) ($job->job_kind ?? '')));

        foreach ($this->all() as $app) {
            if (in_array($jobKind, (array) ($app['job_kinds'] ?? []), true)) {
                return (string) $app['slug'];
            }

            if ($toolCode !== '' && in_array($toolCode, (array) ($app['tool_codes'] ?? []), true)) {
                return (string) $app['slug'];
            }
        }

        return null;
    }

    public function appSlugForFile(CustomerFile $file): ?string
    {
        $toolCode = strtolower(trim((string) ($file->tool_code ?? '')));

        foreach ($this->all() as $app) {
            if ($toolCode !== '' && in_array($toolCode, (array) ($app['file_tool_codes'] ?? []), true)) {
                return (string) $app['slug'];
            }
        }

        return null;
    }

    public function primaryToolCode(string $slug): string
    {
        return (string) data_get($this->for($slug), 'primary_tool_code', $slug);
    }
}
