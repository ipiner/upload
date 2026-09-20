<?php

declare(strict_types=1);

namespace Pin\Upload\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Override;
use Pin\Errors\IError;
use Pin\Support\Size;
use Pin\Upload\Errors;
use Pin\Upload\UploadedFile as ValidatedFile;
use Symfony\Component\Mime\MimeTypes;

/**
 * 文件上传验证规则。
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
        'disk' => null,                        // 存储磁盘
        'min' => 0,                            // 最小文件大小（字节）
        'max' => '5M',                         // 最大文件大小
        'extensions' => 'jpg,jpeg,gif,png,webp', // 允许扩展名
    ];

    /**
     * 当前上传文件实例
     */
    protected UploadedFile $file;

    /**
     * @var array{}|array{size: int, mime_type: string|null} 文件信息
     */
    protected array $fileInfo = [];

    /**
     * @var list<string> 允许的 MIME 类型
     */
    protected array $allowedMimeTypes = [];

    /**
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
     *
     * @param  string|array<string>  $extensions
     */
    public function extensions(string|array $extensions): static
    {
        if (! is_array($extensions)) {
            $extensions = explode(',', $extensions);
        }

        $this->config['extensions'] = array_values(array_unique(array_filter(
            array_map(
                static fn (string $extension): string => strtolower(trim($extension)),
                $extensions
            ),
            static fn (string $extension): bool => $extension !== ''
        )));
        $this->config['mimetypes'] = [];

        $mimeTypes = MimeTypes::getDefault();

        foreach ($this->config['extensions'] as $extension) {
            $this->config['mimetypes'][$extension] = $mimeTypes->getMimeTypes($extension);
        }

        $this->allowedMimeTypes = array_values(
            array_unique(Arr::flatten($this->config['mimetypes']))
        );

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
     * 验证上传文件。
     */
    #[Override]
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

    /**
     * 报告验证错误。
     */
    protected function fail(Closure $fail): void
    {
        foreach ($this->errors as $code => $message) {
            $fail($this->failWithCode ? $code.'|'.$message : $message);
        }
    }

    /**
     * 获取文件 MIME 类型。
     */
    protected function fileMimeType(): ?string
    {
        return $this->fileInfo ? $this->fileInfo['mime_type'] : $this->file->getMimeType();
    }

    /**
     * 换算文件大小。
     */
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
    protected function addError(IError $error, array $replace): int
    {
        $code = $error->code();
        $this->errors[$code] = $error->message($replace);

        return $code;
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

        $this->validateMin();
        $this->validateMax();
        $this->validateExtension();
        $this->validateMimeType();

        return $this->errors === [];
    }

    /**
     * 忽略大小写匹配。
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
        $extensions = MimeTypes::getDefault()->getExtensions($this->fileMimeType() ?? '');

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
        $mimeType = $this->fileMimeType();

        if ($this->inArray($mimeType, $this->allowedMimeTypes)) {
            return 0;
        }

        return $this->addError(
            Errors::UploadMimeTypeInvalid,
            [
                'value' => $mimeType,
                'name' => $this->file->getClientOriginalName(),
                'mimetypes' => implode('、', $this->allowedMimeTypes),
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
