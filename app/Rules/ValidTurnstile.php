<?php

namespace App\Rules;

use App\Services\Security\TurnstileVerifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidTurnstile implements ValidationRule
{
    public function __construct(
        protected ?TurnstileVerifier $verifier = null,
    ) {
        $this->verifier ??= app(TurnstileVerifier::class);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $token = is_string($value) ? trim($value) : '';

        if ($token === '') {
            $fail(__('Please complete the human verification challenge.'));

            return;
        }

        $result = $this->verifier->verify($token, request()->ip());

        if (! (bool) data_get($result, 'success', false)) {
            $fail(__('Human verification failed. Please refresh the challenge and try again.'));
        }
    }
}
