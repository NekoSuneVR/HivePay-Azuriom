@extends('admin.layouts.admin')

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
    <label class="form-label" for="rpcInput">{{ trans('hivepay::messages.rpc') }}</label>
    <select class="form-control @error('rpc') is-invalid @enderror"
            id="rpcInput" name="rpc" required>
        @php
            $rpcNodes = [
                'https://api.hive.blog' => 'api.hive.blog',
                'https://api.deathwing.me' => 'api.deathwing.me',
                'https://rpc.ecency.com' => 'rpc.ecency.com',
            ];
        @endphp
        @foreach($rpcNodes as $url => $label)
            <option value="{{ $url }}" {{ old('rpc', $gateway->data['rpc'] ?? '') === $url ? 'selected' : '' }}>
                {{ $label }}
            </option>
        @endforeach
    </select>

    @error('rpc')
    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
    @enderror
</div>

<div class="mb-3 col-md-6">
    <label class="form-label" for="expiresInput">{{ trans('hivepay::messages.expires') ?? 'Payment Expiry (minutes)' }}</label>
    <input type="number" min="5" step="5"
           id="expiresInput"
           class="form-control @error('expires') is-invalid @enderror"
           name="expires"
           value="{{ old('expires', $gateway->data['expires'] ?? 60) }}">

    @error('expires')
    <span class="invalid-feedback" role="alert"><strong>{{ $message }}</strong></span>
    @enderror
</div>
