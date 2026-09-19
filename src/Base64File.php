<?php

declare(strict_types=1);

namespace Pin\Upload;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\File;

/**
 * 将 Base64 Data URI 解码为临时文件，供文件验证和存储使用。
 */
class Base64File extends File
{
    /** @var resource|null 随对象释放，自动删除尚未移动的临时文件 */
    protected mixed $tempFile = null;

    /**
     * @param  string  $base64Content  Base64 内容（格式：data:image/png;base64,...）
     * @param  string|null  $name  可选字段名，用于绑定到 request（方便后续获取）
     */
    public function __construct(string $base64Content, ?string $name = null)
    {
        parent::__construct(
            $this->getTempFile(
                $this->getFileContentFromBase64($base64Content)
            )
        );

        if ($name !== null && $name !== '') {
            app()->request->attributes->set('base64file.'.$name, $this);
        }
    }

    /**
     * 提取并严格解码 Data URI，不信任头部声明的 MIME 类型。
     *
     * @throws InvalidArgumentException
     */
    protected function getFileContentFromBase64(string $content): string
    {
        $parts = explode(',', $content, 2);

        if (count($parts) !== 2 || ! preg_match('/\Adata:[^,\r\n]*;base64\z/i', $parts[0])) {
            throw new InvalidArgumentException('Invalid Base64 data URI.');
        }

        $decoded = base64_decode($parts[1], true);

        if ($decoded === false || $decoded === '') {
            throw new InvalidArgumentException('Invalid or empty Base64 file content.');
        }

        return $decoded;
    }

    /**
     * 创建临时文件并写入内容
     *
     * @param  string  $content  解码后的文件内容
     */
    protected function getTempFile(string $content): string
    {
        $this->tempFile = tmpfile();

        if ($this->tempFile === false) {
            throw new FileException('Unable to create a temporary upload file.');
        }

        if (fwrite($this->tempFile, $content) !== strlen($content)) {
            throw new FileException('Unable to write the temporary upload file.');
        }

        return stream_get_meta_data($this->tempFile)['uri'];
    }
}
