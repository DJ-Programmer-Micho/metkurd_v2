<?php

use App\Notifications\Landing\TelegramContactUs;
use App\Services\Security\TurnstileVerifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Stevebauman\Location\Facades\Location;

beforeEach(function () {
    Notification::fake();
    app()->setLocale('en');

    RateLimiter::clear('landing-contact:'.sha1('127.0.0.1'));
    RateLimiter::clear('landing-contact:'.sha1('::1'));

    $verifier = \Mockery::mock(TurnstileVerifier::class);
    $verifier->shouldReceive('verify')->andReturn(['success' => true]);
    app()->instance(TurnstileVerifier::class, $verifier);

    Location::shouldReceive('get')->andReturn(false);
});

it('uses the configured contact telegram group when submitting the contact form', function () {
    config()->set('services.telegram-bot-api.groups.contact', '-503111222333');

    Livewire::test('landing::pages.contact')
        ->set('name', 'Contact Tester')
        ->set('email', 'contact.tester@example.com')
        ->set('subject', 'Support request')
        ->set('body', 'Please contact me about a production issue.')
        ->set('cfTurnstileResponse', 'turnstile-token')
        ->call('submitMessage')
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(
        TelegramContactUs::class,
        function (TelegramContactUs $notification, array $channels, $notifiable): bool {
            return in_array(\NotificationChannels\Telegram\TelegramChannel::class, $channels, true)
                && (string) $notifiable->routeNotificationFor('telegram') === '-503111222333';
        }
    );
});

it('logs a clear warning and shows a friendly error when contact telegram group is missing', function () {
    config()->set('services.telegram-bot-api.groups.contact', '');
    Log::spy();

    Livewire::test('landing::pages.contact')
        ->set('name', 'Contact Tester')
        ->set('email', 'contact.tester@example.com')
        ->set('subject', 'Missing telegram config')
        ->set('body', 'This should fail gracefully because no contact chat id is configured.')
        ->set('cfTurnstileResponse', 'turnstile-token')
        ->call('submitMessage')
        ->assertHasErrors(['form']);

    Notification::assertNothingSent();

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Contact page telegram destination is missing.'
                && in_array('TELEGRAM_GROUP_CON', (array) data_get($context, 'expected_keys', []), true);
        })
        ->once();
});
