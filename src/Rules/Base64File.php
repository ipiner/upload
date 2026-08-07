<?php

declare(strict_types=1);

namespace Pin\Upload\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

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
        $file = new \Pin\Upload\Base64File($value, $attribute);

        if ($this->allowMimeTypes && ! in_array($file->getMimeType(), $this->allowMimeTypes)) {
            $fail(':attribute格式不正确');
        }
    }
}
