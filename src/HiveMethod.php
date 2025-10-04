<?php

namespace Azuriom\Plugin\HivePay;

use Azuriom\Plugin\Shop\Cart\Cart;
use Azuriom\Plugin\Shop\Models\Payment;
use Azuriom\Plugin\Shop\Payment\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Carbon\Carbon;

class HiveMethod extends PaymentMethod
{
    protected $id = 'hivepay';
    protected $name = 'Hive / HBD';

    /**
     * Start payment: create a Payment, generate a unique memo token and
     * present instructions to user (send HBD to account with memo).
     *
     * @param Cart $cart
     * @param float $amount
     * @param string $currency
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\Response
     */
    public function startPayment(Cart $cart, float $amount, string $currency)
{
    // If user hasn't chosen currency yet, show a selection page
    if (request()->missing('pay_currency')) {
        return view('hivepay::hive.choose_currency', [
            'amount' => $amount,
            'currencies' => ['HIVE', 'HBD'],
            'form_action' => route('shop.payments.pay', ['hivepay']),
        ]);
    }

    $chosenCurrency = strtoupper(request()->input('pay_currency'));

    // Map Azuriom "HBD" to API's "hive_dollar"
    $apiId = $chosenCurrency === 'HBD' ? 'hive_dollar' : 'hive';

    // Shop base currency (from Azuriom config)
    $baseCurrency = strtoupper(config('currency.iso') ?? 'USD');

    // --- Fetch price feed from NekoGeko API ---
    $url = "https://api.nekosunevr.co.uk/v5/cryptoapi/nekogeko/prices/{$baseCurrency}?id={$apiId}";

    $feed = Http::timeout(10)->get($url)->json();

    if (!isset($feed['current_price'])) {
        throw new \Exception("Price feed unavailable for {$chosenCurrency}");
    }

    $priceInFiat = (float) $feed['current_price'];
    
    // If store currency is already HIVE or HBD, skip conversion
    $converted = $baseCurrency === $chosenCurrency
        ? number_format($amount, 3, '.', '')
        : number_format($amount / $priceInFiat, 3, '.', '');

    $currencyCode = $chosenCurrency === 'HIVE' ? 'HIV' : 'HBD';
    
    // create Azuriom Payment row
    $payment = $this->createPayment($cart, $converted, $currencyCode);

    $memo = strtoupper(Str::substr(Str::uuid()->toString(), 0, 12));

    $payment->transaction_id = $memo;  // hive memo
    $payment->status = 'pending';
    $payment->save();

    $recvAccount = $this->gateway->data['account'] ?? null;
    $nodeUrl = $this->gateway->data['rpc'] ?? 'https://api.hive.blog';
    $expiresMinutes = intval($this->gateway->data['expires'] ?? 60);

    return view('hivepay::hive.pay', [
        'payment' => $payment,
        'memo' => $memo,
        'amount' => $payment->price,
        'currency' => $chosenCurrency,
        'account' => $recvAccount,
        'nodeUrl' => $nodeUrl,
        'expires_at' => Carbon::now()->addMinutes($expiresMinutes),
        'check_url' => route('hivepay.notify', $payment->id),
    ]);
}
    /**
     * Notification/check endpoint. This will run verification against the Hive RPC.
     *
     * If called by Hive (not typical) it will accept a POST with 'payment_id' param,
     * otherwise Azuriom can call this route to manually check a payment.
     *
     * @param Request $request
     * @param string|null $paymentId
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\Response
     */
    public function notification(Request $request, ?string $paymentId)
    {
        // Determine payment id to check
        $id = $paymentId ?? $request->input('payment_id') ?? $request->input('id');

        if (!$id) {
            return response()->json(['status' => 'missing payment id.'], 400);
        }

        $payment = Payment::find($id);
        if (!$payment) {
            return response()->json(['status' => 'payment not found.'], 404);
        }

        // verification
        try {
            $verified = $this->verifyPaymentOnChain($payment);
        } catch (\Exception $e) {
            // useful for debugging, but you may want to log instead
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }

        if ($verified['ok']) {
            // mark paid using Azuriom helper
            $providerTx = $verified['provider_tx'] ?? null;
            return $this->processPayment($payment, $providerTx);
        }

        return response()->json(['status' => 'not paid', 'reason' => $verified['reason'] ?? 'unknown'], 200);
    }

    /**
     * Verify Payment by scanning account history on Hive node for transfer op
     * that matches:
     *  - op[0] == 'transfer'
     *  - op[1]['to'] == receiveAccount
     *  - op[1]['amount'] matches expected AND ends with expected currency (HBD/HIVE)
     *  - op[1]['memo'] contains the memo token
     *
     * @param Payment $payment
     * @return array ['ok' => bool, 'provider_tx' => string|null, 'reason' => string|null]
     * @throws \Exception
     */
    protected function verifyPaymentOnChain(Payment $payment)
{
    $memo = $payment->transaction_id;
    $expectedAmount = floatval($payment->price); // numeric
    $expectedCurrency = strtoupper($payment->currency); // HIVE or HBD

    if (!$memo || !$expectedAmount) {
        throw new \Exception('Payment missing hive metadata (memo/amount).');
    }

    $recvAccount = $this->gateway['account'] ?? 'chisfund';

    // --- 1. Fetch account info ---
    $accountUrl = "https://api.nekosunevr.co.uk/v5/proxy/hiveapi/address/{$recvAccount}";
    $resp = Http::timeout(15)->get($accountUrl);

    if (!$resp->ok()) {
        throw new \Exception('Failed to query Hive account: HTTP ' . $resp->status());
    }

    $accountData = $resp->json();
    $transactions = $accountData['transactions'] ?? [];

    if (empty($transactions)) {
        return ['ok' => false, 'reason' => 'no transactions found for account'];
    }

    // --- 2. Scan each transaction ---
    foreach ($transactions as $txid) {
        $txUrl = "https://api.nekosunevr.co.uk/v5/proxy/hiveapi/tx/{$recvAccount}/{$txid}";
        $txResp = Http::timeout(15)->get($txUrl);

        if (!$txResp->ok()) {
            \Log::warning("Failed to fetch tx $txid for $recvAccount", ['status' => $txResp->status()]);
            continue;
        }

        $tx = $txResp->json();

        // vout contains outputs to account
        foreach ($tx['vout'] ?? [] as $output) {
            $to = $output['address'] ?? null;
            $amount = floatval($output['value'] ?? 0);
            $txMemo = $output['memo'] ?? null;

            if (!$to || $to !== $recvAccount) continue;
            if (!$txMemo || strpos($txMemo, $memo) === false) continue;

            // Optional: detect HIVE/HBD via $expectedCurrency
            // API returns "value" as float, currency can be inferred from payment currency

            // compare amounts with tolerance
            if (abs($amount - $expectedAmount) > 0.000) {
                continue;
            }

            // ✅ Payment matched
            return [
                'ok' => true,
                'provider_tx' => $tx['txid'] ?? $txid,
                'matched_amount' => $amount,
                'matched_memo' => $txMemo,
            ];
        }
    }

    \Log::debug('No matching transfer found via Nekosunevr API', [
        'payment_id' => $payment->id,
        'memo' => $memo,
        'expected_amount' => $expectedAmount,
        'expected_currency' => $expectedCurrency,
        'recvAccount' => $recvAccount,
        'sample_transactions' => array_slice($transactions, 0, 5),
    ]);

    return ['ok' => false, 'reason' => 'no matching transfer found in transactions'];
}
    
    /**
     * Success redirect after user returns to site (if you want a thank-you page)
     */
    public function success(Request $request)
    {
        return redirect()->route('shop.home')->with('success', trans('messages.status.success'));
    }

    public function view()
    {
        return 'hivepay::admin.hive';
    }

    public function rules()
    {
        return [
            'account' => ['required', 'string'],
            'rpc' => ['required', 'string'],
            'expires' => ['nullable', 'int'],
        ];
    }

    public function image()
    {
        // supply your plugin assets for Hive/HBD
        return asset('plugins/hivepay/img/hive.svg');
    }
}
