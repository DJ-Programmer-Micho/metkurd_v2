# Payment Methods

The checkout system is now driven by the `payment_methods` table plus provider adapters under `app/Services/Payments/Providers`.

## Mobile API auth requirement

- Mobile clients do not have a normal register endpoint.
- Mobile account creation is Socialite-only (`POST /api/mobile/auth/social/{provider}`).
- Before any payment, checkout, or subscription-related API action, the customer must complete phone onboarding:
  - submit phone number
  - send OTP
  - verify OTP
- Do not allow payment/subscription flows from onboarding-only mobile tokens (`mobile:onboarding`).

## Add a new payment method

1. Create a provider adapter that implements `App\Contracts\Payments\PaymentProviderInterface`.
2. Register the adapter class under `config/payments.php` in `payments.providers.{driver}.provider_class`.
3. Seed or create a `payment_methods` row with:
   - `code`
   - `driver`
   - `name`
   - `supported_purchase_types`
   - `supported_currencies`
   - support flags
   - `fee_config`
4. Keep secrets in `.env` and `config/payments.php`, not in the database admin UI.
5. If the provider supports webhooks, point the provider callback URL to the matching app route and implement webhook normalization in the adapter.

## Disable a payment method

Set `is_active = false` in the admin payment-method page. This removes it from checkout without deleting history.

## Production safety defaults

Use these environment values in production:

- `PAYMENTS_DEFAULT_PROVIDER=fib`
- `PAYMENTS_FAKE_ENABLED=false`

Behavior notes:

- When fake is disabled, it is excluded from checkout method resolution and customer checkout options.
- If `PAYMENTS_DEFAULT_PROVIDER` points to a disabled/unavailable provider, checkout falls back to the first checkout-ready method and logs a warning with context (`preferred_provider`, purpose, fallback method).
- Keep fake enabled only in local/test environments where simulated payments are intentionally used.

## Hide a payment method

Set `is_visible = false`. The method stays in the catalog but is hidden from customer checkout screens.

## Remove a payment method

Delete the `payment_methods` row only when it is no longer needed for configuration. Historical orders and intents keep their stored `provider` and `payment_method` strings.

## Change labels, icons, order, purchase types, currencies, or fees

Use the admin payment-method page to update:
- `name`
- `description`
- `icon`
- `sort_order`
- `supported_purchase_types`
- `supported_currencies`
- `fee_config`
- support flags

## Secrets

Keep API keys, client IDs, client secrets, webhook secrets, and environment-specific credentials in `.env` and `config/payments.php`. The database only stores non-secret business and UI configuration.

## FIB configuration

FIB runtime configuration now lives in `config/fib.php` with explicit payment and subscription profiles.

Preferred env keys:
- `FIB_ENV`
- `FIB_PAYMENT_BASE_URL_STAGING`
- `FIB_PAYMENT_BASE_URL_PRODUCTION`
- `FIB_SUBSCRIPTION_BASE_URL_STAGING`
- `FIB_SUBSCRIPTION_BASE_URL_PRODUCTION`
- `FIB_PAYMENT_CLIENT_ID`
- `FIB_PAYMENT_CLIENT_SECRET`
- `FIB_SUBSCRIPTION_CLIENT_ID`
- `FIB_SUBSCRIPTION_CLIENT_SECRET`
- `FIB_CALLBACK_BASE_URL`

Legacy generic keys such as `FIB_BASE_URL` and `FIB_CLIENT_ID` are still accepted as temporary fallbacks, but `php artisan fib:debug-config` will flag them as legacy so the effective source is visible.

For local debugging, run:

```bash
php artisan fib:debug-config
php artisan fib:debug-config --probe
```

The probe command does not print secrets or bearer tokens. It reports the resolved host, token issuer, and the protected-endpoint response for each profile so staging host mismatches are easy to spot.

## FIB production rejection checklist

Use this checklist when real customer checkouts are being rejected in production:

1. Verify production provider defaults:
   - `PAYMENTS_DEFAULT_PROVIDER=fib`
   - `PAYMENTS_FAKE_ENABLED=false`
   - `FIB_ENABLED=true`
2. Verify callback base URL is public HTTPS:
   - `APP_URL=https://metkurd.ai`
   - `FIB_CALLBACK_BASE_URL=https://metkurd.ai`
3. Verify payment and subscription profiles are configured separately:
   - Payment profile uses `FIB_PAYMENT_CLIENT_ID` / `FIB_PAYMENT_CLIENT_SECRET` (`pg-*`)
   - Subscription profile uses `FIB_SUBSCRIPTION_CLIENT_ID` / `FIB_SUBSCRIPTION_CLIENT_SECRET` (`sub-*`)
4. Clear and rebuild config cache on every app VM after env changes:
   - `php artisan optimize:clear`
   - `php artisan config:cache`
5. Verify webhook routes are reachable publicly:
   - `POST https://metkurd.ai/payments/webhooks/fib`
   - `POST https://metkurd.ai/payments/webhooks/fib/subscription`
   - Ensure no auth/CSRF/Cloudflare rules block provider callbacks.
6. Use the diagnose command to inspect live status and provider reason for one payment UUID:
   - `php artisan payments:fib:diagnose`
   - `php artisan payments:fib:diagnose <payment_uuid>`
   - `php artisan payments:fib:diagnose <payment_uuid> --no-provider-check`

The diagnose command prints non-secret runtime data:
- app environment and `APP_URL`
- callback URL and callback validity check
- resolved payment/subscription base hosts and client-id source
- local payment status and provider reference
- live provider status, mapped local status, provider reason, and provider error codes
- current VM hostname (useful in multi-VM load-balanced production)
