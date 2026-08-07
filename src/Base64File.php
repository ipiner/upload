<?php

declare(strict_types=1);

namespace Pin\Upload;

use Symfony\Component\HttpFoundation\File\File;

/**
 * Base64File
 *
 * 将 Base64 编码的数据转换为 Symfony File 对象，
 * 以便在 Laravel / Symfony 文件处理流程中像普通上传文件一样使用。
 */
class Base64File extends File
{
    /**
     * 构造函数
     *
     * @param  string  $base64Content  Base64 内容（格式：data:image/png;base64,...）
     * @param  string|null  $name  可选字段名，用于绑定到 request（方便后续获取）
     */
    public function __construct(string $base64Content, ?string $name = null)
    {
        parent::__construct(
            // 创建临时文件并写入内容
            $this->getTempFile(
                $this->getFileContentFromBase64($base64Content)
            )
        );

        if ($name) {
            app()->request->attributes->set('base64file.'.$name, $this);
        }
    }

    /**
     * 从 Base64 字符串中提取文件内容
     *
     * @return string 解码前的 Base64 数据
     */
    protected function getFileContentFromBase64(string $content): string
    {
        $arr = explode(';', $content);

        return explode(',', $arr[1])[1];
    }

    /**
     * 创建临时文件并写入内容
     *
     * @param  string  $content  文件内容（Base64 字符串）
     */
    protected function getTempFile(string $content): string
    {
        $filename = tempnam(sys_get_temp_dir(), uniqid('', true));
        file_put_contents($filename, $content);

        return $filename;
    }
}
