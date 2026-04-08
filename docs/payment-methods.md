# Payment Methods

The checkout system is now driven by the `payment_methods` table plus provider adapters under `app/Services/Payments/Providers`.

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
