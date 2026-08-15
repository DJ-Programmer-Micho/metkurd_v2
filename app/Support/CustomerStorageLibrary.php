<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerFile;
use App\Models\MlJob;
use Illuminate\Support\Collection;

class CustomerStorageLibrary
{
    /** @return array{key: string, service_key: string, service: string, title: string, icon_asset: string, accent: string} */
    public function identity(CustomerFile $file, ?MlJob $job = null): array
    {
        $model = (string) ($job?->model_key ?: data_get($file->meta, 'model_key', ''));

        return match ((string) $file->tool_code) {
            'xomni' => $model === 'model_2'
                ? $this->definition('apollo-2', 'text-to-speech', 'Text-to-Speech', 'Apollo 2.0v', 'TTS.png', 'blue')
                : $this->definition('apollo-1', 'text-to-speech', 'Text-to-Speech', 'Apollo 1.5v', 'TTS.png', 'blue'),
            'xomni-v2' => $this->definition('apollo-2', 'text-to-speech', 'Text-to-Speech', 'Apollo 2.0v', 'TTS.png', 'blue'),
            'clone_xomni' => $model === 'model_2'
                ? $this->definition('vector-2', 'clone-text-to-speech', 'Clone Text-to-Speech', 'Vector 2.0v', 'CTTS.png', 'rose')
                : $this->definition('vector-1', 'clone-text-to-speech', 'Clone Text-to-Speech', 'Vector 1.5v', 'CTTS.png', 'rose'),
            'tts' => $this->definition('apollo-legacy', 'text-to-speech', 'Text-to-Speech', 'Apollo 1.0v', 'TTS.png', 'blue'),
            'ftts' => $this->definition('delta', 'text-to-speech', 'Text-to-Speech', 'Delta', 'TTS.png', 'violet'),
            'clone_tts', 'clone-tts' => $this->definition('vector-legacy', 'clone-text-to-speech', 'Clone Text-to-Speech', 'Vector 1.0v', 'CTTS.png', 'rose'),
            'wasr', 'asr' => $this->definition('wasr', 'speech-to-text', 'Speech-to-Text', 'WASR NEO', 'ASR.png', 'emerald'),
            'qasr' => $this->definition('qasr', 'speech-to-text', 'Speech-to-Text', 'QASR LEO', 'ASR.png', 'emerald'),
            'caption' => $this->definition('caption', 'speech-to-text', 'Speech-to-Text', 'Caption', 'ASR.png', 'emerald'),
            'tran' => $this->definition('translation', 'speech-to-text', 'Speech-to-Text', 'MET Translation', 'ASR.png', 'emerald'),
            'ocr' => $this->definition('ocr', 'ocr', 'OCR', 'OCR Scanner', 'OCR.png', 'amber'),
            'stem' => $this->definition('stem', 'stem', 'STEM', 'Stem Separation', 'STEM.png', 'cyan'),
            default => $this->definition('legacy-'.((string) $file->tool_code ?: 'output'), 'legacy', 'Legacy Outputs', strtoupper((string) $file->tool_code ?: 'Output'), 'ASR.png', 'slate'),
        };
    }

    /** @return array<int, array{key: string, label: string, icon_asset: string, accent: string, products: array<int, array<string, mixed>>, file_count: int, size_bytes: int}> */
    public function navigation(Customer $customer): array
    {
        $files = CustomerFile::query()
            ->where('customer_id', (int) $customer->id)
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('retention_mode')->orWhere('retention_mode', '!=', 'temporary'))
            ->get(['id', 'tool_code', 'path', 'size_bytes', 'meta', 'source_type', 'source_id']);

        $jobs = $this->jobsFor($customer, $files);
        $products = [];

        foreach ($files as $file) {
            $jobId = $this->jobIdFor($file);
            $identity = $this->identity($file, $jobs->get($jobId));
            $key = $identity['key'];

            if (! isset($products[$key])) {
                $products[$key] = array_merge($identity, ['file_count' => 0, 'size_bytes' => 0]);
            }

            $products[$key]['file_count']++;
            $products[$key]['size_bytes'] += max(0, (int) $file->size_bytes);
        }

        return collect($products)
            ->groupBy('service_key')
            ->map(function (Collection $items, string $serviceKey): array {
                $first = $items->first();

                return [
                    'key' => $serviceKey,
                    'label' => $first['service'],
                    'icon_asset' => $first['icon_asset'],
                    'accent' => $first['accent'],
                    'products' => $items->sortBy('title')->values()->all(),
                    'file_count' => (int) $items->sum('file_count'),
                    'size_bytes' => (int) $items->sum('size_bytes'),
                ];
            })
            ->sortBy(function (array $service): int {
                $order = array_search($service['key'], ['text-to-speech', 'clone-text-to-speech', 'speech-to-text', 'ocr', 'stem', 'legacy'], true);

                return $order === false ? 99 : $order;
            })
            ->values()
            ->all();
    }

    public function productFor(Customer $customer, string $key): ?array
    {
        foreach ($this->navigation($customer) as $service) {
            foreach ($service['products'] as $product) {
                if ($product['key'] === $key) {
                    return $product;
                }
            }
        }

        return null;
    }

    /** @param Collection<int, CustomerFile> $files @return Collection<string, MlJob> */
    protected function jobsFor(Customer $customer, Collection $files): Collection
    {
        $jobIds = $files->map(fn (CustomerFile $file) => $this->jobIdFor($file))->filter()->unique()->values();

        if ($jobIds->isEmpty()) {
            return collect();
        }

        return MlJob::query()
            ->where('customer_id', (int) $customer->id)
            ->whereIn('id', $jobIds)
            ->get(['id', 'model_key'])
            ->keyBy('id');
    }

    protected function jobIdFor(CustomerFile $file): string
    {
        return (string) (data_get($file->meta, 'job_id') ?: (($file->source_type === 'ml_job') ? $file->source_id : ''));
    }

    /** @return array{key: string, service_key: string, service: string, title: string, icon_asset: string, accent: string} */
    protected function definition(string $key, string $serviceKey, string $service, string $title, string $icon, string $accent): array
    {
        return [
            'key' => $key,
            'service_key' => $serviceKey,
            'service' => __($service),
            'title' => __($title),
            'icon_asset' => 'app/services_icons/'.$icon,
            'accent' => $accent,
        ];
    }
}
