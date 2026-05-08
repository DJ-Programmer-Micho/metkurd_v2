@extends('law.clean')
@section('law')
<style>
    body {
        background-color: #ffffff;
        max-width: 100%!important;
        padding: 0!important;
    }
    li {
        padding-top: 6pt!important;
        padding-bottom: 6pt!important;
    }
</style>
<section class="price_plan_area section_padding_130_80 bg my-5" id="pricing">
    <div class="container bg-white p-sm-4 p-4" style="border: solid #7c5cff 2px; border-radius: 10px;">
        <div class="d-flex flex-column  align-items-center gap-3 mb-4">
            <img src="{{ app('logo_1024_tran') }}" alt="metkurd.ai" width="120">
            <h1 class="h3 mb-0">MetKurd AI</h1>
        </div>
    <hr class="underline_Logo" style="height: 5px; background-color: #7c5cff">
        {!! $terms !!}
    </div>
</section>
@endsection
