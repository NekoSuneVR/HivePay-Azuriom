<?php

use Illuminate\Support\Facades\Route;
use Azuriom\Plugin\HivePay\HiveMethod;

Route::post('/hivepay/notify/{payment?}', [HiveMethod::class, 'notification'])
    ->name('hivepay.notify');
