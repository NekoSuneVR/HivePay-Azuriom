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
    $expectedAmount = $payment->price; // string
    $expectedCurrency = $payment->currency;

    if (!$memo || !$expectedAmount) {
        throw new \Exception('Payment missing hive metadata (memo/amount).');
    }

    $recvAccount = $this->gateway['account'] ?? 'chisfund';
    if (!$recvAccount) {
        throw new \Exception('Receiving Hive account not configured.');
    }

    $nodeUrl = $this->gateway['rpc'] ?? 'https://api.hive.blog';

    $limit = 1000;
    $start = -1; // latest ops

    $body = [
        'jsonrpc' => '2.0',
        'method'  => 'account_history_api.get_account_history',
        'params'  => [$recvAccount, $start, $limit],
        'id'      => 1,
    ];

    $resp = Http::timeout(15)->post($nodeUrl, $body);

    if (!$resp->ok()) {
        throw new \Exception('Failed to query Hive RPC: HTTP ' . $resp->status());
    }

    $result = $resp->json();

    // defensive: find ops array
    $ops = $result['result'] ?? $result['result']['history'] ?? [];
    if (!is_array($ops)) {
        // fallback using condenser API
        $body2 = [
            'jsonrpc' => '2.0',
            'method' => 'condenser_api.get_account_history',
            'params' => [$recvAccount, -1, $limit],
            'id' => 2,
        ];
        $resp2 = Http::timeout(15)->post($nodeUrl, $body2);
        $r2 = $resp2->json();
        $ops = $r2['result'] ?? [];
    }

    \Log::debug('Hive RPC response', [
        'payment_id' => $payment->id,
        'memo' => $memo,
        'expected_amount' => $expectedAmount,
        'expected_currency' => $expectedCurrency,
        'recvAccount' => $recvAccount,
        'sample_ops' => array_slice($ops, 0, 5),
    ]);

    foreach ($ops as $entry) {
        $opName = null;
        $opData = null;
        $trxId = null;
        $timestamp = null;

        // normalize different RPC response formats
        if (is_array($entry) && count($entry) >= 2 && is_array($entry[1])) {
            $opName = $entry[1][0] ?? null;
            $opData = $entry[1][1] ?? null;
            $trxId = $entry[2] ?? null;
        } elseif (is_array($entry) && isset($entry['op'])) {
            $opName = $entry['op'][0] ?? null;
            $opData = $entry['op'][1] ?? null;
            $trxId = $entry['trx_id'] ?? null;
            $timestamp = $entry['timestamp'] ?? null;
        } elseif (is_object($entry)) {
            $arr = (array)$entry;
            if (isset($arr['op'])) {
                $opName = $arr['op'][0] ?? null;
                $opData = $arr['op'][1] ?? null;
                $trxId = $arr['trx_id'] ?? null;
                $timestamp = $arr['timestamp'] ?? null;
            }
        }

        if ($opName !== 'transfer' || !is_array($opData)) {
            continue;
        }

        $to = $opData['to'] ?? ($opData->to ?? null);
        $amountStr = $opData['amount'] ?? ($opData->amount ?? null);
        $memoField = $opData['memo'] ?? ($opData->memo ?? null);
        $from = $opData['from'] ?? ($opData->from ?? null);

        // debug each transfer op
        \Log::debug('Checking transfer op', [
            'to' => $to,
            'from' => $from,
            'amount' => $amountStr,
            'memo' => $memoField,
            'payment_memo' => $memo,
            'expected_amount' => $expectedAmount,
            'expected_currency' => $expectedCurrency,
        ]);

        if (!$to || !$amountStr) {
            continue;
        }

        if (strtolower($to) !== strtolower($recvAccount)) {
            continue;
        }

        if (!$memoField || strpos($memoField, $memo) === false) {
            continue;
        }

        $parts = preg_split('/\s+/', trim($amountStr));
        if (count($parts) < 2) {
            continue;
        }

        $amt = floatval($parts[0]);
        $cur = strtoupper(trim($parts[1]));

        if ($cur !== strtoupper(trim($expectedCurrency))) {
            continue;
        }

        $expectedFloat = floatval($expectedAmount);

        // allow small rounding tolerance or accept slightly higher
        if (abs($amt - $expectedFloat) > 0.001 && $amt < $expectedFloat) {
            continue;
        }

        // Payment verified
        $providerTx = $trxId ?? (is_string($timestamp) ? $timestamp . ':' . $from : null);

        return [
            'ok' => true,
            'provider_tx' => $providerTx,
            'matched_from' => $from,
            'matched_amount' => $amountStr,
            'matched_memo' => $memoField,
        ];
    }

    \Log::debug('No matching transfer found', [
        'payment_id' => $payment->id,
        'recent_ops' => array_slice($ops, 0, 5),
        'memo' => $memo,
        'expected_amount' => $expectedAmount,
        'expected_currency' => $expectedCurrency,
    ]);

    return ['ok' => false, 'reason' => 'no matching transfer found in latest account history'];
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
