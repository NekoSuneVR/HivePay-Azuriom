<div class="row g-3">
    <div class="mb-3 col-md-6">
        <label class="form-label" for="accountInput">{{ trans('hivepay::messages.account') }}</label>
        <input type="text" class="form-control @error('account') is-invalid @enderror"
               id="accountInput" name="account"
               value="{{ old('account', $gateway->data['account'] ?? '') }}" required>

        @error('account')
        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
        @enderror
    </div>

    <div class="mb-3 col-md-6">
        <label class="form-label" for="currencyInput">{{ trans('hivepay::messages.currency') }}</label>
        <select class="form-control @error('currency') is-invalid @enderror"
                id="currencyInput" name="currency" required>
            <option value="HIVE" {{ old('currency', $gateway->data['currency'] ?? '') === 'HIVE' ? 'selected' : '' }}>HIVE</option>
            <option value="HBD" {{ old('currency', $gateway->data['currency'] ?? '') === 'HBD' ? 'selected' : '' }}>HBD</option>
        </select>

        @error('currency')
        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
        @enderror
    </div>

    <div class="mb-3 col-md-12">
        <label class="form-label" for="rpcInput">{{ trans('hivepay::messages.rpc') }}</label>
        <input type="text" class="form-control @error('rpc') is-invalid @enderror"
               id="rpcInput" name="rpc"
               value="{{ old('rpc', $gateway->data['rpc'] ?? 'https://api.hive.blog') }}" required>

        @error('rpc')
        <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
        @enderror
    </div>
</div>

<div class="alert alert-info">
    <p>
        <i class="bi bi-info-circle"></i>
        @lang('hivepay::messages.setup', [
            'notification' => '<code>'.route('shop.payments.notification', 'hivepay').'</code>'
        ])
    </p>
    <p>
        {{ trans('hivepay::messages.memo') }}:
        <code>{{ trans('hivepay::messages.memo_format', ['id' => '{payment_id}']) }}</code>
    </p>
</div>
