<?php

declare(strict_types=1);

namespace Pin\Upload;

use Override;
use Pin\Errors\Registry;
use Pin\Support\ServiceProvider;

/**
 * 上传服务提供者
 */
class UploadServiceProvider extends ServiceProvider
{
    /**
     * 注册上传配置。
     */
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/upload.php', 'pin.upload');
    }

    /**
     * 注册上传错误。
     */
    public function boot(): void
    {
        Registry::register(Errors::cases());
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'pin-upload');
        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/pin-upload'),
        ], 'pin-upload-errors');
    }
}
