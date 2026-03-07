{{-- resources/views/app/pages/addon-credits/⚡addon-credits.blade.php --}}
<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;

use App\Models\CreditProduct;
use App\Models\CreditOrder;
use App\Services\Billing\CreditService;

new
#[Layout('app::layouts.app')]
#[Title('Add-on Credits | METKURD')]
class extends Component
{
    public array $products = [];
    public ?int $selectedProductId = null;

    public bool $showConfirm = false;
    public bool $processing = false;

    public string $message = '';
    public string $messageType = 'success';

    #[On('customerPlanUpdated')]
    #[On('header:refresh')]
    public function refreshPageData(): void
    {
        $this->loadData();
    }

    public function mount(): void
    {
        $this->loadData();
    }

    protected function loadData(): void
    {
        $this->products = CreditProduct::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => (int) $p->id,
                    'code' => (string) $p->code,
                    'name' => (string) $p->name,
                    'credits_amount' => (int) ($p->credits_amount ?? 0),
                    'price_usd' => (float) ($p->price_usd ?? 0),
                ];
            })
            ->values()
            ->all();
    }

    public function openConfirm(int $productId): void
    {
        if ($this->processing) {
            return;
        }

        $exists = collect($this->products)->contains(fn ($p) => (int) $p['id'] === $productId);
        if (!$exists) {
            $this->messageType = 'danger';
            $this->message = 'Selected add-on product was not found.';
            return;
        }

        $this->selectedProductId = $productId;
        $this->message = '';
        $this->messageType = 'success';
        $this->showConfirm = true;
    }

    public function closeConfirm(): void
    {
        if ($this->processing) {
            return;
        }

        $this->showConfirm = false;
        $this->selectedProductId = null;
    }

    public function confirmPurchase(): void
    {
        if ($this->processing) {
            return;
        }

        $customer = auth('app')->user();

        if (!$customer) {
            $this->messageType = 'danger';
            $this->message = 'Customer not found.';
            return;
        }

        if (!$this->selectedProductId) {
            $this->messageType = 'danger';
            $this->message = 'Please choose an add-on product first.';
            return;
        }

        $this->processing = true;
        $this->message = '';
        $this->messageType = 'success';

        try {
            DB::transaction(function () use ($customer) {
                $product = CreditProduct::query()
                    ->where('is_active', true)
                    ->findOrFail($this->selectedProductId);

                $order = CreditOrder::create([
                    'customer_id' => (int) $customer->id,
                    'order_type' => 'addon',
                    'source_type' => 'credit_product',
                    'service_plan_id' => null,
                    'credit_product_id' => (int) $product->id,
                    'status' => 'paid',
                    'credits_amount' => (int) $product->credits_amount,
                    'amount_usd' => (float) $product->price_usd,
                    'currency' => 'USD',
                    'provider' => 'fake',
                    'provider_ref' => 'FAKE-ADDON-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
                    'meta' => [
                        'ui' => 'addon-credits-page',
                        'product_code' => (string) $product->code,
                        'product_name' => (string) $product->name,
                    ],
                ]);

                app(CreditService::class)->addAddonCredits(
                    customerId: (int) $customer->id,
                    credits: (int) $product->credits_amount,
                    meta: [
                        'related_type' => CreditOrder::class,
                        'related_id' => (string) $order->id,
                        'reference_code' => 'ADDON-' . $order->id,
                        'product_id' => (int) $product->id,
                        'product_code' => (string) $product->code,
                        'product_name' => (string) $product->name,
                        'amount_usd' => (float) $product->price_usd,
                        'ui' => 'addon-credits-page',
                    ]
                );
            }, 3);

            $this->showConfirm = false;
            $this->selectedProductId = null;
            $this->messageType = 'success';
            $this->message = 'Add-on credits purchased successfully and added to your add-on balance.';

            $this->dispatch('header:refresh');
            $this->dispatch('customerPlanUpdated');
            $this->dispatch('customerStorageUpdated');
        } catch (\Throwable $e) {
            $this->messageType = 'danger';
            $this->message = 'Failed: ' . $e->getMessage();
        } finally {
            $this->processing = false;
        }
    }

    public function render()
    {
        $customer = auth('app')->user()->fresh();

        $customer->loadMissing([
            'wallet',
            'servicePlan',
            'activeServiceSubscription.servicePlan',
        ]);

        $wallet = $customer->wallet()->first();

        $subscriptionBalance = (int) ($wallet?->subscription_balance_credits ?? 0);
        $addonBalance = (int) ($wallet?->addon_balance_credits ?? 0);
        $combinedBalance = $subscriptionBalance + $addonBalance;

        $currentPlan =
            $customer->servicePlan
            ?: $customer->activeServiceSubscription?->servicePlan;

        $planCode = (string) ($currentPlan?->code ?? 'free');
        $planName = (string) ($currentPlan?->name ?? 'Free');

        return view('app.pages.addon-credits.⚡addon-credits', [
            'combinedBalance' => $combinedBalance,
            'subscriptionBalance' => $subscriptionBalance,
            'addonBalance' => $addonBalance,
            'planCode' => $planCode,
            'planName' => $planName,
        ]);
    }
};
?>

<div>
    <div class="row justify-content-center mt-4">
        <div class="col-lg-8">
            <div class="text-center mb-4 pb-2">
                <h4 class="fs-22">Add-on Credits</h4>
                <p class="text-muted mb-2 fs-15">
                    Current plan: <b>{{ strtoupper($planCode) }}</b> — {{ $planName }}
                </p>

                <div class="d-flex justify-content-center flex-wrap gap-3 small">
                    <div>
                        <span class="text-muted">Combined Balance:</span>
                        <b>{{ number_format($combinedBalance) }}</b>
                    </div>
                    <div>
                        <span class="text-muted">Subscription Credits:</span>
                        <b>{{ number_format($subscriptionBalance) }}</b>
                    </div>
                    <div>
                        <span class="text-muted">Add-on Credits:</span>
                        <b>{{ number_format($addonBalance) }}</b>
                    </div>
                </div>

                <div class="alert alert-info mt-3 mb-0 text-start">
                    <div><b>How it works:</b></div>
                    <div>Add-on credits are one-time top-ups.</div>
                    <div>They are kept separately from your monthly subscription credits.</div>
                    <div>When spending credits, subscription credits are used first, then add-on credits.</div>
                    <div>Changing your service plan does not remove your add-on credits.</div>
                </div>

                @if($message)
                    <div class="alert alert-{{ $messageType }} mt-3 mb-0">{{ $message }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="row justify-content-center">
        @forelse($products as $p)
            <div class="col-lg-4 col-md-6">
                <div class="card pricing-box">
                    <div class="card-body p-4 m-2">
                        <div class="d-flex align-items-start">
                            <div class="flex-grow-1">
                                <h5 class="mb-1">{{ $p['name'] }}</h5>
                                <p class="text-muted mb-1">{{ strtoupper($p['code']) }}</p>
                                <span class="badge bg-soft-primary text-primary">Add-on Pack</span>
                            </div>

                            <div class="ms-auto text-end">
                                <div class="fw-semibold">{{ number_format($p['credits_amount']) }} credits</div>
                                <div class="text-muted fs-12">${{ number_format($p['price_usd'], 2) }}</div>
                            </div>
                        </div>

                        <hr class="my-4 text-muted">

                        <ul class="list-unstyled text-muted vstack gap-2 mb-0">
                            <li class="d-flex">
                                <div class="flex-shrink-0 text-success me-1">
                                    <i class="ri-checkbox-circle-fill fs-15 align-middle"></i>
                                </div>
                                <div class="flex-grow-1">
                                    One-time credit top-up
                                </div>
                            </li>

                            <li class="d-flex">
                                <div class="flex-shrink-0 text-success me-1">
                                    <i class="ri-checkbox-circle-fill fs-15 align-middle"></i>
                                </div>
                                <div class="flex-grow-1">
                                    Preserved when switching plans
                                </div>
                            </li>

                            <li class="d-flex">
                                <div class="flex-shrink-0 text-success me-1">
                                    <i class="ri-checkbox-circle-fill fs-15 align-middle"></i>
                                </div>
                                <div class="flex-grow-1">
                                    Used after subscription credits
                                </div>
                            </li>
                        </ul>

                        <div class="mt-4">
                            <button class="btn btn-primary w-100"
                                    wire:click="openConfirm({{ $p['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="openConfirm({{ $p['id'] }})">
                                Buy Add-on (Fake Pay)
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-lg-8">
                <div class="alert alert-warning mb-0">
                    No active add-on credit products found.
                </div>
            </div>
        @endforelse
    </div>

    @if($showConfirm)
        @php
            $selected = collect($products)->firstWhere('id', $selectedProductId);
        @endphp

        <div class="modal fade show d-block" tabindex="-1" style="background: rgba(0,0,0,.5)">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Confirm Add-on Purchase</h5>
                        <button type="button"
                                class="btn-close"
                                wire:click="closeConfirm"
                                @disabled($processing)></button>
                    </div>

                    <div class="modal-body">
                        <div class="alert alert-warning mb-3">
                            This is a <b>fake payment</b> for testing.
                        </div>

                        @if($selected)
                            <p class="mb-2">
                                Product:
                                <b>{{ $selected['name'] }}</b>
                            </p>
                            <p class="mb-2">
                                Credits:
                                <b>{{ number_format($selected['credits_amount']) }}</b>
                            </p>
                            <p class="mb-2">
                                Price:
                                <b>${{ number_format($selected['price_usd'], 2) }}</b>
                            </p>
                        @endif

                        <div class="small text-muted">
                            These credits will be added to your <b>add-on credit bucket</b>, not your monthly subscription bucket.
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button class="btn btn-light"
                                wire:click="closeConfirm"
                                @disabled($processing)">
                            Cancel
                        </button>

                        <button class="btn btn-primary"
                                wire:click="confirmPurchase"
                                wire:loading.attr="disabled"
                                wire:target="confirmPurchase"
                                @disabled($processing)">
                            <span wire:loading.remove wire:target="confirmPurchase">
                                Confirm Purchase
                            </span>
                            <span wire:loading wire:target="confirmPurchase">
                                Processing...
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>