<?php

declare(strict_types=1);

namespace Pin\Upload\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Pin\Errors\IError;
use Pin\Support\Size;
use Pin\Upload\Errors;
use Pin\Upload\UploadedFile as ValidatedFile;
use Symfony\Component\Mime\MimeTypes;

/**
 * Upload 文件上传验证规则
 */
class Upload implements ValidationRule
{
    /**
     * @var array<int, string> 当前文件的验证错误
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
     * 当前验证期间复用文件信息，避免反复探测 MIME 和读取文件大小。
     */
    protected array $fileInfo = [];

    /**
     * 构造函数
     *
     * @param  bool  $failWithCode  是否返回错误码（code|message）
     */
    public function __construct(protected bool $failWithCode = true)
    {
        $this->max($this->config['max']);
        $this->min($this->config['min']);
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

        $this->config['extensions'] = array_values(array_unique(array_filter(
            array_map(static fn (string $extension): string => strtolower(trim($extension)), $extensions),
            static fn (string $extension): bool => $extension !== ''
        )));
        $this->config['mimetypes'] = [];

        $mimeTypes = MimeTypes::getDefault();

        foreach ($this->config['extensions'] as $extension) {
            $this->config['mimetypes'][$extension] = $mimeTypes->getMimeTypes($extension);
        }

        return $this;
    }

    /**
     * 设置最大文件大小
     */
    public function max(int|string $max): static
    {
        $this->config['max'] = $this->sizeInBytes($max);

        return $this;
    }

    /**
     * 设置最小文件大小
     */
    public function min(int|string $min): static
    {
        $this->config['min'] = $this->sizeInBytes($min);

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
        $this->errors = [];
        $this->fileInfo = [];

        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $this->addError(Errors::UploadInvalid, []);
            $this->fail($fail);

            return;
        }

        $this->file = $value;

        if (! $this->check()) {
            $this->fail($fail);
        }

        ValidatedFile::validated($this->file, $this->errors, $this->config);
    }

    protected function fail(Closure $fail): void
    {
        foreach ($this->errors as $code => $message) {
            $fail($this->failWithCode ? $code.'|'.$message : $message);
        }
    }

    protected function sizeInBytes(int|string $size): int
    {
        $bytes = is_int($size) ? $size : Size::toBytes($size);

        if ($bytes < 0) {
            throw new InvalidArgumentException('Upload size limits must not be negative.');
        }

        return $bytes;
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
        $this->fileInfo = [
            'size' => $this->file->getSize(),
            'mime_type' => $this->file->getMimeType(),
        ];

        // 收集全部错误，供请求验证和上传日志共用。
        $this->validateMin();
        $this->validateMax();
        $this->validateExtension();
        $this->validateMimeType();

        return $this->errors === [];
    }

    /**
     * 扩展名和 MIME 配置已归一化，只需转换待比较的值。
     */
    protected function inArray(?string $needle, array $haystack): bool
    {
        return $needle !== null && in_array(strtolower($needle), $haystack, true);
    }

    /**
     * 校验扩展名
     */
    protected function validateExtension(): int
    {
        $mimeType = $this->fileInfo['mime_type'] ?? $this->file->getMimeType();
        $extensions = MimeTypes::getDefault()->getExtensions($mimeType ?? '');

        // 根据实际内容判断，并兼容 jpg/jpeg 等等价扩展名。
        if (array_intersect($extensions, $this->config['extensions'])) {
            return 0;
        }

        return $this->addError(
            Errors::UploadExtensionInvalid,
            [
                'value' => $extensions[0] ?? '',
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
        $size = $this->fileInfo['size'] ?? $this->file->getSize();

        if ($this->config['max'] === 0 || $size <= $this->config['max']) {
            return 0;
        }

        return $this->addError(
            Errors::UploadSizeTooLarge,
            [
                'value' => Size::format($size),
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
        $mimeType = $this->fileInfo['mime_type'] ?? $this->file->getMimeType();
        $allowedMimeTypes = array_values(array_unique(Arr::flatten($this->config['mimetypes'])));

        if ($this->inArray($mimeType, $allowedMimeTypes)) {
            return 0;
        }

        return $this->addError(
            Errors::UploadMimeTypeInvalid,
            [
                'value' => $mimeType,
                'name' => $this->file->getClientOriginalName(),
                'mimetypes' => implode('、', $allowedMimeTypes),
            ]
        );
    }

    /**
     * 校验最小文件大小
     */
    protected function validateMin(): int
    {
        $size = $this->fileInfo['size'] ?? $this->file->getSize();

        if ($this->config['min'] === 0 || $size >= $this->config['min']) {
            return 0;
        }

        return $this->addError(
            Errors::UploadSizeTooSmall,
            [
                'value' => Size::format($size),
                'min' => Size::format($this->config['min']),
                'name' => $this->file->getClientOriginalName(),
            ]
        );
    }
}
