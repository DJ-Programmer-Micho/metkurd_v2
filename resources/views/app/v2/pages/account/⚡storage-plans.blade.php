<?php
use Livewire\Attributes\Layout;
new #[Layout('app::v2.layouts.app')] class extends \App\Livewire\Account\PurchasePage {
    protected string $kind = 'storage';
};
?>
@include('app.v2.pages.account.purchase')
