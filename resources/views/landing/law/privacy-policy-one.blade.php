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
    <section class="price_plan_area section_padding_130_80 bg mb-5" id="pricing">
        <div class="container bg-white p-sm-4 p-4" style="border: solid #003465 2px; border-radius: 10px;">
            {!! $terms !!}
        </div>
</section>
@endsection