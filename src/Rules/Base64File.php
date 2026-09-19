<?php

declare(strict_types=1);

namespace Pin\Upload\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Pin\Upload\Base64File as DecodedFile;

/**
 * Base64File 文件验证规则
 */
class Base64File implements ValidationRule
{
    public function __construct(protected readonly array $allowMimeTypes = [])
    {
    }

    /**
     * 执行验证
     *
     * @param  string  $attribute  字段名
     * @param  mixed  $value  Base64 字符串
     * @param  Closure  $fail  验证失败回调
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $attributes = app()->request->attributes;
        $key = 'base64file.'.$attribute;
        $attributes->remove($key);

        if (! is_string($value)) {
            $fail(__('pin-upload::upload.base64_file_invalid'));

            return;
        }

        try {
            $file = new DecodedFile($value);
        } catch (InvalidArgumentException) {
            $fail(__('pin-upload::upload.base64_file_invalid'));

            return;
        }

        if ($this->allowMimeTypes && ! in_array($file->getMimeType(), $this->allowMimeTypes, true)) {
            $fail(__('pin-upload::upload.base64_file_invalid'));

            return;
        }

        $attributes->set($key, $file);
    }
}
