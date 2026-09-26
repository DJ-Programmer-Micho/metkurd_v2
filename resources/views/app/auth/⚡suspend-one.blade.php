<?php

use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    public function mount()
    {
        $customer = auth('app')->user();

        if ($customer && $customer->status != 0) {
            $this->redirect(\App\Support\CustomerAppDestination::afterAuthentication());
        }
    }
};

?>

<x-slot:title>{{ __('Account Suspended') }} | {{ __('MET KURD') }}</x-slot:title>

<div>
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6 col-xl-5">
            <div class="card mt-4">
                <div class="card-body p-4 text-center">
                    <script src="https://cdn.lordicon.com/lordicon.js"></script>

                    <lord-icon src="https://cdn.lordicon.com/usownftb.json" trigger="loop" colors="primary:#cc0022,secondary:#66d7ee" style="width:250px;height:250px">
                    </lord-icon>

                    <div class="mt-4 pt-2">
                        <h5>{{ __('Your account is suspended') }}</h5>
                        <p class="text-muted mb-0">{{ __('If you think this is a mistake, please send an email to') }}</p>
                        <a href="mailto:support@metkurd.ai" class="fw-semibold text-primary text-decoration-underline">support@metkurd.ai</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Add your JavaScript here
</script>

<style>
    /* Add your CSS here */
</style>
