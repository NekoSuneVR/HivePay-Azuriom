@extends('layouts.app')

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
    <div class="my-2" id="keychainWrapper">
        <button id="keychainBtn" class="btn btn-primary m-2">
            <i class="bi bi-box-arrow-in-right"></i> Pay with Hive Keychain
        </button>
        <input type="text" id="hiveUsername" class="form-control mt-2" placeholder="Enter Hive username" style="display:none;">
    </div>

    {{-- HiveSigner Button --}}
    <a href="https://hivesigner.com/sign/transfer?to={{ $account }}&amount={{ $amount }}%20{{ $currency }}&memo={{ urlencode($memo) }}"
       target="_blank" class="btn btn-success m-2">
        <i class="bi bi-link-45deg"></i> Pay with HiveSigner
    </a>
</div>
@endsection

<script>
// Check if Hive Keychain is installed
const keychainBtn = document.getElementById('keychainBtn');
const hiveUsernameInput = document.getElementById('hiveUsername');

if (!window.hive_keychain) {
    alert("Hive Keychain not detected. You must enter your Hive username manually.");
    hiveUsernameInput.style.display = 'block';
} else {
    // Optional: try to pre-fill username if user is logged in
    // Hive Keychain cannot automatically detect logged-in username without requestPermission
    hiveUsernameInput.style.display = 'none';
}

keychainBtn.addEventListener('click', function () {
    let username = hiveUsernameInput.value || "{{ auth()->user()->name ?? '' }}";

    if (!username) {
        alert("Please enter your Hive username to continue.");
        return;
    }

    if (!window.hive_keychain) {
        alert("Hive Keychain extension not found. Use HiveSigner or QR code.");
        return;
    }

    window.hive_keychain.requestTransfer(
        username,          // from
        "{{ $account }}",  // to
        "{{ $amount }}",   // amount
        "{{ $memo }}",     // memo
        "{{ $currency }}", // HIVE or HBD
        function (response) {
            if (response.success) {
                alert("Transaction broadcasted! Please wait for confirmation.");
            } else {
                alert("Keychain transfer failed.");
            }
        },
        true
    );
});

// --- Auto Polling Payment Verification ---
function checkPayment() {
    fetch("{{ $check_url }}", {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Content-Type': 'application/json'
        }
    })
    .then(r => r.json())
    .then(data => {
        console.log("Check result:", data);
        if (data.status === 'paid') {
            alert("✅ Payment confirmed!");
            window.location.href = "{{ route('shop.home') }}";
        }
    })
    .catch(err => console.error("Check error:", err));
}

// poll every 30 seconds
setInterval(checkPayment, 30000);
</script>
