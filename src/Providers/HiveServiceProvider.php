<?php

namespace Azuriom\Plugin\HivePay\Providers;

use Azuriom\Extensions\Plugin\BasePluginServiceProvider;
use Azuriom\Plugin\HivePay\HiveMethod;

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

        // 👇 Add this line so your routes/web.php is loaded
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        payment_manager()->registerPaymentMethod('hivepay', HiveMethod::class);
    }
}
