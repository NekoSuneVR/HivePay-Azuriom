@extends('layouts.app')

@section('title', 'Choose Payment Currency')

@section('content')
<div class="container text-center my-5">
    <h2>Select Currency</h2>
    <p>You will pay <strong>{{ $amount }}</strong> in your chosen currency.</p>

    <form method="POST" action="{{ $form_action }}">
        @csrf
        <input type="hidden" name="amount" value="{{ $amount }}">
        <div class="my-3">
            @foreach($currencies as $cur)
                <button type="submit" name="pay_currency" value="{{ $cur }}" class="btn btn-outline-primary m-2">
                    Pay with {{ $cur }}
                </button>
            @endforeach
        </div>
    </form>
</div>
@endsection
