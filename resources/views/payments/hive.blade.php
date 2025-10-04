@extends('shop::layouts.app')

@section('title', 'Pay with Hive')

@section('content')
<div class="container text-center my-5">

    <h2>Pay with {{ $currency }}</h2>
    <p>Please send <strong>{{ $amount }} {{ $currency }}</strong> to:</p>
    <h3><code>{{ $account }}</code></h3>
    <p>Memo: <code>{{ $memo }}</code></p>

    {{-- QR Code for mobile wallets --}}
    <div class="my-3">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data={{ urlencode("hive://transfer?to=$account&amount=$amount%20$currency&memo=$memo") }}"
             alt="QR Code" class="img-fluid">
    </div>

    {{-- Hive Keychain Button --}}
    <button id="keychainBtn" class="btn btn-primary m-2">
        <i class="bi bi-box-arrow-in-right"></i> Pay with Hive Keychain
    </button>

    {{-- HiveSigner Button --}}
    <a href="https://hivesigner.com/sign/transfer?to={{ $account }}&amount={{ $amount }}%20{{ $currency }}&memo={{ urlencode($memo) }}"
       target="_blank" class="btn btn-success m-2">
        <i class="bi bi-link-45deg"></i> Pay with HiveSigner
    </a>

</div>
@endsection

@section('scripts')
<script>
document.getElementById('keychainBtn').addEventListener('click', function () {
    if (window.hive_keychain) {
        window.hive_keychain.requestTransfer(
            "{{ auth()->user()->name ?? '' }}", // from user (if logged in with same name as Hive)
            "{{ $account }}",                  // to
            "{{ $amount }}",                   // amount
            "{{ $memo }}",                     // memo
            "{{ $currency }}",                 // HIVE or HBD
            function (response) {
                if (response.success) {
                    alert("Transaction broadcasted! Please wait for confirmation.");
                } else {
                    alert("Keychain transfer failed.");
                }
            },
            true
        );
    } else {
        alert("Hive Keychain extension not found.");
    }
});
</script>
@endsection
