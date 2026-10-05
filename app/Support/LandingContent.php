<?php

namespace App\Support;

use Illuminate\Support\Arr;

class LandingContent
{
    protected const CONTENT = [
        'site' => [
            'name' => 'MetKurd AI',
            'tagline' => 'The Future of Kurdish AI',
            'author' => 'Michel Mikhael, support@metkurd.ai',
            'founder_name' => 'Michel Mikhael',
            'cofounder_name' => 'Shabo Shabo',
            'origin' => 'Erbil, Kurdistan',
            'meta_description' => 'Create and review Kurdish content with tools built around Sorani. Turn your scripts, recordings and documents into useful work for media, learning and everyday communication.',
            'meta_keywords' => 'MetKurd AI, Kurdish AI, Sorani Kurdish, Kurdish content tools',
            'subject' => 'Create and review Kurdish content with tools built around Sorani. Turn your scripts, recordings and documents into useful work for media, learning and everyday communication.',
            'type' => 'website',
            'robots' => 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
            'theme_color' => '#07111f',
            'default_image_alt' => 'MetKurd AI',
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
            'home_label' => 'MetKurd AI home',
            'toggle_label' => 'Toggle navigation',
            'toggle_theme' => 'Toggle color theme',
            'home' => 'Home',
            'tools' => 'Tools',
            'pricing' => 'Pricing',
            'contact' => 'Contact',
            'overview' => 'AI Overview',
            'research_development' => 'Research and Development',
            'kurdish_ai_challenges' => 'Kurdish AI Challenges',
            'how_built' => 'How It Was Built',
            'privacy' => 'Privacy',
            'terms' => 'Terms',
            'dashboard' => 'Dashboard',
            'profile' => 'Profile',
            'admin_panel' => 'Admin Panel',
            'get_started' => 'Start for Free',
            'sign_in' => 'Sign In',
        ],
        'footer' => [
            'copy' => 'Create and review Kurdish content with tools built around Sorani. Turn your scripts, recordings and documents into useful work for media, learning and everyday communication.',
            'rights' => '(c) :year MetKurd AI. All rights reserved.',
            'made_for' => 'Built for Kurdish content, accessibility and learning.',
            'newsletter_badge' => 'Join the MetKurd AI newsletter',
            'newsletter_title' => 'Get updates on Kurdish AI features, releases, and educational resources.',
            'newsletter_copy' => 'Receive product updates, launch news, and helpful guides for Kurdish-first AI workflows.',
            'newsletter_placeholder' => 'Enter your email',
            'newsletter_form_label' => 'Newsletter email address',
            'newsletter_success' => 'Thanks. Your email was captured locally for now.',
            'subscribe' => 'Subscribe',
            'platform_heading' => 'Platform',
            'resources_heading' => 'Resources',
            'legal_heading' => 'Legal',
            'status_heading' => 'Status',
            'status_uptime' => 'Sorani Kurdish focus',
            'status_secure' => 'Private account files',
            'status_accessible' => 'Arabic and Kurdish interface',
        ],
        'google_review' => [
            'g_badge' => 'Your Feedback Fuels Our Innovation',
            'g_title' => 'Love using MetKurd AI? Share your experience on Google and help us build better Kurdish AI tools for the community.',
            'g_copy' => 'Rate us on Google',
        ],
        'common' => [
            'learn_more' => 'Learn more',
            'open_page' => 'Open page',
            'view_pricing' => 'View pricing',
            'get_started' => 'Start for Free',
            'open_dashboard' => 'Open dashboard',
            'monthly' => 'Monthly',
            'yearly' => 'Yearly',
            'toggle_yearly' => 'Toggle yearly billing',
            'per_month' => '/month',
            'per_year' => '/year',
            'one_time' => 'one-time',
            'monthly_credits_value' => ':value monthly credits',
            'included_actions_value' => ':value included AI actions',
            'credits_short' => ':value credits',
            'actions_short' => ':value actions',
            'base_billing_note' => 'Base billing: :amount',
            'send_message' => 'Send message',
            'message_sent' => 'Your message was sent successfully. Our team will reply as soon as possible.',
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
                'title' => 'Kurdish AI for Speech, Voice, OCR & Audio | MetKurd AI',
                'description' => 'Create and review Kurdish content with tools built around Sorani. Turn your scripts, recordings and documents into useful work for media, learning and everyday communication.',
                'keywords' => 'MetKurd AI, Kurdish AI, Sorani Kurdish, Kurdish content tools',
            ],
            'hero' => [
                'badge' => 'Kurdish-first AI SaaS platform',
                'title_html' => '<span class="landing-gradient-text">The Future of Kurdish AI</span>',
                'lead' => 'Create and review Kurdish content with tools built around Sorani. Turn your scripts, recordings and documents into useful work for media, learning and everyday communication.',
                'primary_cta' => 'Start for Free',
                'secondary_cta' => 'View Pricing',
                'pills' => [
                    'Sorani Kurdish focus',
                    'Tools for speech, documents and audio',
                    'Built for creators, businesses, and education',
                ],
            ],
            'preview' => [
                'live' => 'Live platform preview',
                'latency' => 'Kurdish text to speech',
                'latency_value' => 'Sorani',
                'confidence' => 'Kurdish speech to text and captions',
                'confidence_value' => 'Ready',
                'demo_title' => 'Speech workflow',
                'demo_badge' => 'Sorani',
                'ocr_title' => 'Kurdish OCR and text tools',
                'ocr_badge' => 'Image + PDF',
                'table_headers' => [
                    'page' => 'Step',
                    'status' => 'Status',
                    'language' => 'Language',
                ],
                'table' => [
                    [
                        'page' => '01',
                        'status' => 'Prepared',
                        'language' => 'Kurdish Sorani',
                    ],
                    [
                        'page' => '02',
                        'status' => 'Processed',
                        'language' => 'Arabic script',
                    ],
                ],
            ],
            'tools' => [
                'badge' => 'Core product',
                'title' => 'Practical AI for Kurdish content and everyday work.',
                'copy' => 'Explore the currently available tools for creators, media teams, universities, businesses and NGOs. Choose a tool, review its limits and keep control of the final result.',
            ],
            'demo' => [
                'badge' => 'How it works',
                'title' => 'Use one platform for end-to-end Kurdish AI workflows.',
                'copy' => 'Start with your own content, choose an available tool and review the output. Bring the result into your media, education or research workflow.',
                'sample_text' => 'Sorani Kurdish sample text appears here...',
                'shell' => [
                    'tts_title' => 'Kurdish text to speech',
                    'tts_badge' => 'Text to Speech',
                    'asr_title' => 'Kurdish speech to text and captions',
                    'asr_badge' => 'Speech to Text',
                    'ocr_title' => 'Kurdish OCR and text tools',
                    'ocr_badge' => 'Document OCR',
                ],
                'steps' => [
                    [
                        'title' => 'Input text, audio, image, or PDF',
                        'copy' => 'Start from the format your team already uses in daily workflows.',
                    ],
                    [
                        'title' => 'Run the right AI tool',
                        'copy' => 'Choose the available tool that fits your input and the result you need.',
                    ],
                    [
                        'title' => 'Review and export',
                        'copy' => 'Download outputs and continue your business, education, media, or research workflow.',
                    ],
                ],
                'cards' => [
                    'tts' => 'Kurdish voice generation queue',
                    'asr' => 'Transcription output preview',
                    'ocr' => 'Detected OCR content blocks',
                ],
            ],
            'reasons_heading' => [
                'badge' => 'Why MetKurd AI',
                'title' => 'Built for Sorani Kurdish quality, speed, and real-world productivity.',
            ],
            'reasons' => [
                [
                    'icon' => 'bi bi-translate',
                    'title' => 'Sorani Kurdish focus',
                    'copy' => 'Work with Arabic-script Sorani content and review the output in your own language.',
                ],
                [
                    'icon' => 'bi bi-clock-history',
                    'title' => 'Time and cost efficiency',
                    'copy' => 'Reduce repetitive manual work, while keeping human review part of your workflow.',
                ],
                [
                    'icon' => 'bi bi-book',
                    'title' => 'Digital empowerment and preservation',
                    'copy' => 'MetKurd AI helps reduce the Kurdish digital gap by making Kurdish speech, documents, and content easier to create, search, preserve, and reuse.',
                ],
                [
                    'icon' => 'bi bi-shield-lock',
                    'title' => 'Privacy and trust',
                    'copy' => 'User uploads and generated outputs are private to customer accounts and are not used for training.',
                ],
            ],
            'workflow' => [
                'badge' => 'Language support',
                'title' => 'Accurate public language coverage and transparent limitations.',
                'steps' => [
                    [
                        'title' => 'Primary support: Sorani Kurdish',
                        'copy' => 'MetKurd AI is currently focused on Sorani Kurdish and Arabic-script Kurdish workflows.',
                    ],
                    [
                        'title' => 'Partial support by tool',
                        'copy' => 'Arabic and English support varies by tool. Check the language options of the selected service.',
                    ],
                    [
                        'title' => 'Clear quality expectations',
                        'copy' => 'AI results may not always be perfect. Output quality depends on input quality and is continuously improved.',
                    ],
                ],
                'surface' => [
                    [
                        'label' => 'Kurdish Sorani',
                        'value' => 'Supported',
                    ],
                    [
                        'label' => 'Kurdish Kurmanji',
                        'value' => 'Not currently supported',
                    ],
                    [
                        'label' => 'Arabic and English',
                        'value' => 'Partial by tool',
                    ],
                ],
            ],
            'pricing_heading' => [
                'badge' => 'Pricing and credits',
                'title' => 'Free to start, with flexible subscriptions, credit add-ons, and storage plans.',
            ],
            'testimonial' => [
                'badge' => 'Mission',
                'quote' => 'To help Kurdish people enter the new AI era with high-quality Kurdish-first tools.',
                'copy' => 'MetKurd AI is a startup company founded by Michel Mikhael with co-founder Shabo Shabo, started from Erbil, Kurdistan.',
                'author' => 'MetKurd AI',
            ],
            'faq_heading' => [
                'badge' => 'FAQ and AEO',
                'title' => 'Direct answers about Kurdish AI, MetKurd AI tools, privacy, and usage.',
            ],
            'faqs' => [],
        ],
        'tools_page' => [
            'meta' => [
                'title' => 'Kurdish AI Tools | MetKurd AI',
                'description' => 'Explore current MetKurd tools for Kurdish content, documents and audio, with product explanations and configured examples.',
                'keywords' => 'MetKurd AI, Kurdish AI, Sorani Kurdish, Kurdish content tools',
            ],
            'badge' => 'Current tools',
            'title' => 'AI tools built around Kurdish content.',
            'lead' => 'Explore current tools for Sorani Kurdish content, Arabic text and audio production. Only currently active products are listed.',
            'empty_title' => 'No public tools are available right now',
            'empty_copy' => 'Activate a tool in the dashboard and it will appear here automatically.',
        ],
        'pricing_page' => [
            'meta' => [
                'title' => 'MetKurd AI Pricing | Plans & Credits',
                'description' => 'Compare MetKurd plans, App credits, storage and available developer access. Choose the plan that fits your Kurdish content and processing needs.',
                'keywords' => 'MetKurd AI pricing, Kurdish AI credits, Kurdish AI subscription, Sorani AI plans, OCR pricing, transcription pricing, TTS pricing',
            ],
            'badge' => 'Pricing',
            'title' => 'Simple plans for every stage.',
            'lead' => 'Start for free, then upgrade anytime with monthly credits, one-time add-ons, and storage expansion for heavier workloads.',
            'featured_label' => 'Best value',
            'currency_notice' => 'Pricing display resolved for: :currency',
            'base_currency_notice' => 'Billing source remains IQD.',
            'monthly_note' => 'Billed monthly',
            'yearly_note' => 'Billed yearly',
            'summary_fallback' => 'Includes :credits monthly credits and :actions enabled actions.',
            'storage_badge' => 'Storage Pricing',
            'storage_title' => 'Storage upgrades',
            'storage_copy' => 'Choose additional storage for your uploaded files and saved results.',
            'addons_badge' => 'Add-on Pricing',
            'addons_title' => 'Credit top-ups',
            'addons_copy' => 'One-time add-on packs for extra credits whenever your team needs more throughput.',
            'offer_name' => 'MetKurd AI Free Start',
            'offer_category' => 'Kurdish-first AI SaaS subscription',
            'faq_heading' => [
                'badge' => 'Pricing FAQ',
                'title' => 'Credits, storage, billing, and plan behavior explained clearly.',
            ],
            'faqs' => [],
            'highlights' => [
                [
                    'icon' => 'bi bi-lightning-charge',
                    'title' => 'Free to start',
                    'copy' => 'New users can begin with free credits before moving to paid plans.',
                ],
                [
                    'icon' => 'bi bi-bar-chart-line',
                    'title' => 'Tool-based credit logic',
                    'copy' => 'Different tools use different metrics, including character, minute, page, and separation-based usage.',
                ],
                [
                    'icon' => 'bi bi-arrow-repeat',
                    'title' => 'Flexible upgrades',
                    'copy' => 'Switch plans or add credits whenever usage grows.',
                ],
            ],
        ],
        'contact_page' => [
            'meta' => [
                'title' => 'Contact',
                'description' => 'Contact MetKurd AI for support, partnerships, education use cases, media workflows, and Kurdish AI product questions.',
                'keywords' => 'Contact MetKurd AI, Kurdish AI support, Sorani AI support, Kurdish OCR support, Kurdish transcription support',
            ],
            'badge' => 'Contact',
            'title' => 'Talk to the team behind MetKurd AI.',
            'lead' => 'Contact us for support, business questions, and Kurdish AI workflow guidance.',
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
                'MetKurd AI startup company',
                'Erbil, Kurdistan',
                'Founder and CEO: Michel Mikhael',
                'Co-Founder: Shabo Shabo',
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
                'description' => 'Understand how MetKurd AI handles user privacy, uploads, generated files, and secure storage practices.',
                'keywords' => 'MetKurd AI privacy policy, Kurdish AI privacy, user uploads privacy, AI storage policy',
            ],
            'badge' => 'Privacy Policy',
            'title' => 'Privacy and trust for Kurdish AI workflows.',
            'lead' => 'MetKurd AI states that user uploads and generated outputs are private and are not used for training.',
            'cards' => [
                [
                    'title' => 'Private customer content',
                    'copy' => 'Uploaded files and generated outputs are private to customer accounts.',
                ],
                [
                    'title' => 'No training on user data',
                    'copy' => 'MetKurd AI states that user uploads and generated outputs are not used for training.',
                ],
                [
                    'title' => 'Secure storage',
                    'copy' => 'Uploaded files and generated outputs are stored securely and protected using encryption where applicable.',
                ],
                [
                    'title' => 'File deletion controls',
                    'copy' => 'Customers can delete generated files and manage storage directly from their account.',
                ],
                [
                    'title' => 'Retention behavior',
                    'copy' => 'Files remain stored until customers delete files or delete their account, based on product behavior.',
                ],
                [
                    'title' => 'Payments and responsibility',
                    'copy' => 'Secure payments are supported through FIB and credit or debit card options. Users are responsible for uploaded and generated content.',
                ],
            ],
        ],
        'terms_page' => [
            'meta' => [
                'title' => 'Terms and Conditions',
                'description' => 'Review the terms for using MetKurd AI services, including content responsibility, safe usage, and billing behavior.',
                'keywords' => 'MetKurd AI terms, Kurdish AI usage policy, SaaS terms, voice cloning policy, AI content responsibility',
            ],
            'badge' => 'Terms and Conditions',
            'title' => 'Clear terms for responsible Kurdish AI usage.',
            'lead' => 'Use of MetKurd AI must follow legal rights, safe usage expectations, and platform policies.',
            'cards' => [
                [
                    'title' => 'User agreement',
                    'copy' => 'Users must access the service lawfully and remain responsible for uploaded content and account security.',
                ],
                [
                    'title' => 'Voice cloning safety',
                    'copy' => 'Do not clone political figures, famous figures, or any voice in a misleading, harmful, or unauthorized way.',
                ],
                [
                    'title' => 'AI content policy',
                    'copy' => 'The platform must not be used to generate harmful, deceptive, or unauthorized content.',
                ],
                [
                    'title' => 'Copyright and permission',
                    'copy' => 'Users are responsible for ensuring they have permission and legal rights for uploaded materials and generated outputs.',
                ],
                [
                    'title' => 'Payment terms',
                    'copy' => 'Paid subscriptions renew under selected billing cycles, and customers can upgrade or cancel according to plan rules.',
                ],
                [
                    'title' => 'Service limitations',
                    'copy' => 'AI outputs may contain mistakes and are continuously improved; API access requires eligible configured paid access.',
                ],
            ],
        ],
        'overview_page' => [
            'meta' => [
                'title' => 'MetKurd AI Overview | Kurdish-first AI Platform',
                'description' => 'Create and review Kurdish content with tools built around Sorani. Turn your scripts, recordings and documents into useful work for media, learning and everyday communication.',
                'keywords' => 'MetKurd AI overview, Kurdish-first AI platform, Sorani Kurdish AI, Kurdish AI tools',
            ],
            'badge' => 'AI Overview',
            'title' => 'MetKurd AI: Kurdish-first AI platform overview',
            'lead' => 'MetKurd AI is a Kurdish-first SaaS platform designed to help users, creators, businesses, students, and researchers work with Sorani Kurdish speech and text more efficiently.',
            'one_sentence_heading' => 'One-sentence AI description',
            'one_sentence' => 'Create and review Kurdish content with tools built around Sorani. Turn your scripts, recordings and documents into useful work for media, learning and everyday communication.',
            'one_paragraph_heading' => 'One-paragraph AI description',
            'one_paragraph' => 'MetKurd helps people create, review and reuse Kurdish content for media, education, accessibility and research. Explore the current tools to see available products and examples.',
            'facts_heading' => 'Key facts',
            'facts' => [
                'Official name: MetKurd AI',
                'Category: Kurdish-first AI SaaS platform',
                'Primary language focus: Sorani Kurdish',
                'Tagline: The Future of Kurdish AI',
                'Founder and CEO: Michel Mikhael',
                'Co-Founder: Shabo Shabo',
                'Erbil, Kurdistan',
                'The team recorded and prepared Kurdish data manually.',
                'User uploads and outputs are not used for training.',
            ],
            'tools_heading' => 'Main tools',
            'tools' => [
                'AI tools built around Kurdish content.',
                'Kurdish voice cloning',
                'LEO',
                'Kurdish OCR and text tools',
                'Music and vocal separation',
            ],
            'limitations_heading' => 'Important limitations',
            'limitations' => [
                'Kurmanji Kurdish is not currently supported.',
                'MetKurd provides REST API V2 access on eligible configured paid plans.',
                'AI outputs may contain mistakes and are continuously improved.',
            ],
        ],
        'research_development_page' => [
            'meta' => [
                'title' => 'Research and Development | MetKurd AI',
                'description' => 'Learn how MetKurd AI approaches Kurdish-first research, data preparation, and quality improvement for Sorani Kurdish AI tools.',
                'keywords' => 'Kurdish AI research, Sorani AI development, MetKurd AI R&D, Kurdish language technology',
            ],
            'badge' => 'Research and Development',
            'title' => 'How MetKurd AI approaches Kurdish AI research and development',
            'lead' => 'Building useful Kurdish AI requires more than connecting a generic model. MetKurd AI was developed through Kurdish-focused data preparation, manual review, language testing, and continuous improvement around Sorani Kurdish speech and text.',
            'sections' => [
                [
                    'title' => 'Manual Kurdish data preparation',
                    'copy' => 'Kurdish AI data was difficult to collect, so the team recorded and prepared data manually for practical model training and evaluation workflows.',
                ],
                [
                    'title' => 'Language review with educators',
                    'copy' => 'Kurdish spelling and word correctness were reviewed with Kurdish language teachers and academic reviewers to improve quality and linguistic consistency.',
                ],
                [
                    'title' => 'Limited dictionary resources',
                    'copy' => 'A lack of official and dependable dictionaries created additional validation challenges in normalization, correction, and lexical alignment.',
                ],
                [
                    'title' => 'Data quality drives model quality',
                    'copy' => 'Output quality depends heavily on data quality, so data cleaning, annotation discipline, and repeated evaluation remain central to progress.',
                ],
                [
                    'title' => 'Continuous model and workflow testing',
                    'copy' => 'Different approaches and models were tested across speech and text workflows to improve real-world usability for Kurdish users.',
                ],
            ],
        ],
        'kurdish_ai_challenges_page' => [
            'meta' => [
                'title' => 'Kurdish AI Challenges | MetKurd AI',
                'description' => 'Understand why Kurdish is difficult for generic AI tools and why language-specific Sorani Kurdish workflows matter.',
                'keywords' => 'Kurdish AI challenges, low-resource language AI, Sorani Kurdish AI challenges',
            ],
            'badge' => 'Kurdish AI Challenges',
            'title' => 'Why Kurdish is challenging for generic AI systems',
            'lead' => 'Generic AI tools often provide limited support for low-resource languages. MetKurd AI is built specifically for Kurdish-first workflows with a current focus on Sorani Kurdish.',
            'sections' => [
                [
                    'title' => 'Low-resource language constraints',
                    'copy' => 'Kurdish has fewer publicly available high-quality datasets compared with widely supported global languages.',
                ],
                [
                    'title' => 'Dialect and script complexity',
                    'copy' => 'Dialect differences and script variation require language-specific handling instead of one-size-fits-all multilingual assumptions.',
                ],
                [
                    'title' => 'Limited standardized linguistic resources',
                    'copy' => 'Spelling variation and specialist vocabulary require careful review of AI outputs.',
                ],
                [
                    'title' => 'Quality requires domain adaptation',
                    'copy' => 'Accurate Kurdish AI outcomes need data preparation and evaluation tuned to Kurdish usage contexts, not only generic benchmarks.',
                ],
            ],
        ],
        'how_built_page' => [
            'meta' => [
                'title' => 'How MetKurd AI Was Built',
                'description' => 'Read the public build story behind MetKurd AI, including manual data work, language review, and iterative quality improvements.',
                'keywords' => 'How MetKurd AI was built, Kurdish AI build story, Sorani AI development story',
            ],
            'badge' => 'Build Story',
            'title' => 'How MetKurd AI was built',
            'lead' => 'MetKurd AI started in Erbil, Kurdistan with a focus on practical tools for Sorani Kurdish content and everyday work.',
            'timeline' => [
                [
                    'title' => 'Step 1: Define a Kurdish-first mission',
                    'copy' => 'The project mission prioritized Kurdish digital access, language preservation, and practical productivity for users and businesses.',
                ],
                [
                    'title' => 'Step 2: Collect and prepare data manually',
                    'copy' => 'The team invested in manual Kurdish data collection and preparation because ready-to-use dependable resources were limited.',
                ],
                [
                    'title' => 'Step 3: Validate language quality',
                    'copy' => 'Spelling and wording quality were reviewed with Kurdish language educators to improve naturalness and consistency.',
                ],
                [
                    'title' => 'Step 4: Test and improve continuously',
                    'copy' => 'Multiple approaches were tested, measured, and iterated to improve quality for real users across tools.',
                ],
            ],
        ],
        'tool_catalog' => [
            'tts' => [
                'slug' => 'tts',
                'icon' => 'bi bi-soundwave',
                'badge' => 'Speech',
                'title' => 'Kurdish text to speech',
                'summary' => 'Turn Sorani Kurdish scripts into speech with built-in voices. Create narration for media, education and accessibility, then review the audio before publishing.',
                'capabilities' => [
                    'Sorani Kurdish support',
                    'Male and female voices',
                    'WAV output download',
                ],
            ],
            'clone_tts' => [
                'slug' => 'ctts',
                'icon' => 'bi bi-mic',
                'badge' => 'Voice clone',
                'title' => 'Kurdish voice cloning',
                'summary' => 'Generate speech from text using a voice recording you are authorized to use. Choose a clear single-speaker reference without music or overlapping speech; recording quality affects the result.',
                'capabilities' => [
                    '10 to 30 second sample',
                    'Sample filtering support',
                    'Sorani Kurdish output',
                ],
            ],
            'asr' => [
                'slug' => 'asr',
                'icon' => 'bi bi-file-earmark-text',
                'badge' => 'Transcription',
                'title' => 'Kurdish speech to text and captions',
                'summary' => 'Transcribe Sorani Kurdish recordings into searchable text for interviews, lectures and archives. Review names and specialist terms; noise and overlapping speakers can reduce accuracy.',
                'capabilities' => [
                    'Sorani Kurdish',
                    'LEO: Sorani',
                    'TXT output',
                ],
            ],
            'ocr' => [
                'slug' => 'ocr',
                'icon' => 'bi bi-images',
                'badge' => 'Documents',
                'title' => 'Kurdish OCR and text tools',
                'summary' => 'Extract Sorani Kurdish text from images and scanned PDFs for books, forms and research archives. Review spelling and layout, then export reusable text.',
                'capabilities' => [
                    'Arabic-script Kurdish',
                    'Image and PDF support',
                    'Partial handwriting support',
                ],
            ],
            'stem' => [
                'slug' => 'stem',
                'icon' => 'bi bi-disc',
                'badge' => 'Audio',
                'title' => 'Music and vocal separation',
                'summary' => 'Separate vocals from music and review individual audio tracks. Audio stem separation is language-independent and useful for practice, analysis and media production; some sound leakage may remain.',
                'capabilities' => [
                    'Stem separation',
                    'Vocal remover',
                    'Noise reduction',
                ],
            ],
        ],
        'tool_pages' => [
            'tts' => [
                'meta_title' => 'Kurdish Text to Speech (Sorani TTS) | MetKurd AI',
                'meta_description' => 'Turn Sorani Kurdish scripts into speech with built-in voices. Create narration for media, education and accessibility, then review the audio before publishing.',
                'badge' => 'Kurdish text to speech',
                'title' => 'Kurdish text to speech',
                'lead' => 'Turn Sorani Kurdish scripts into speech with built-in voices. Create narration for media, education and accessibility, then review the audio before publishing.',
                'about_title' => 'What it does',
                'about_copy' => 'Turn Sorani Kurdish scripts into speech with built-in voices. Create narration for media, education and accessibility, then review the audio before publishing.',
                'use_cases_title' => 'Use cases',
                'use_cases' => [
                    'Audiobooks and narration',
                    'Educational audio content',
                    'Product voice experiences',
                    'YouTube voiceovers',
                    'Advertisements',
                ],
                'features' => [
                    [
                        'icon' => 'bi bi-lightning-charge',
                        'title' => 'Sorani Kurdish support',
                        'copy' => 'Currently supports Sorani Kurdish text workflows.',
                    ],
                    [
                        'icon' => 'bi bi-person-check',
                        'title' => 'Voice preview and selection',
                        'copy' => 'Supports multiple voices, including male and female voice options.',
                    ],
                    [
                        'icon' => 'bi bi-stars',
                        'title' => 'Downloadable output',
                        'copy' => 'Generated audio is downloadable in WAV format.',
                    ],
                ],
            ],
            'clone_tts' => [
                'meta_title' => 'Kurdish Voice Cloning | MetKurd AI',
                'meta_description' => 'Generate speech from text using a voice recording you are authorized to use. Choose a clear single-speaker reference without music or overlapping speech; recording quality affects the result.',
                'badge' => 'Kurdish voice cloning',
                'title' => 'Kurdish voice cloning',
                'lead' => 'Generate speech from text using a voice recording you are authorized to use. Choose a clear single-speaker reference without music or overlapping speech; recording quality affects the result.',
                'about_title' => 'What it does',
                'about_copy' => 'Generate speech from text using a voice recording you are authorized to use. Choose a clear single-speaker reference without music or overlapping speech; recording quality affects the result.',
                'use_cases_title' => 'Use cases',
                'use_cases' => [
                    'Custom branded voices',
                    'Speaker style replication',
                    'Narration consistency',
                    'Creative media workflows',
                    'Kurdish voiceover production',
                ],
                'features' => [
                    [
                        'icon' => 'bi bi-lightning-charge',
                        'title' => 'Recommended sample length',
                        'copy' => '10 to 30 seconds is recommended for better style capture.',
                    ],
                    [
                        'icon' => 'bi bi-shield-lock',
                        'title' => 'Sample format support',
                        'copy' => 'Supports mp3, wav, flac, m4a, aac, ogg, and opus samples.',
                    ],
                    [
                        'icon' => 'bi bi-exclamation-triangle',
                        'title' => 'Safety notice',
                        'copy' => 'Do not clone political figures, famous figures, or any voice in a misleading, harmful, or unauthorized way.',
                    ],
                ],
            ],
            'asr' => [
                'meta_title' => 'Kurdish Speech to Text & Captions | MetKurd AI',
                'meta_description' => 'Transcribe Sorani Kurdish recordings into searchable text for interviews, lectures and archives. Review names and specialist terms; noise and overlapping speakers can reduce accuracy.',
                'badge' => 'Kurdish speech to text and captions',
                'title' => 'Kurdish speech to text and captions',
                'lead' => 'Transcribe Sorani Kurdish recordings into searchable text for interviews, lectures and archives. Review names and specialist terms; noise and overlapping speakers can reduce accuracy.',
                'about_title' => 'What it does',
                'about_copy' => 'Transcribe Sorani Kurdish recordings into searchable text for interviews, lectures and archives. Review names and specialist terms; noise and overlapping speakers can reduce accuracy.',
                'use_cases_title' => 'Use cases',
                'use_cases' => [
                    'Podcast transcription',
                    'Class recordings',
                    'Radio interviews',
                    'Research interviews',
                    'Meeting documentation',
                ],
                'features' => [
                    [
                        'icon' => 'bi bi-lightning-charge',
                        'title' => 'Model coverage',
                        'copy' => 'Check supported languages in the selected tool.',
                    ],
                    [
                        'icon' => 'bi bi-file-earmark-text',
                        'title' => 'Long audio support',
                        'copy' => 'Supports uploaded audio files up to 100MB.',
                    ],
                    [
                        'icon' => 'bi bi-clock-history',
                        'title' => 'Current limitations',
                        'copy' => 'Timestamps are coming soon, and speaker separation is not currently supported.',
                    ],
                ],
            ],
            'ocr' => [
                'meta_title' => 'Kurdish OCR and text tools',
                'meta_description' => 'Extract Sorani Kurdish text from images and scanned PDFs for books, forms and research archives. Review spelling and layout, then export reusable text.',
                'badge' => 'Kurdish OCR and text tools',
                'title' => 'Kurdish OCR and text tools',
                'lead' => 'Extract Sorani Kurdish text from images and scanned PDFs for books, forms and research archives. Review spelling and layout, then export reusable text.',
                'about_title' => 'What it does',
                'about_copy' => 'Extract Sorani Kurdish text from images and scanned PDFs for books, forms and research archives. Review spelling and layout, then export reusable text.',
                'use_cases_title' => 'Use cases',
                'use_cases' => [
                    'Digitizing books',
                    'Image text extraction',
                    'PDF archive processing',
                    'Educational document workflows',
                    'Historical archive processing',
                ],
                'features' => [
                    [
                        'icon' => 'bi bi-lightning-charge',
                        'title' => 'Arabic-script support',
                        'copy' => 'Built for Arabic-script Kurdish images and documents.',
                    ],
                    [
                        'icon' => 'bi bi-file-earmark-richtext',
                        'title' => 'Practical formats',
                        'copy' => 'Supports images, scanned documents, and PDFs with TXT output.',
                    ],
                    [
                        'icon' => 'bi bi-exclamation-circle',
                        'title' => 'Current limitations',
                        'copy' => 'Handwriting support is partial and real-time camera capture is not currently supported.',
                    ],
                ],
            ],
            'stem' => [
                'meta_title' => 'AI Music & Vocal Separation | MetKurd AI',
                'meta_description' => 'Separate vocals from music and review individual audio tracks. Audio stem separation is language-independent and useful for practice, analysis and media production; some sound leakage may remain.',
                'badge' => 'Music and vocal separation',
                'title' => 'Music and vocal separation',
                'lead' => 'Separate vocals from music and review individual audio tracks. Audio stem separation is language-independent and useful for practice, analysis and media production; some sound leakage may remain.',
                'about_title' => 'What it does',
                'about_copy' => 'Separate vocals from music and review individual audio tracks. Audio stem separation is language-independent and useful for practice, analysis and media production; some sound leakage may remain.',
                'use_cases_title' => 'Use cases',
                'use_cases' => [
                    'Karaoke and acapella creation',
                    'Music and podcast production',
                    'Mix inspection',
                    'Educational review',
                    'Creative media workflows',
                ],
                'features' => [
                    [
                        'icon' => 'bi bi-lightning-charge',
                        'title' => 'Stem separation',
                        'copy' => 'Split uploaded audio into separated tracks for targeted reuse.',
                    ],
                    [
                        'icon' => 'bi bi-mic-mute',
                        'title' => 'Vocal remover',
                        'copy' => 'Remove vocals for practice, karaoke, and content preparation tasks.',
                    ],
                    [
                        'icon' => 'bi bi-soundwave',
                        'title' => 'Noise reduction',
                        'copy' => 'Reduce background noise to improve practical listening and analysis workflows.',
                    ],
                ],
            ],
        ],
        'tool_detail' => [
            'fallback_title' => 'Tool',
            'model_label' => 'Model',
            'status_label' => 'Status',
            'status_ready' => 'Ready',
            'use_cases_title' => 'Use cases',
        ],
        'plans' => [
            'free' => [
                'title' => 'Free',
                'summary' => 'Free starter access with limited credits for evaluating Kurdish AI workflows.',
                'features' => [
                    'Starter monthly credits',
                    'Core product access',
                    'Upgrade anytime',
                ],
                'cta' => 'Start for Free',
            ],
            'student' => [
                'title' => 'Student',
                'summary' => 'Balanced plan for learning, coursework, and educational Kurdish AI tasks.',
                'features' => [
                    'Higher monthly credits',
                    'Tools for speech, documents and audio',
                    'Email support',
                ],
                'cta' => 'Get Started',
            ],
            'pro' => [
                'title' => 'Pro',
                'summary' => 'Production-focused plan for creators, teams, and business workflows.',
                'features' => [
                    'Higher throughput',
                    'Business-ready usage',
                    'Best fit for daily workloads',
                ],
                'cta' => 'Get Started',
                'featured' => true,
            ],
            'premium' => [
                'title' => 'Premium',
                'summary' => 'Expanded monthly capacity for heavy and continuous usage.',
                'features' => [
                    'Large monthly credit pool',
                    'Priority processing',
                    'Dedicated support path',
                ],
                'cta' => 'Get Started',
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
            'overview_page',
            'research_development_page',
            'kurdish_ai_challenges_page',
            'how_built_page',
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
        $public = app(\App\Support\Landing\PublicWebsiteContent::class);
        $catalog = app(\App\Support\Landing\PublicProductCatalog::class);
        if ($path === 'home.meta.description') {
            return $public->homeDescription();
        }
        if (in_array($path, ['site.meta_description', 'site.subject', 'footer.copy',
            'overview_page.meta.description', 'overview_page.one_sentence'], true)) {
            return $public->entity();
        }
        $family = match ($path) {
            'home.preview.latency', 'home.demo.shell.tts_title' => 'tts',
            'home.preview.confidence', 'home.demo.shell.asr_title' => 'asr',
            'home.preview.ocr_title', 'home.demo.shell.ocr_title' => 'ocr',
            default => null,
        };
        if ($family !== null) {
            return implode(' · ', $catalog->names($family)) ?: $public->text('no_tools');
        }
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
        $public = app(\App\Support\Landing\PublicWebsiteContent::class);
        if (in_array($path, ['home.faqs', 'pricing_page.faqs'], true)) {
            return $public->faqs($path === 'home.faqs' ? 'home' : 'pricing');
        }
        if ($path === 'overview_page.tools') {
            return app(\App\Support\Landing\PublicProductCatalog::class)->names();
        }
        $override = AreaJsonTranslations::group($path, 'landing');

        if ($override !== []) {
            $value = $override;
        } else {
            $value = Arr::get(self::CONTENT, $path, []);
            $value = is_array($value) ? self::translate($value) : [];
        }
        if ($path === 'home.hero') {
            $value['lead'] = $public->entity();
        }
        if ($path === 'home.preview') {
            foreach (['latency', 'confidence', 'ocr_title'] as $key) {
                $value[$key] = self::text('home.preview.'.$key);
            }
        }
        if ($path === 'home.workflow') {
            $value['surface'] = array_slice($value['surface'] ?? [], 0, 3);
            $catalog = app(\App\Support\Landing\PublicProductCatalog::class);
            if ($catalog->apiEnabled()) {
                $value['surface'][] = ['label' => 'API', 'value' => $public->text('api_included')];
            }
            if ($catalog->mcpEnabled()) {
                $value['surface'][] = ['label' => 'MCP', 'value' => $public->text('mcp_included')];
            }
        }
        if ($path === 'overview_page.limitations') {
            unset($value[1]);
            $catalog = app(\App\Support\Landing\PublicProductCatalog::class);
            $value[] = $public->text($catalog->apiEnabled() ? 'api_available' : 'api_unavailable');
            if ($catalog->mcpEnabled()) {
                $value[] = $public->text('mcp_available');
            }
            $value = array_values($value);
        }

        return $value;
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
            $path = $prefix.'.'.$key;

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
            $map[':'.$key] = (string) $item;
        }

        return strtr($value, $map);
    }
}
