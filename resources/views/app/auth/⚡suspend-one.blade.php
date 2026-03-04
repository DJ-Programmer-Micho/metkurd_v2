<?php

use Livewire\Component;
use Livewire\Attributes\Layout;

new #[Layout('app::layouts.app-auth')] class extends Component
{
    public function mount(){
        $customer = auth('app')->user();
        if($customer && $customer->status != 0) {
            $this->redirectRoute('app.home');  
        }
    }
};
?>

<div>
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6 col-xl-5">
            <div class="card mt-4">
                <div class="card-body p-4 text-center">
                    <script src="https://cdn.lordicon.com/lordicon.js"></script>

                    <lord-icon src="https://cdn.lordicon.com/usownftb.json" trigger="loop" colors="primary:#cc0022,secondary:#66d7ee" style="width:250px;height:250px">
                    </lord-icon>

                    <div class="mt-4 pt-2">
                        <h5>Your account is Suspended</h5>
                        <p class="text-muted mb-0">If you think this is a mistake, please send an Email to</p>
                        <a href="mailto:support@metkurd.com" class="fw-semibold text-primary text-decoration-underline">support@metkurd.com</a>
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