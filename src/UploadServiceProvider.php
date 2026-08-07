<?php

declare(strict_types=1);

namespace Pin\Upload;

use Pin\Errors\Registry;
use Pin\Support\ServiceProvider;

/**
 * 上传服务提供者
 */
class UploadServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {
        Registry::register(Errors::cases());
        $this->mergeConfigFrom(__DIR__.'/../config/upload.php', 'pin.upload');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'pin-upload');
        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/pin'),
        ], 'pin-upload-errors');
    }
}
