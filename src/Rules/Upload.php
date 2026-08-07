<?php

declare(strict_types=1);

namespace Pin\Upload\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Pin\Errors\IError;
use Pin\Support\Size;
use Pin\Upload\Errors;
use Symfony\Component\Mime\MimeTypes;

/**
 * Upload 文件上传验证规则
 */
class Upload implements ValidationRule
{
    /**
     * 错误集合
     * [code => message]
     */
    protected array $errors = [];

    /**
     * 配置项
     */
    protected array $config = [
        'disk' => null,                 // 存储磁盘
        'min' => 0,                    // 最小文件大小（字节）
        'max' => '5M',                 // 最大文件大小
        'extensions' => 'jpg,jpeg,gif,png,webp', // 允许扩展名
    ];

    /**
     * 当前上传文件实例
     */
    protected UploadedFile $file;

    /**
     * 构造函数
     *
     * @param  bool  $failWithCode  是否返回错误码（code|message）
     */
    public function __construct(protected bool $failWithCode = true)
    {
        $this->max($this->config['max']);
        $this->extensions($this->config['extensions']);
    }

    /**
     * 设置存储磁盘
     */
    public function disk(string $disk): static
    {
        $this->config['disk'] = $disk;

        return $this;
    }

    /**
     * 设置允许的扩展名
     */
    public function extensions(string|array $extensions): static
    {
        if (! is_array($extensions)) {
            $extensions = explode(',', $extensions);
        }

        $this->config['extensions'] = $extensions;

        // 根据扩展名生成 MIME 类型映射
        foreach ($this->config['extensions'] as $ext) {
            $this->config['mimetypes'][$ext] = MimeTypes::getDefault()->getMimeTypes($ext);
        }

        return $this;
    }

    /**
     * 设置最大文件大小
     */
    public function max(int|string $max): static
    {
        $this->config['max'] = is_int($max) ? $max : Size::toBytes($max);

        return $this;
    }

    /**
     * 设置最小文件大小
     */
    public function min(int|string $min): static
    {
        $this->config['min'] = is_int($min) ? $min : Size::toBytes($min);

        return $this;
    }

    /**
     * 执行验证
     *
     * @param  string  $attribute  字段名
     * @param  mixed  $value  上传文件
     * @param  Closure  $fail  失败回调
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $this->file = $value;

        if (! $this->check()) {
            foreach ($this->errors as $code => $message) {
                $fail($this->failWithCode ? $code.'|'.$message : $message);
            }
        }

        \Pin\Upload\UploadedFile::validated($this->file, $this->errors, $this->config);
    }

    /**
     * 添加错误信息
     *
     * @param  array  $replace  占位符替换
     * @return int 错误码
     */
    protected function addError(IError $err, array $replace): int
    {
        $this->errors[$err->code()] = $err->message($replace);

        return $err->code();
    }

    /**
     * 执行所有校验
     */
    protected function check(): bool
    {
        return empty(array_filter([
            $this->validateMin(),
            $this->validateMax(),
            $this->validateExtension(),
            $this->validateMimeType(),
        ]));
    }

    /**
     * 不区分大小写的 in_array 判断
     */
    protected function inArray(?string $needle, array $haystack): bool
    {
        return $needle && in_array(strtoupper($needle), array_map('strtoupper', $haystack));
    }

    /**
     * 校验扩展名
     */
    protected function validateExtension(): int
    {
        if ($this->inArray($this->file->extension(), $this->config['extensions'])) {
            return 0;
        }

        return $this->addError(
            Errors::UploadExtensionInvalid,
            [
                'value' => $this->file->extension(),
                'name' => $this->file->getClientOriginalName(),
                'extensions' => implode('、', $this->config['extensions']),
            ]
        );
    }

    /**
     * 校验最大文件大小
     */
    protected function validateMax(): int
    {
        if ($this->config['max'] == 0 || $this->file->getSize() <= $this->config['max']) {
            return 0;
        }

        return $this->addError(
            Errors::UploadSizeTooLarge,
            [
                'value' => Size::format($this->file->getSize()),
                'max' => Size::format($this->config['max']),
                'name' => $this->file->getClientOriginalName(),
            ]
        );
    }

    /**
     * 校验 MIME 类型
     */
    protected function validateMimeType(): int
    {
        $mimetype = $this->file->getMimeType();
        $allows = $this->config['mimetypes'][$this->file->extension()] ?? [];

        if ($this->inArray($mimetype, $allows)) {
            return 0;
        }

        return $this->addError(
            Errors::UploadMimeTypeInvalid,
            [
                'value' => $mimetype,
                'name' => $this->file->getClientOriginalName(),
                'mimetypes' => implode('、', Arr::flatten($allows ?: $this->config['mimetypes'])),
            ]
        );
    }

    /**
     * 校验最小文件大小
     */
    protected function validateMin(): int
    {
        if ($this->config['min'] == 0 || $this->file->getSize() > $this->config['min']) {
            return 0;
        }

        return $this->addError(
            Errors::UploadSizeTooSmall,
            [
                'value' => Size::format($this->file->getSize()),
                'min' => Size::format($this->config['min']),
                'name' => $this->file->getClientOriginalName(),
            ]
        );
    }
}
