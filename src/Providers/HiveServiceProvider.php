<?php

namespace Azuriom\Plugin\Crypto\Providers;

use Azuriom\Extensions\Plugin\BasePluginServiceProvider;
use Azuriom\Plugin\Crypto\HiveMethod;

class HiveServiceProvider extends BasePluginServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        $this->loadViews();
        $this->loadTranslations();

        payment_manager()->registerPaymentMethod('hivepay', HiveMethod::class);
    }
}
