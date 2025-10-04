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
        // create Azuriom Payment row
        $payment = $this->createPayment($cart, $amount, $currency);

        // generate unique memo token (short)
        $memo = strtoupper(Str::substr(Str::uuid()->toString(), 0, 12));

        // store memo & expected amount in the payment meta so we can verify later
        $payment->meta = array_merge($payment->meta ?? [], [
            'hive_memo' => $memo,
            'expected_amount' => number_format($amount, 3, '.', ''), // keep precision
            'expected_currency' => strtoupper($currency), // usually HBD
            'created_at' => Carbon::now()->toIso8601String(),
        ]);
        $payment->save();

        // Read configured settings
        $recvAccount = $this->gateway->data['receive-account'] ?? null;
        $nodeUrl = $this->gateway->data['rpc-node'] ?? 'https://api.hive.blog';
        $expiresMinutes = intval($this->gateway->data['expires-minutes'] ?? 60);

        if (!$recvAccount) {
            return response('Receiving Hive account not configured in payment gateway.', 500);
        }

        // Build instructions view route (you can create a blade view that uses $data)
        // We'll redirect to a small internal instruction page that shows memo and a "I've paid" check button
        return view('hivepay::hive.instructions', [
            'payment' => $payment,
            'memo' => $memo,
            'amount' => $payment->meta['expected_amount'],
            'currency' => $payment->meta['expected_currency'],
            'recvAccount' => $recvAccount,
            'nodeUrl' => $nodeUrl,
            'expires_at' => Carbon::now()->addMinutes($expiresMinutes),
            'check_url' => route('shop.payments.notify', 'hivepay'), // re-use notification route to check
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
        $meta = $payment->meta ?? [];
        $memo = $meta['hive_memo'] ?? null;
        $expectedAmount = $meta['expected_amount'] ?? null; // string
        $expectedCurrency = $meta['expected_currency'] ?? 'HBD';

        if (!$memo || !$expectedAmount) {
            throw new \Exception('Payment missing hive metadata (memo/amount).');
        }

        $recvAccount = $this->gateway->data['receive-account'] ?? null;
        if (!$recvAccount) {
            throw new \Exception('Receiving Hive account not configured.');
        }

        $nodeUrl = rtrim($this->gateway->data['rpc-node'] ?? 'https://api.hive.blog', '/');

        // We'll use account_history_api.get_account_history which supports querying history with pagination.
        // Request: POST to nodeUrl with JSON-RPC body:
        // {"jsonrpc":"2.0","method":"account_history_api.get_account_history","params":["<account>", -1, 1000], "id":1}
        //
        // Then filter for transfer ops. Limit is 1000 per call; for larger work you must paginate.
        $limit = 1000;
        $start = -1; // -1 to request latest according to docs (then you get last `limit` ops)

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

        // Some nodes return 'result' with array of ops; others embed differently — be defensive.
        $ops = $result['result'] ?? $result['result']['history'] ?? $result['result']['history'] ?? null;
        if ($ops === null && isset($result['result']) && is_array($result['result'])) {
            // Newer account_history_api returns an array of objects like [{id, op, ...}, ...]
            $ops = $result['result'];
        }

        if (!is_array($ops)) {
            // As fallback, try condenser_api.get_account_history method
            $body2 = [
                'jsonrpc' => '2.0',
                'method' => 'condenser_api.get_account_history',
                'params' => [$recvAccount, -1, $limit],
                'id' => 2,
            ];
            $resp2 = Http::timeout(15)->post($nodeUrl, $body2);
            if ($resp2->ok()) {
                $r2 = $resp2->json();
                $ops = $r2['result'] ?? [];
            } else {
                throw new \Exception('RPC returned unexpected response while attempting fallback.');
            }
        }

        // ops elements may be arrays [index, [op_name, op_data]] or objects.
        foreach ($ops as $entry) {
            // Normalize op tuple
            // Two common shapes:
            // 1) [ index, [ "transfer", { from, to, amount, memo } ] ]
            // 2) { "op": ["transfer", {...}], "trx_id": "...", "timestamp": "...", ... }
            $opName = null;
            $opData = null;
            $trxId = null;
            $timestamp = null;

            if (is_array($entry) && count($entry) >= 2 && is_array($entry[1])) {
                $opName = $entry[1][0] ?? null;
                $opData = $entry[1][1] ?? null;
                // sometimes trx id at entry[1]['trx_id'] but uncertain
                $trxId = $entry[2] ?? null;
            } elseif (is_array($entry) && isset($entry['op'])) {
                $opName = $entry['op'][0] ?? null;
                $opData = $entry['op'][1] ?? null;
                $trxId = $entry['trx_id'] ?? ($entry['trx_id'] ?? null);
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
            $amountStr = $opData['amount'] ?? ($opData->amount ?? null); // e.g. "12.345 HBD"
            $memoField = $opData['memo'] ?? ($opData->memo ?? null);
            $from = $opData['from'] ?? ($opData->from ?? null);

            if (!$to || !$amountStr) {
                continue;
            }

            // check recipient
            if (strtolower($to) !== strtolower($recvAccount)) {
                continue;
            }

            // check memo contains our token (exact match or contains)
            if (!$memoField || strpos($memoField, $memo) === false) {
                continue;
            }

            // parse amount & currency
            // "12.345 HBD"
            $parts = preg_split('/\s+/', trim($amountStr));
            if (count($parts) < 2) {
                continue;
            }

            $amt = floatval($parts[0]);
            $cur = strtoupper($parts[1]);

            // require currency match (e.g., HBD)
            if ($cur !== strtoupper($expectedCurrency)) {
                continue;
            }

            // compare amounts: allow small rounding tolerance (0.0001)
            $expectedFloat = floatval($expectedAmount);
            if (abs($amt - $expectedFloat) > 0.0005) {
                // if amount mismatch, you might still accept if >= expected; adjust policy
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
            'receive-account' => ['required', 'string'],
            'rpc-node' => ['required', 'string'],
            'desc' => ['nullable', 'string'],
            'color' => ['required', 'int'],
            'expires-minutes' => ['nullable', 'int'],
        ];
    }

    public function image()
    {
        // supply your plugin assets for Hive/HBD
        return asset('plugins/hivepay/img/hive.svg');
    }
}
