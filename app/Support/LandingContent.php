<?php

namespace App\Support;

use Illuminate\Support\Arr;

class LandingContent
{
    protected const CONTENT = [
        'site' => [
            'name' => 'METKURD AI',
            'tagline' => 'The Future of Kurdish AI',
            'author' => 'Michel Shabo, michel@metiraq.com',
            'meta_description' => 'METKURD AI is a Kurdish-first AI platform for speech-to-text, text-to-speech, ASR, OCR, Kurdish translation, voice technology, and music stem separation for research, media, and production workflows.',
            'meta_keywords' => 'METKURD, METKURD AI, The Future of Kurdish AI, Kurdish AI, Sorani AI, speech to text, STT, text to speech, TTS, ASR, OCR, Kurdish OCR, Kurdish translation, transcription, voice AI, music stem separation, stem separator, Kurdish media AI',
            'subject' => 'Kurdish-first AI platform for speech, text, OCR, translation, and media tools',
            'type' => 'website',
            'robots' => 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
            'theme_color' => '#07111f',
            'default_image_alt' => 'METKURD AI | The Future of Kurdish AI',
        ],
        'locales' => [
            'en' => 'English',
            'ar' => 'Arabic',
            'ku' => 'Kurdish',
        ],
        'nav' => [
            'primary_label' => 'Primary navigation',
            'locale_label' => 'Switch language',
            'skip_to_content' => 'Skip to content',
            'home_label' => 'METKURD AI home',
            'toggle_label' => 'Toggle navigation',
            'toggle_theme' => 'Toggle color theme',
            'home' => 'Home',
            'tools' => 'Tools',
            'pricing' => 'Pricing',
            'contact' => 'Contact',
            'privacy' => 'Privacy',
            'terms' => 'Terms',
            'dashboard' => 'Dashboard',
            'profile' => 'Profile',
            'admin_panel' => 'Admin Panel',
            'get_started' => 'Get Started',
            'sign_in' => 'Sign In',
        ],
        'footer' => [
            'copy' => 'Built for modern Kurdish AI teams who need polished product flows, trustworthy outputs, and fast iteration across speech, documents, and media.',
            'rights' => '© :year METKURD. All rights reserved.',
            'made_for' => 'Designed for Kurdish-first AI products and research workflows.',
            'newsletter_badge' => 'Join the METKURD newsletter',
            'newsletter_title' => 'Stay updated on new Kurdish AI releases, demos, and research tools.',
            'newsletter_copy' => 'Get product updates, launch news, and early access opportunities.',
            'newsletter_placeholder' => 'Enter your email',
            'newsletter_form_label' => 'Newsletter email address',
            'newsletter_success' => 'Thanks. Your email was captured locally for now.',
            'subscribe' => 'Subscribe',
            'platform_heading' => 'Platform',
            'resources_heading' => 'Resources',
            'legal_heading' => 'Legal',
            'status_heading' => 'Status',
            'status_uptime' => '99.95% uptime',
            'status_secure' => 'Cloud secured',
            'status_accessible' => 'WCAG-friendly UI',
        ],
        'google_review' => [
            'g_badge' => 'Your Feedback Fuels Our Innovation',
            'g_title' => 'Love using METKURD? Share your experience on Google and help us build better AI for the community.',
            'g_copy' => 'Rate Us on Google ❤',
        ],
        'common' => [
            'learn_more' => 'Learn more',
            'open_page' => 'Open page',
            'view_pricing' => 'View pricing',
            'get_started' => 'Create account',
            'open_dashboard' => 'Open dashboard',
            'monthly' => 'Monthly',
            'yearly' => 'Yearly',
            'toggle_yearly' => 'Toggle yearly billing',
            'per_month' => '/month',
            'per_year' => '/year',
            'monthly_credits_value' => ':value monthly credits',
            'included_actions_value' => ':value included AI actions',
            'credits_short' => ':value credits',
            'actions_short' => ':value actions',
            'send_message' => 'Send message',
            'message_sent' => 'Your message draft was captured locally. Connect mail delivery when you are ready.',
            'name' => 'Name',
            'email' => 'Email address',
            'subject' => 'Subject',
            'message' => 'Message',
            'back_to_tools' => 'Back to tools',
            'explore_tools' => 'Explore tools',
            'open_app' => 'Open the app',
        ],
        'home' => [
            'meta' => [
                'title' => 'Kurdish AI Workspace',
                'description' => 'A high-performance Kurdish AI platform for text-to-speech, voice cloning, OCR, speech recognition, and stem separation.',
                'keywords' => 'Kurdish AI workspace, Sorani AI tools, Kurdish TTS, Kurdish OCR, Kurdish ASR, METKURD',
            ],
            'hero' => [
                'badge' => 'Premium Kurdish AI platform for CKB - Sorani',
                'title_html' => 'The <span class="landing-gradient-text">Future Of Kurdish AI</span> built for Kurdish speech, text, and research.',
                'lead' => 'Generate natural Kurdish voices, transcribe audio, extract text from scans, separate stems, and run serious language workflows inside one modern interface.',
                'primary_cta' => 'Try it free',
                'secondary_cta' => 'See pricing',
                'pills' => [
                    'CKB-native models',
                    'Fast cloud processing',
                    'Customer-ready UX',
                ],
            ],
            'preview' => [
                'live' => 'Live product preview',
                'latency' => 'TTS latency',
                'latency_value' => '1.8s',
                'confidence' => 'ASR confidence',
                'confidence_value' => '98.2%',
                'demo_title' => 'Text to voice demo',
                'demo_badge' => 'CKB',
                'ocr_title' => 'OCR extraction',
                'ocr_badge' => 'PDF + Image',
                'table_headers' => [
                    'page' => 'Page',
                    'status' => 'Status',
                    'language' => 'Language',
                ],
                'table' => [
                    [
                        'page' => '01',
                        'status' => 'Detected',
                        'language' => 'CKB',
                    ],
                    [
                        'page' => '02',
                        'status' => 'Parsed',
                        'language' => 'Arabic script',
                    ],
                ],
            ],
            'tools' => [
                'badge' => 'Core platform',
                'title' => 'High-impact Kurdish AI tools in one polished product.',
                'copy' => 'Every workflow is designed to feel premium while staying practical for education, media, archives, and local-language innovation.',
            ],
            'demo' => [
                'badge' => 'Product demos',
                'title' => 'Show the value in seconds, not paragraphs.',
                'copy' => 'The landing layer mirrors the product experience, so previews feel real before the user even signs in.',
                'sample_text' => 'Kurdish Sorani demo text appears here...',
                'shell' => [
                    'tts_title' => 'TTS Demo',
                    'tts_badge' => 'Interactive mock',
                    'asr_title' => 'ASR Output',
                    'asr_badge' => 'Realtime',
                    'ocr_title' => 'OCR Preview',
                    'ocr_badge' => 'Smart detect',
                ],
                'steps' => [
                    [
                        'title' => 'Text to speech',
                        'copy' => 'Paste Kurdish text, synthesize speech, and preview output instantly.',
                    ],
                    [
                        'title' => 'Speech to text',
                        'copy' => 'Upload audio and see clean transcription with confidence feedback.',
                    ],
                    [
                        'title' => 'OCR preview',
                        'copy' => 'Drop a page image and surface extracted Kurdish text immediately.',
                    ],
                ],
                'cards' => [
                    'tts' => 'Realtime speech generation queue',
                    'asr' => 'Structured transcription output',
                    'ocr' => 'Detected OCR content regions',
                ],
            ],
            'reasons_heading' => [
                'badge' => 'Why teams choose it',
                'title' => 'Built with the credibility modern AI buyers expect.',
            ],
            'reasons' => [
                [
                    'icon' => 'bi bi-translate',
                    'title' => 'Built for Kurdish',
                    'copy' => 'Focused on real Kurdish workflows instead of generic multilingual compromises.',
                ],
                [
                    'icon' => 'bi bi-cpu',
                    'title' => 'Fast AI processing',
                    'copy' => 'Optimized for quick demos, classroom use, and production iteration.',
                ],
                [
                    'icon' => 'bi bi-bullseye',
                    'title' => 'High-fidelity outputs',
                    'copy' => 'Tooling tuned for accuracy, clarity, and usability in day-to-day workflows.',
                ],
                [
                    'icon' => 'bi bi-cloud-check',
                    'title' => 'Secure cloud foundation',
                    'copy' => 'Prepared for access rules, storage controls, and protected processing pipelines.',
                ],
            ],
            'workflow' => [
                'badge' => 'Workflow',
                'title' => 'Simple enough for students. Powerful enough for research teams.',
                'steps' => [
                    [
                        'title' => 'Upload or input content',
                        'copy' => 'Paste text, upload audio, or drop in an image or PDF.',
                    ],
                    [
                        'title' => 'Run the right AI engine',
                        'copy' => 'The platform routes each job to the right Kurdish model and processing lane.',
                    ],
                    [
                        'title' => 'Review and export',
                        'copy' => 'Inspect results, play outputs, and download structured deliverables.',
                    ],
                ],
                'surface' => [
                    [
                        'label' => 'Input queue',
                        'value' => '3 active',
                    ],
                    [
                        'label' => 'Model selection',
                        'value' => 'CKB-v2',
                    ],
                    [
                        'label' => 'Processing',
                        'value' => 'Running',
                    ],
                    [
                        'label' => 'Export',
                        'value' => 'TXT / WAV / JSON',
                    ],
                ],
            ],
            'pricing_heading' => [
                'badge' => 'Pricing preview',
                'title' => 'Start free, then scale when your workflow grows.',
            ],
            'testimonial' => [
                'badge' => 'Testimonials',
                'quote' => 'It feels like a serious AI startup product, but for Kurdish.',
                'copy' => 'The landing experience is intentionally designed to communicate trust, speed, and local-language specialization from the first screen.',
                'author' => 'Research and Language Innovation Team',
            ],
            'faq_heading' => [
                'badge' => 'FAQ',
                'title' => 'Questions teams ask before they commit.',
            ],
            'faqs' => [
                [
                    'title' => 'Is the platform built specifically for Kurdish Sorani?',
                    'copy' => 'Yes. The workflows, tone, and feature set are focused on Kurdish-first usage and real operator needs.',
                ],
                [
                    'title' => 'Can these landing demos connect to real APIs later?',
                    'copy' => 'Yes. The structure is ready to connect to backend services and live product data.',
                ],
                [
                    'title' => 'Does it stay responsive on mobile?',
                    'copy' => 'Yes. The design is tuned for desktop, tablet, and mobile transitions.',
                ],
                [
                    'title' => 'Is accessibility considered?',
                    'copy' => 'The pages use semantic structure, contrast-aware styling, and large touch-friendly controls.',
                ],
            ],
        ],
        'tools_page' => [
            'meta' => [
                'title' => 'Tool Suite',
                'description' => 'Explore Kurdish AI tools for speech, OCR, transcription, and audio workflows.',
                'keywords' => 'Kurdish AI tools, Sorani transcription, OCR, TTS, stem separation',
            ],
            'badge' => 'Tool suite',
            'title' => 'A complete Kurdish AI product stack.',
            'lead' => 'Explore every workflow with premium SaaS styling, clear use cases, and conversion-ready product pages.',
            'empty_title' => 'No public tools are available right now',
            'empty_copy' => 'Activate a tool in the dashboard and it will appear here automatically.',
        ],
        'pricing_page' => [
            'meta' => [
                'title' => 'Pricing',
                'description' => 'Compare active METKURD plans with monthly or yearly billing views.',
                'keywords' => 'Kurdish AI pricing, SaaS plans, AI subscriptions, METKURD plans',
            ],
            'badge' => 'Pricing',
            'title' => 'Simple plans for every stage.',
            'lead' => 'Switch billing instantly and compare the live plan data coming from your service plan table.',
            'featured_label' => 'Best value',
            'monthly_note' => 'Billed monthly',
            'yearly_note' => 'Billed yearly',
            'highlights' => [
                [
                    'icon' => 'bi bi-lightning-charge',
                    'title' => 'Fast onboarding',
                    'copy' => 'Move from preview to working account without leaving the SPA flow.',
                ],
                [
                    'icon' => 'bi bi-bar-chart-line',
                    'title' => 'Real plan data',
                    'copy' => 'Cards reflect the active service plan records already defined in the database.',
                ],
                [
                    'icon' => 'bi bi-arrow-repeat',
                    'title' => 'Smooth billing toggle',
                    'copy' => 'Monthly and yearly states switch client-side without waiting on a network round-trip.',
                ],
            ],
        ],
        'contact_page' => [
            'meta' => [
                'title' => 'Contact',
                'description' => 'Contact the METKURD team for support, partnerships, or product questions.',
                'keywords' => 'Contact METKURD, Kurdish AI support, AI sales, product inquiry',
            ],
            'badge' => 'Contact',
            'title' => 'Talk to the team behind METKURD AI.',
            'lead' => 'A clean contact experience that fits the same polished flow as the public site and the app itself.',
            'form_title' => 'Send a message',
            'name_placeholder' => 'Your name',
            'email_placeholder' => 'Email address',
            'subject_placeholder' => 'Subject',
            'message_placeholder' => 'How can we help?',
            'support_title' => 'Email support',
            'support_lines' => [
                'support@metkurd.ai',
                'sales@metkurd.ai',
            ],
            'company_title' => 'Company information',
            'company_lines' => [
                'METKURD AI Platform',
                'Erbil, Iraq',
            ],
            'social_title' => 'Social links',
            'response_title' => 'Response window',
            'response_lines' => [
                'General replies within 1 business day',
                'Priority customers receive faster routing',
            ],
        ],
        'privacy_page' => [
            'meta' => [
                'title' => 'Privacy Policy',
                'description' => 'Understand how METKURD approaches privacy, storage, and user content handling.',
                'keywords' => 'METKURD privacy policy, Kurdish AI privacy, user data, AI storage',
            ],
            'badge' => 'Privacy Policy',
            'title' => 'A privacy-first shell for Kurdish AI workflows.',
            'lead' => 'These pages are structured for clear customer communication today and ready for more detailed legal copy later.',
            'cards' => [
                [
                    'title' => 'Data storage',
                    'copy' => 'User files and generated outputs may be stored temporarily or persistently according to account features and retention rules.',
                ],
                [
                    'title' => 'Voice data usage',
                    'copy' => 'Voice samples and speaker assets should only be processed in line with your consent and product policies.',
                ],
                [
                    'title' => 'Uploaded content',
                    'copy' => 'Documents, images, audio, and text are processed solely to deliver the requested workflow result.',
                ],
                [
                    'title' => 'User privacy controls',
                    'copy' => 'The product shell is prepared for notices, retention settings, and deletion workflows.',
                ],
                [
                    'title' => 'Security practices',
                    'copy' => 'Encrypted transport, access controls, and audit-friendly monitoring should back production deployments.',
                ],
                [
                    'title' => 'Third-party services',
                    'copy' => 'Cloud providers, analytics, and communications vendors may support the platform depending on deployment choices.',
                ],
            ],
        ],
        'terms_page' => [
            'meta' => [
                'title' => 'Terms and Conditions',
                'description' => 'Review the core platform terms for using METKURD AI services.',
                'keywords' => 'METKURD terms, AI usage policy, SaaS terms, Kurdish AI platform rules',
            ],
            'badge' => 'Terms and Conditions',
            'title' => 'Clear platform terms for a premium AI product.',
            'lead' => 'The legal pages stay in the same design system so the public site feels cohesive from first visit to account creation.',
            'cards' => [
                [
                    'title' => 'User agreement',
                    'copy' => 'Users agree to access the service lawfully and remain responsible for uploaded content and account security.',
                ],
                [
                    'title' => 'Data usage',
                    'copy' => 'Uploaded text, audio, and files may be processed to provide AI outputs and core service functionality.',
                ],
                [
                    'title' => 'AI content policy',
                    'copy' => 'The platform must not be used to generate harmful, deceptive, or unauthorized content.',
                ],
                [
                    'title' => 'Service availability',
                    'copy' => 'Availability targets may vary during maintenance, upgrades, or infrastructure incidents.',
                ],
                [
                    'title' => 'Payment terms',
                    'copy' => 'Paid subscriptions renew under the selected plan and billing cycle.',
                ],
                [
                    'title' => 'Refund policy',
                    'copy' => 'Refund handling can be tailored later by plan, billing window, and regional requirements.',
                ],
            ],
        ],
        'tool_catalog' => [
            'tts' => [
                'slug' => 'tts',
                'icon' => 'bi bi-soundwave',
                'badge' => 'Speech',
                'title' => 'TTS-CKB',
                'summary' => 'Turn Kurdish Sorani text into fluid, natural-sounding speech for product, media, and accessibility workflows.',
                'capabilities' => [
                    'Natural voices',
                    'Narration-ready',
                    'Accessible output',
                ],
            ],
            'clone_tts' => [
                'slug' => 'ctts',
                'icon' => 'bi bi-mic',
                'badge' => 'Voice clone',
                'title' => 'CTTS-CKB',
                'summary' => 'Clone voices and build custom speaker styles for branded content, narration, and personalized experiences.',
                'capabilities' => [
                    'Custom voices',
                    'Brand tone',
                    'Studio-style control',
                ],
            ],
            'asr' => [
                'slug' => 'asr',
                'icon' => 'bi bi-file-earmark-text',
                'badge' => 'Transcription',
                'title' => 'ASR-CKB',
                'summary' => 'Convert Kurdish speech into text for subtitles, meetings, interviews, and searchable media archives.',
                'capabilities' => [
                    'Fast transcripts',
                    'Searchable output',
                    'Export-ready text',
                ],
            ],
            'ocr' => [
                'slug' => 'ocr',
                'icon' => 'bi bi-images',
                'badge' => 'Documents',
                'title' => 'OCR-CKB',
                'summary' => 'Extract Kurdish text from scans, images, and PDFs to accelerate archiving, education, and document workflows.',
                'capabilities' => [
                    'Scans',
                    'PDF archives',
                    'Structured extraction',
                ],
            ],
            'stem' => [
                'slug' => 'stem',
                'icon' => 'bi bi-disc',
                'badge' => 'Audio',
                'title' => 'STEM Tools',
                'summary' => 'Separate mixes into usable stems for analysis, education, remix preparation, and media workflows.',
                'capabilities' => [
                    'Stem split',
                    'DAW-style output',
                    'Audio review',
                ],
            ],
        ],
        'tool_pages' => [
            'tts' => [
                'meta_title' => 'TTS-CKB',
                'meta_description' => 'Generate natural Kurdish voices on demand with METKURD text-to-speech workflows.',
                'badge' => 'TTS-CKB',
                'title' => 'Generate natural Kurdish voices on demand.',
                'lead' => 'Convert Kurdish Sorani text into high-quality speech for narration, products, accessibility, and media production.',
                'about_title' => 'What it does',
                'about_copy' => 'TTS-CKB helps teams transform Kurdish scripts into clear, natural playback for apps, media, education, and assistive experiences.',
                'use_cases_title' => 'Use cases',
                'use_cases' => [
                    'Audiobooks and narration',
                    'Educational audio content',
                    'Accessibility and screen reading',
                    'Product voice experiences',
                ],
                'features' => [
                    [
                        'icon' => 'bi bi-lightning-charge',
                        'title' => 'Fast workflow',
                        'copy' => 'Designed for rapid feedback and polished product demos.',
                    ],
                    [
                        'icon' => 'bi bi-shield-lock',
                        'title' => 'Secure by design',
                        'copy' => 'Prepared for protected file handling and private audio delivery.',
                    ],
                    [
                        'icon' => 'bi bi-stars',
                        'title' => 'Product-ready',
                        'copy' => 'Launch-grade presentation with room for deeper backend integrations.',
                    ],
                ],
            ],
            'clone_tts' => [
                'meta_title' => 'CTTS-CKB',
                'meta_description' => 'Clone Kurdish voice profiles with a polished studio-style public product page.',
                'badge' => 'CTTS-CKB',
                'title' => 'Clone voices with studio-style control.',
                'lead' => 'Build custom speaker profiles and cloned voices for branded audio, consistent narration, and personalized speech generation.',
                'about_title' => 'What it does',
                'about_copy' => 'CTTS-CKB gives teams a premium way to present custom speaker generation for branded voices and creative workflows.',
                'use_cases_title' => 'Use cases',
                'use_cases' => [
                    'Custom branded voices',
                    'Narration consistency',
                    'Speaker style replication',
                    'Creative media workflows',
                ],
                'features' => [
                    [
                        'icon' => 'bi bi-lightning-charge',
                        'title' => 'Fast workflow',
                        'copy' => 'Studio-like controls without heavy product friction.',
                    ],
                    [
                        'icon' => 'bi bi-shield-lock',
                        'title' => 'Consent-aware design',
                        'copy' => 'Clear space for privacy, approval, and access policy messaging.',
                    ],
                    [
                        'icon' => 'bi bi-stars',
                        'title' => 'Launch-ready UX',
                        'copy' => 'Clean enough for marketing and clear enough for real users.',
                    ],
                ],
            ],
            'asr' => [
                'meta_title' => 'ASR-CKB',
                'meta_description' => 'Transcribe Kurdish speech into clean searchable text with METKURD ASR workflows.',
                'badge' => 'ASR-CKB',
                'title' => 'Turn Kurdish speech into searchable text.',
                'lead' => 'Transcribe spoken Kurdish Sorani audio into text for subtitling, interviews, meetings, archives, and analysis workflows.',
                'about_title' => 'What it does',
                'about_copy' => 'ASR-CKB turns spoken Kurdish content into text teams can read, search, export, and build on.',
                'use_cases_title' => 'Use cases',
                'use_cases' => [
                    'Subtitles and captions',
                    'Interview transcription',
                    'Meeting notes',
                    'Searchable media archives',
                ],
                'features' => [
                    [
                        'icon' => 'bi bi-lightning-charge',
                        'title' => 'Fast workflow',
                        'copy' => 'Designed to surface transcripts quickly and clearly.',
                    ],
                    [
                        'icon' => 'bi bi-shield-lock',
                        'title' => 'Secure by design',
                        'copy' => 'Prepared for controlled audio handling and export rules.',
                    ],
                    [
                        'icon' => 'bi bi-stars',
                        'title' => 'Analysis ready',
                        'copy' => 'Results can feed archives, reports, subtitles, and search.',
                    ],
                ],
            ],
            'ocr' => [
                'meta_title' => 'OCR-CKB',
                'meta_description' => 'Extract Kurdish text from documents and scans with METKURD OCR workflows.',
                'badge' => 'OCR-CKB',
                'title' => 'Extract Kurdish text from documents and scans.',
                'lead' => 'Recognize Kurdish text in images and PDFs to digitize books, archives, forms, educational materials, and scanned notes.',
                'about_title' => 'What it does',
                'about_copy' => 'OCR-CKB helps teams turn scanned Kurdish content into usable, searchable, and exportable text.',
                'use_cases_title' => 'Use cases',
                'use_cases' => [
                    'Digitizing books',
                    'Image text extraction',
                    'PDF archive processing',
                    'Educational document workflows',
                ],
                'features' => [
                    [
                        'icon' => 'bi bi-lightning-charge',
                        'title' => 'Fast workflow',
                        'copy' => 'Upload, detect, and review text without leaving the page flow.',
                    ],
                    [
                        'icon' => 'bi bi-shield-lock',
                        'title' => 'Secure by design',
                        'copy' => 'Prepared for controlled storage and document retention rules.',
                    ],
                    [
                        'icon' => 'bi bi-stars',
                        'title' => 'Archive friendly',
                        'copy' => 'Fits digital library, education, and document modernization work.',
                    ],
                ],
            ],
            'stem' => [
                'meta_title' => 'STEM Tools',
                'meta_description' => 'Separate mixes into stems for review, education, and media workflows inside METKURD.',
                'badge' => 'STEM Tools',
                'title' => 'Audio utilities for Kurdish research and media workflows.',
                'lead' => 'Support education, analysis, remix preparation, and audio-focused workflows with clean separated stem outputs.',
                'about_title' => 'What it does',
                'about_copy' => 'STEM tools separate uploaded audio into usable layers so teams can inspect, review, and repurpose their source material.',
                'use_cases_title' => 'Use cases',
                'use_cases' => [
                    'Research assistance',
                    'Educational review',
                    'Mix inspection',
                    'Academic productivity',
                ],
                'features' => [
                    [
                        'icon' => 'bi bi-lightning-charge',
                        'title' => 'Fast workflow',
                        'copy' => 'Preview and compare stem outputs in a polished interface.',
                    ],
                    [
                        'icon' => 'bi bi-shield-lock',
                        'title' => 'Secure by design',
                        'copy' => 'Prepared for protected audio handling and controlled downloads.',
                    ],
                    [
                        'icon' => 'bi bi-stars',
                        'title' => 'Audio-first UX',
                        'copy' => 'A clear marketing layer for a serious audio workflow.',
                    ],
                ],
            ],
        ],
        'tool_detail' => [
            'model_label' => 'Model',
            'status_label' => 'Status',
            'status_ready' => 'Ready',
        ],
        'plans' => [
            'free' => [
                'title' => 'Free',
                'summary' => 'A lightweight entry point for evaluation and small experiments.',
                'features' => [
                    'Core product experience',
                    'Starter AI usage',
                    'Community support',
                ],
                'cta' => 'Try it free',
            ],
            'student' => [
                'title' => 'Student',
                'summary' => 'A practical plan for coursework, labs, and educational teams.',
                'features' => [
                    'Higher usage envelope',
                    'Speech and OCR workflows',
                    'Email support',
                ],
                'cta' => 'Create account',
            ],
            'pro' => [
                'title' => 'Pro',
                'summary' => 'Balanced capacity for production teams and serious creators.',
                'features' => [
                    'Full workflow coverage',
                    'Priority-ready UX',
                    'Best fit for daily usage',
                ],
                'cta' => 'Get started',
                'featured' => true,
            ],
            'premium' => [
                'title' => 'Premium',
                'summary' => 'Expanded capacity for organizations running heavier workloads.',
                'features' => [
                    'Large monthly credit pool',
                    'Priority processing posture',
                    'Dedicated support path',
                ],
                'cta' => 'Open account',
            ],
        ],
    ];

    public static function rawText(string $path, ?string $fallback = null): string
    {
        $value = Arr::get(self::CONTENT, $path);

        if (! is_string($value)) {
            return $fallback ?? '';
        }

        return $value;
    }

    public static function rawSection(string $path): array
    {
        $value = Arr::get(self::CONTENT, $path, []);

        return is_array($value) ? $value : [];
    }

    public static function translationCatalog(?array $allowedRoots = null): array
    {
        $flat = [];
        $roots = $allowedRoots ?: [
            'site',
            'nav',
            'footer',
            'common',
            'home',
            'tools_page',
            'pricing_page',
            'contact_page',
            'privacy_page',
            'terms_page',
            'tool_catalog',
            'tool_pages',
            'tool_detail',
            'plans',
            'locales',
        ];

        foreach ($roots as $root) {
            $value = Arr::get(self::CONTENT, $root);

            if (! is_array($value)) {
                continue;
            }

            self::flattenCatalogValues($value, $root, $flat);
        }

        ksort($flat, SORT_NATURAL);

        return $flat;
    }

    public static function text(string $path, array $replace = [], ?string $fallback = null): string
    {
        $override = AreaJsonTranslations::get($path, 'landing');

        if ($override !== null) {
            return self::interpolate($override, $replace);
        }

        $value = Arr::get(self::CONTENT, $path);

        if (! is_string($value)) {
            return $fallback ?? '';
        }

        return __($value, $replace);
    }

    public static function section(string $path): array
    {
        $override = AreaJsonTranslations::group($path, 'landing');

        if ($override !== []) {
            return $override;
        }

        $value = Arr::get(self::CONTENT, $path, []);

        if (! is_array($value)) {
            return [];
        }

        return self::translate($value);
    }

    protected static function translate(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? __($value) : $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::translate($item);
        }

        return $value;
    }

    protected static function flattenCatalogValues(array $value, string $prefix, array &$flat): void
    {
        foreach ($value as $key => $item) {
            $path = $prefix . '.' . $key;

            if (is_array($item)) {
                self::flattenCatalogValues($item, $path, $flat);
                continue;
            }

            if (! is_string($item)) {
                continue;
            }

            if (! self::isCatalogValueEditable($path, $item)) {
                continue;
            }

            $flat[$path] = $item;
        }
    }

    protected static function isCatalogValueEditable(string $path, string $value): bool
    {
        if (str_contains($path, '.icon') || str_ends_with($path, '.slug')) {
            return false;
        }

        // Keep machine-oriented tokens out of the admin translation grid.
        if (preg_match('/^bi bi-[a-z0-9\\-]+$/', trim($value)) === 1) {
            return false;
        }

        return true;
    }

    protected static function interpolate(string $value, array $replace = []): string
    {
        if ($replace === []) {
            return $value;
        }

        $map = [];

        foreach ($replace as $key => $item) {
            $map[':' . $key] = (string) $item;
        }

        return strtr($value, $map);
    }
}
