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
     * 在其他服务启动前加载默认配置。
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/upload.php', 'pin.upload');
    }

    public function boot(): void
    {
        Registry::register(Errors::cases());
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'pin-upload');
        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/pin-upload'),
        ], 'pin-upload-errors');
    }
}
