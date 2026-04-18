<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

use App\Models\ServicePlan;
use App\Models\StoragePlan;
use App\Models\Tool;
use App\Models\ToolAction;
use App\Models\PlanEntitlement;
use App\Models\PricingRule;
use App\Models\CreditProduct;
use App\Models\Voice;
use App\Models\PlanVoiceAccess;
use App\Services\Billing\BillingCurrencyService;

class DevDefaultSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $plans = $this->seedServicePlans();
            $this->seedStoragePlans();
            $this->seedCreditProducts();
            [$tools, $actions] = $this->seedToolsAndActions();
            $this->seedPlanEntitlements($plans, $actions);
            $this->seedPricingRules($actions);
            $this->seedVoicesAndAccess($plans);
        });
    }

    private function seedServicePlans(): array
    {
        $currency = app(BillingCurrencyService::class);
        $uiFeatures = $this->servicePlanUiFeatures();

        $rows = [
            [
                'code' => 'free',
                'name' => 'Free',
                'billing_interval' => 'monthly',
                'monthly_credits' => 10000,
                'concurrent_jobs_limit' => 2,
                'is_free' => true,
                'is_active' => true,
                'sort_order' => 1,
                'price_usd_monthly' => 0,
                'price_usd_yearly' => 0,
                'price_iqd_monthly' => $currency->legacyUsdAmountToIqd(0),
                'price_iqd_yearly' => $currency->legacyUsdAmountToIqd(0),
                'ui_features' => $uiFeatures['free'],
            ],
            [
                'code' => 'student',
                'name' => 'Student',
                'billing_interval' => 'monthly',
                'monthly_credits' => 50000,
                'concurrent_jobs_limit' => 3,
                'is_free' => false,
                'is_active' => true,
                'sort_order' => 2,
                'price_usd_monthly' => 10,
                'price_usd_yearly' => 96,
                'price_iqd_monthly' => $currency->legacyUsdAmountToIqd(10),
                'price_iqd_yearly' => $currency->legacyUsdAmountToIqd(96),
                'ui_features' => $uiFeatures['student'],
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'billing_interval' => 'monthly',
                'monthly_credits' => 100000,
                'concurrent_jobs_limit' => 4,
                'is_free' => false,
                'is_active' => true,
                'sort_order' => 3,
                'price_usd_monthly' => 20,
                'price_usd_yearly' => 192,
                'price_iqd_monthly' => $currency->legacyUsdAmountToIqd(20),
                'price_iqd_yearly' => $currency->legacyUsdAmountToIqd(192),
                'ui_features' => $uiFeatures['pro'],
            ],
            [
                'code' => 'premium',
                'name' => 'Premium',
                'billing_interval' => 'monthly',
                'monthly_credits' => 250000,
                'concurrent_jobs_limit' => 5,
                'is_free' => false,
                'is_active' => true,
                'sort_order' => 4,
                'price_usd_monthly' => 50,
                'price_usd_yearly' => 480,
                'price_iqd_monthly' => $currency->legacyUsdAmountToIqd(50),
                'price_iqd_yearly' => $currency->legacyUsdAmountToIqd(480),
                'ui_features' => $uiFeatures['premium'],
            ],
        ];

        $out = [];

        foreach ($rows as $row) {
            $out[$row['code']] = ServicePlan::updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }

        return $out;
    }

    private function servicePlanUiFeatures(): array
    {
        return [
            'free' => [
                'en' => [
                    'badge' => 'FREE',
                    'title' => 'Free',
                    'summary' => 'Everything included for getting started.',
                    'recommended' => false,
                    'cta' => 'Try it free',
                    'features' => [
                        'Everything included',
                        '10,000 credits per month',
                        '5000 hours of text-to-speech per month',
                        '4200 hours of voice clone per month',
                        '1200 hours of speech-to-text per month',
                        '10 ~ 20 Songs Separation',
                        '40 Pages Text Extraction OCR',
                        'All models and features',
                        'Commercial license',
                    ],
                ],
                'ar' => [
                    'badge' => 'مجاني',
                    'title' => 'مجاني',
                    'summary' => 'كل ما تحتاجه للبدء متوفر.',
                    'recommended' => false,
                    'cta' => 'جرّبه مجانًا',
                    'features' => [
                        'كل الميزات متاحة',
                        '10,000 رصيد شهريًا',
                        '5000 ساعة تحويل النص إلى كلام شهريًا',
                        '4200 ساعة استنساخ الصوت شهريًا',
                        '1200 ساعة تحويل الكلام إلى نص شهريًا',
                        '10 ~ 20 فصل الأغاني',
                        '40 صفحة استخراج نصوص (OCR)',
                        'جميع النماذج والميزات',
                        'ترخيص تجاري',
                    ],
                ],
                'ku' => [
                    'badge' => 'بەخۆڕایی',
                    'title' => 'بەخۆڕایی',
                    'summary' => 'هەموو شتێک بۆ دەستپێکردن لەخۆدەگرێت.',
                    'recommended' => false,
                    'cta' => 'بەخۆڕایی تاقیکەوە',
                    'features' => [
                        'هەموو تایبەتمەندییەکان',
                        '10,000 کرێدیت لە مانگێکدا',
                        '5000 کاتژمێر دەق بۆ دەنگ لە مانگێکدا',
                        '4200 کاتژمێر کلۆنی دەنگ لە مانگێکدا',
                        '1200 کاتژمێر دەنگ بۆ دەق لە مانگێکدا',
                        '10 ~ 20 جیاکردنەوەی گۆرانی',
                        '40 پەڕە دەرهێنانی دەق (OCR)',
                        'هەموو مۆدێل و تایبەتمەندییەکان',
                        'مۆڵەتی بازرگانی',
                    ],
                ],
            ],
            'student' => [
                'en' => [
                    'badge' => 'STUDENT',
                    'title' => 'Student',
                    'summary' => 'More credits and usage for students and growing users.',
                    'recommended' => false,
                    'cta' => 'Get Started',
                    'features' => [
                        'Everything included',
                        '12 included AI actions',
                        '50,000 credits per month',
                        '25000 hours of text-to-speech per month',
                        '20000 hours of voice clone per month',
                        '6000 hours of speech-to-text per month',
                        '50 ~ 100 Songs Separation',
                        '200 Pages Text Extraction OCR',
                        'All models and features',
                        'Commercial license',
                        'Email support',
                    ],
                ],
                'ar' => [
                    'badge' => 'طلاب',
                    'title' => 'طلاب',
                    'summary' => 'رصيد واستخدام أكبر للطلاب والمستخدمين المتقدمين.',
                    'recommended' => false,
                    'cta' => 'ابدأ الآن',
                    'features' => [
                        'كل الميزات متاحة',
                        '12 إجراء ذكاء اصطناعي متضمن',
                        '50,000 رصيد شهريًا',
                        '25000 ساعة تحويل النص إلى كلام شهريًا',
                        '20000 ساعة استنساخ الصوت شهريًا',
                        '6000 ساعة تحويل الكلام إلى نص شهريًا',
                        '50 ~ 100 فصل الأغاني',
                        '200 صفحة استخراج نصوص (OCR)',
                        'جميع النماذج والميزات',
                        'ترخيص تجاري',
                        'دعم عبر البريد الإلكتروني',
                    ],
                ],
                'ku' => [
                    'badge' => 'خوێندکار',
                    'title' => 'خوێندکار',
                    'summary' => 'کرێدیت و بەکارهێنانی زیاتر بۆ خوێندکاران و بەکارهێنەرانی گەشەکردوو.',
                    'recommended' => false,
                    'cta' => 'دەستپێبکە',
                    'features' => [
                        'هەموو تایبەتمەندییەکان',
                        '12 کردارى AI لەخۆدەگرێت',
                        '50,000 کرێدیت لە مانگێکدا',
                        '25000 کاتژمێر دەق بۆ دەنگ',
                        '20000 کاتژمێر کلۆنی دەنگ',
                        '6000 کاتژمێر دەنگ بۆ دەق',
                        '50 ~ 100 جیاکردنەوەی گۆرانی',
                        '200 پەڕە OCR',
                        'هەموو مۆدێل و تایبەتمەندییەکان',
                        'مۆڵەتی بازرگانی',
                        'پشتیوانی ئیمەیڵ',
                    ],
                ],
            ],
            'pro' => [
                'en' => [
                    'badge' => 'PRO',
                    'title' => 'Pro',
                    'summary' => 'Best for professionals and daily production work.',
                    'recommended' => true,
                    'cta' => 'Choose Pro',
                    'features' => [
                        'Everything included',
                        '100,000 credits per month',
                        '50000 hours of text-to-speech per month',
                        '40000 hours of voice clone per month',
                        '12000 hours of speech-to-text per month',
                        '100 ~ 200 Songs Separation',
                        '400 Pages Text Extraction OCR',
                        'All models and features',
                        'Commercial license',
                        'Email support',
                        'Priority support',
                    ],
                ],
                'ar' => [
                    'badge' => 'احترافي',
                    'title' => 'احترافي',
                    'summary' => 'الأفضل للمحترفين والعمل اليومي.',
                    'recommended' => true,
                    'cta' => 'اختر الخطة',
                    'features' => [
                        'كل الميزات متاحة',
                        '100,000 رصيد شهريًا',
                        '50000 ساعة تحويل النص إلى كلام',
                        '40000 ساعة استنساخ الصوت',
                        '12000 ساعة تحويل الكلام إلى نص',
                        '100 ~ 200 فصل الأغاني',
                        '400 صفحة OCR',
                        'جميع النماذج والميزات',
                        'ترخيص تجاري',
                        'دعم عبر البريد الإلكتروني',
                        'دعم أولوية',
                    ],
                ],
                'ku' => [
                    'badge' => 'پڕۆ',
                    'title' => 'پڕۆ',
                    'summary' => 'باشترین هەڵبژاردە بۆ پیشەییەکان و کارە ڕۆژانەکان.',
                    'recommended' => true,
                    'cta' => 'هەڵبژێرە',
                    'features' => [
                        'هەموو تایبەتمەندییەکان',
                        '100,000 کرێدیت',
                        '50000 کاتژمێر دەق بۆ دەنگ',
                        '40000 کاتژمێر کلۆنی دەنگ',
                        '12000 کاتژمێر دەنگ بۆ دەق',
                        '100 ~ 200 جیاکردنەوەی گۆرانی',
                        '400 پەڕە OCR',
                        'هەموو مۆدێل و تایبەتمەندییەکان',
                        'مۆڵەتی بازرگانی',
                        'پشتیوانی ئیمەیڵ',
                        'پشتیوانی پێشکەوتوو',
                    ],
                ],
            ],
            'premium' => [
                'en' => [
                    'badge' => 'PREMIUM',
                    'title' => 'Premium',
                    'summary' => 'High-volume access for teams and advanced users.',
                    'recommended' => false,
                    'cta' => 'Go Premium',
                    'features' => [
                        'Everything included',
                        '250,000 credits per month',
                        '125000 hours of text-to-speech per month',
                        '100000 hours of voice clone per month',
                        '36000 hours of speech-to-text per month',
                        '250 ~ 500 Songs Separation',
                        '1000 Pages Text Extraction OCR',
                        'All models and features',
                        'Commercial license',
                        'Email support',
                        'Priority support',
                    ],
                ],
                'ar' => [
                    'badge' => 'بريميوم',
                    'title' => 'بريميوم',
                    'summary' => 'مناسب للفرق والاستخدام المكثف.',
                    'recommended' => false,
                    'cta' => 'اشترك الآن',
                    'features' => [
                        'كل الميزات متاحة',
                        '250,000 رصيد شهريًا',
                        '125000 ساعة تحويل النص إلى كلام',
                        '100000 ساعة استنساخ الصوت',
                        '36000 ساعة تحويل الكلام إلى نص',
                        '250 ~ 500 فصل الأغاني',
                        '1000 صفحة OCR',
                        'جميع النماذج والميزات',
                        'ترخيص تجاري',
                        'دعم عبر البريد الإلكتروني',
                        'دعم أولوية',
                    ],
                ],
                'ku' => [
                    'badge' => 'پریمیوم',
                    'title' => 'پریمیوم',
                    'summary' => 'بۆ تیمەکان و بەکارهێنانی زۆر.',
                    'recommended' => false,
                    'cta' => 'پریمیوم بەدەستبهێنە',
                    'features' => [
                        'هەموو تایبەتمەندییەکان',
                        '250,000 کرێدیت',
                        '125000 کاتژمێر دەق بۆ دەنگ',
                        '100000 کاتژمێر کلۆنی دەنگ',
                        '36000 کاتژمێر دەنگ بۆ دەق',
                        '250 ~ 500 جیاکردنەوەی گۆرانی',
                        '1000 پەڕە OCR',
                        'هەموو مۆدێل و تایبەتمەندییەکان',
                        'مۆڵەتی بازرگانی',
                        'پشتیوانی ئیمەیڵ',
                        'پشتیوانی پێشکەوتوو',
                    ],
                ],
            ],
        ];
    }

    private function seedStoragePlans(): void
    {
        $rows = [
            ['code' => 'free-512',      'name' => 'Free (512MB)',   'quota_mb' => 512,   'is_active' => true, 'sort_order' => 1],
            ['code' => 'student-3072',  'name' => 'Student (3GB)',  'quota_mb' => 3072,  'is_active' => true, 'sort_order' => 2],
            ['code' => 'pro-5120',      'name' => 'Pro (5GB)',      'quota_mb' => 5120,  'is_active' => true, 'sort_order' => 3],
            ['code' => 'premium-10240', 'name' => 'Premium (10GB)', 'quota_mb' => 10240, 'is_active' => true, 'sort_order' => 4],
        ];

        foreach ($rows as $row) {
            StoragePlan::updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }
    }

    private function seedCreditProducts(): void
    {
        $currency = app(BillingCurrencyService::class);

        $rows = [
            ['code' => 'addon_10000',  'name' => 'Add-on 10,000 Credits',  'credits_amount' => 10000,  'price_usd' => 5.00,  'price_iqd' => $currency->legacyUsdAmountToIqd(5.00),  'is_active' => true, 'sort_order' => 1],
            ['code' => 'addon_50000',  'name' => 'Add-on 50,000 Credits',  'credits_amount' => 50000,  'price_usd' => 20.00, 'price_iqd' => $currency->legacyUsdAmountToIqd(20.00), 'is_active' => true, 'sort_order' => 2],
            ['code' => 'addon_100000', 'name' => 'Add-on 100,000 Credits', 'credits_amount' => 100000, 'price_usd' => 35.00, 'price_iqd' => $currency->legacyUsdAmountToIqd(35.00), 'is_active' => true, 'sort_order' => 3],
        ];

        foreach ($rows as $row) {
            CreditProduct::updateOrCreate(
                ['code' => $row['code']],
                $row
            );
        }
    }

    private function seedToolsAndActions(): array
    {
        $toolRows = [
            ['code' => 'tts',            'name' => 'Text To Speech',        'sort_order' => 1],
            ['code' => 'ftts',           'name' => 'F5 Text To Speech',     'sort_order' => 2],
            ['code' => 'clone_tts',      'name' => 'Clone Text To Speech',  'sort_order' => 3],
            ['code' => 'asr',            'name' => 'Automatic Speech Recognition', 'sort_order' => 4],
            ['code' => 'qasr',           'name' => 'Qwen Automatic Speech Recognition', 'sort_order' => 5],
            ['code' => 'tran',           'name' => 'MET Translation',       'sort_order' => 6],
            ['code' => 'stem',           'name' => 'Stem Separation',       'sort_order' => 7],
            ['code' => 'ocr',            'name' => 'OCR',                   'sort_order' => 8],
            ['code' => 'youtube_audio',  'name' => 'YouTube Audio Downloader', 'sort_order' => 9],
            ['code' => 'youtube_video',  'name' => 'YouTube Video Downloader', 'sort_order' => 10],
        ];

        $tools = [];
        foreach ($toolRows as $row) {
            $tools[$row['code']] = Tool::updateOrCreate(
                ['code' => $row['code']],
                [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'is_active' => true,
                    'sort_order' => $row['sort_order'],
                    'meta' => null,
                ]
            );
        }

        $actionRows = [
            ['tool_code' => 'tts',           'action_code' => 'standard', 'name' => 'TTS Standard',         'metric' => 'character'],
            ['tool_code' => 'ftts',          'action_code' => 'standard', 'name' => 'F5 TTS Standard',      'metric' => 'character'],

            ['tool_code' => 'clone_tts',     'action_code' => 'standard', 'name' => 'Clone TTS Standard',   'metric' => 'character'],

            ['tool_code' => 'asr',           'action_code' => 'standard', 'name' => 'ASR Standard',         'metric' => 'minute'],
            ['tool_code' => 'qasr',          'action_code' => 'standard', 'name' => 'QASR Standard',        'metric' => 'minute'],
            ['tool_code' => 'tran',          'action_code' => 'standard', 'name' => 'Translation Standard', 'metric' => 'character'],

            ['tool_code' => 'stem',          'action_code' => 'sep2',     'name' => 'Stem Separation 2',    'metric' => 'stem_output'],
            ['tool_code' => 'stem',          'action_code' => 'sep4',     'name' => 'Stem Separation 4',    'metric' => 'stem_output'],

            ['tool_code' => 'ocr',           'action_code' => 'standard', 'name' => 'OCR Standard',         'metric' => 'page'],

            ['tool_code' => 'youtube_audio', 'action_code' => 'mp3',      'name' => 'YouTube Audio MP3',    'metric' => 'minute'],
            ['tool_code' => 'youtube_audio', 'action_code' => 'wav',      'name' => 'YouTube Audio WAV',    'metric' => 'minute'],

            ['tool_code' => 'youtube_video', 'action_code' => 'p480',     'name' => 'YouTube Video 480p',   'metric' => 'minute'],
            ['tool_code' => 'youtube_video', 'action_code' => 'p720',     'name' => 'YouTube Video 720p',   'metric' => 'minute'],
            ['tool_code' => 'youtube_video', 'action_code' => 'p1080',    'name' => 'YouTube Video 1080p',  'metric' => 'minute'],
            ['tool_code' => 'youtube_video', 'action_code' => 'p4k',      'name' => 'YouTube Video 4K',     'metric' => 'minute'],
        ];

        $actions = [];

        foreach ($actionRows as $row) {
            $fullCode = $row['tool_code'] . '.' . $row['action_code'];

            $actions[$fullCode] = ToolAction::updateOrCreate(
                ['full_code' => $fullCode],
                [
                    'full_code' => $fullCode,
                    'tool_code' => $row['tool_code'],
                    'action_code' => $row['action_code'],
                    'name' => $row['name'],
                    'default_metric_code' => $row['metric'],
                    'is_active' => true,
                    'meta' => null,
                ]
            );
        }

        return [$tools, $actions];
    }

    private function seedPlanEntitlements(array $plans, array $actions): void
    {
        foreach ($plans as $planCode => $plan) {
            foreach ($actions as $fullCode => $action) {
                $allowed = true;

                PlanEntitlement::updateOrCreate(
                    [
                        'service_plan_id' => $plan->id,
                        'tool_action_id' => $action->id,
                    ],
                    [
                        'allowed' => $allowed,
                        'limits' => null,
                    ]
                );
            }
        }
    }

    private function seedPricingRules(array $actions): void
    {
        $rules = [
            'tts.standard' => [
                'metric_code' => 'character',
                'unit_size' => 1,
                'credits_per_unit' => 1.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 1,
            ],
            'ftts.standard' => [
                'metric_code' => 'character',
                'unit_size' => 1,
                'credits_per_unit' => 1.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 1,
            ],
            'clone_tts.standard' => [
                'metric_code' => 'character',
                'unit_size' => 1,
                'credits_per_unit' => 1.2,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 1,
            ],
            'asr.standard' => [
                'metric_code' => 'minute',
                'unit_size' => 1,
                'credits_per_unit' => 1000.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 1000,
            ],
            'qasr.standard' => [
                'metric_code' => 'minute',
                'unit_size' => 1,
                'credits_per_unit' => 1000.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 1000,
            ],
            'tran.standard' => [
                'metric_code' => 'character',
                'unit_size' => 1,
                'credits_per_unit' => 1.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 1,
            ],
            'stem.sep2' => [
                'metric_code' => 'stem_output',
                'unit_size' => 1,
                'credits_per_unit' => 500.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 500,
                'conditions' => ['separation_mode' => 2],
            ],
            'stem.sep4' => [
                'metric_code' => 'stem_output',
                'unit_size' => 1,
                'credits_per_unit' => 500.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 500,
                'conditions' => ['separation_mode' => 4],
            ],
            'ocr.standard' => [
                'metric_code' => 'page',
                'unit_size' => 1,
                'credits_per_unit' => 250.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 250,
            ],
            'youtube_audio.mp3' => [
                'metric_code' => 'minute',
                'unit_size' => 1,
                'credits_per_unit' => 100.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 100,
            ],
            'youtube_audio.wav' => [
                'metric_code' => 'minute',
                'unit_size' => 1,
                'credits_per_unit' => 150.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 150,
            ],
            'youtube_video.p480' => [
                'metric_code' => 'minute',
                'unit_size' => 1,
                'credits_per_unit' => 100.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 100,
            ],
            'youtube_video.p720' => [
                'metric_code' => 'minute',
                'unit_size' => 1,
                'credits_per_unit' => 200.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 200,
            ],
            'youtube_video.p1080' => [
                'metric_code' => 'minute',
                'unit_size' => 1,
                'credits_per_unit' => 250.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 250,
            ],
            'youtube_video.p4k' => [
                'metric_code' => 'minute',
                'unit_size' => 1,
                'credits_per_unit' => 300.0,
                'rounding_mode' => 'ceil',
                'rounding_step' => 1,
                'minimum_credits' => 300,
            ],
        ];

        foreach ($rules as $fullCode => $rule) {
            $action = $actions[$fullCode];

            PricingRule::updateOrCreate(
                [
                    'tool_action_id' => $action->id,
                    'priority' => 100,
                ],
                [
                    'metric_code' => $rule['metric_code'],
                    'unit_size' => $rule['unit_size'],
                    'credits_per_unit' => $rule['credits_per_unit'],
                    'rounding_mode' => $rule['rounding_mode'],
                    'rounding_step' => $rule['rounding_step'],
                    'minimum_credits' => $rule['minimum_credits'],
                    'conditions' => $rule['conditions'] ?? null,
                    'config' => null,
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedVoicesAndAccess(array $plans): void
    {
        $voiceList = [
            ['code' => 'female_1',      'name' => '👩 Female 1', 'is_public' => true, 'sort_order' => 1, 'meta' => ['engine' => 'xtts', 'gender' => 'female']],
            ['code' => 'female_2',      'name' => '👩 Female 2', 'is_public' => true, 'sort_order' => 2, 'meta' => ['engine' => 'xtts', 'gender' => 'female']],
            ['code' => 'female_3',      'name' => '👩 Female 3', 'is_public' => true, 'sort_order' => 3, 'meta' => ['engine' => 'xtts', 'gender' => 'female']],
            ['code' => 'liza',          'name' => '👩 Female 4', 'is_public' => true, 'sort_order' => 4, 'meta' => ['engine' => 'xtts', 'gender' => 'female']],
            ['code' => 'taha_fathi',    'name' => '👨 Male 1',   'is_public' => true, 'sort_order' => 5, 'meta' => ['engine' => 'xtts', 'gender' => 'male']],
            ['code' => 'shwan',         'name' => '👨 Male 2',   'is_public' => true, 'sort_order' => 6, 'meta' => ['engine' => 'xtts', 'gender' => 'male']],
            ['code' => 'rudaw',         'name' => '👨 Male 3',   'is_public' => true, 'sort_order' => 7, 'meta' => ['engine' => 'xtts', 'gender' => 'male']],
            ['code' => 'serok_barzani', 'name' => '👨 Male 4',   'is_public' => true, 'sort_order' => 8, 'meta' => ['engine' => 'xtts', 'gender' => 'male']],
        ];

        $voices = [];

        foreach ($voiceList as $row) {
            $voices[$row['code']] = Voice::updateOrCreate(
                ['code' => $row['code']],
                [
                    'name' => $row['name'],
                    'is_public' => $row['is_public'],
                    'is_active' => true,
                    'sort_order' => $row['sort_order'],
                    'meta' => $row['meta'] ?? null,
                ]
            );
        }

        foreach ($plans as $planCode => $plan) {
            foreach ($voices as $voice) {
                PlanVoiceAccess::updateOrCreate(
                    [
                        'service_plan_id' => $plan->id,
                        'voice_id' => $voice->id,
                    ],
                    [
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
