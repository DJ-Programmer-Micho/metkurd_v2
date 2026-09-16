<div>
    @if($paginator->hasPages())
        <nav class="v2-account-actions" aria-label="{{ __('account_v2.pages') }}">
            <button type="button" class="btn btn-outline-info btn-sm" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled($paginator->onFirstPage())>{{ __('account_v2.previous') }}</button>
            <small aria-live="polite">{{ __('account_v2.page_count', ['page' => $paginator->currentPage(), 'total' => $paginator->lastPage()]) }}</small>
            <button type="button" class="btn btn-outline-info btn-sm" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" @disabled(!$paginator->hasMorePages())>{{ __('account_v2.next') }}</button>
        </nav>
    @endif
</div>
