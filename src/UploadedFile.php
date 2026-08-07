<?php

namespace Pin\Upload;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Pin\Errors\Translator;
use Symfony\Component\HttpFoundation\File\File;

/**
 * UploadedFile
 *
 * 上传文件统一封装类（增强版），基于 Laravel UploadedFile 扩展
 *
 * @property string $pathname 文件绝对路径
 * @property string $path 相对路径（基于 disk）
 * @property string $name 文件名
 * @property string $file_id 文件uuid
 * @property int $size 文件大小（字节）
 * @property int|null $width 图片宽度
 * @property int|null $height 图片高度
 * @property string $extension 扩展名
 * @property string $mime_type MIME 类型
 * @property array $original 客户端原始信息
 * @property array|null $thumb 缩略图信息
 * @property array|null $water 水印信息
 * @property string|null $disk 存储磁盘
 * @property array $errors 验证错误
 */
class UploadedFile extends Fluent
{
    /**
     * 原始 UploadedFile 对象
     */
    public File $file;

    /**
     * 构造函数
     */
    public function __construct(\Illuminate\Http\UploadedFile $file, array $errors, public array $uploadConfig)
    {
        $this->file = $file;

        parent::__construct([
            'file_id' => Str::uuid()->toString(),

            // 文件路径信息
            'pathname' => $file->getPathname(),
            'path' => $this->path(),
            'name' => $file->getFilename(),

            // 文件基本属性
            'extension' => $file->extension() ?: $file->clientExtension(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),

            // 客户端信息
            'original' => [
                'name' => $file->getClientOriginalName(),
                'extension' => $file->getClientOriginalExtension(),
                'mime_type' => $file->getClientMimeType(),
            ],

            // 存储磁盘
            'disk' => $this->uploadConfig['disk'] ?? null,

            // 验证错误
            'errors' => $errors,
        ]);

        // 如果是图片，自动获取尺寸
        if ($this->isImage()) {
            [$this->width, $this->height] = getimagesize($this->pathname) ?: [0, 0];
        }
    }

    /**
     * 获取已验证的文件
     *
     * @return static|null
     */
    public static function item(\Illuminate\Http\UploadedFile|string $hash)
    {
        $items = static::items();
        $hash = is_string($hash) ? $hash : spl_object_hash($hash);

        return $items[$hash] ?? null;
    }

    /**
     * 获取当前请求中所有已验证的文件
     *
     * @return static[]
     */
    public static function items(): array
    {
        return app()->request->attributes->get('uploaded-files', []);
    }

    /**
     * 标记文件为已验证，并存入 request
     */
    public static function validated(
        \Illuminate\Http\UploadedFile $file,
        array $errors,
        array $uploadConfig
    ): void {
        $items = app()->request->attributes->get('uploaded-files', []);

        $items[spl_object_hash($file)] = app(
            UploadedFile::class,
            compact('file', 'errors', 'uploadConfig')
        );

        app()->request->attributes->set('uploaded-files', $items);
    }

    /**
     * 获取存储磁盘实例
     */
    public function disk(?string $default = null): Filesystem
    {
        return Storage::disk($this->uploadConfig['disk'] ?? $default);
    }

    /**
     * 获取上传错误信息
     *
     * @param  array  $replace  错误信息占位符替换参数（如 ['attribute' => '']）
     */
    public function getErrors(array $replace = []): ?array
    {
        if (empty($this->errors)) {
            return null;
        }

        $replace = array_merge(['attribute' => ''], $replace);

        return Arr::map($this->errors, fn ($s) => Translator::trans($s, $replace));
    }

    /**
     * 是否为图片
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    /**
     * 移动文件（物理移动）
     *
     * @param  string  $path  相对路径
     * @param  string|null  $name  文件名
     */
    public function move(string $path, ?string $name = null): ?File
    {
        $name = $name ?: $this->hashName();

        $file = $this->file->move(
            $this->disk()->path($path),
            $name
        );

        $this->moved($file);

        return $file;
    }

    /**
     * 存储文件（Laravel Storage）
     */
    public function storeAs(string $path, ?string $name = null, array|string $options = []): false|null|string
    {
        $name = $name ?: $this->hashName();

        $path = $this->file->storeAs(
            $path,
            $name,
            $this->parseOptions($options)
        );

        $this->stored($path);

        return $path;
    }

    /**
     * 生成缩略图
     *
     * @param  bool  $replace  是否覆盖原图
     * @param  int|string|null  $width  宽度或配置 key
     * @param  int|null  $height  高度
     * @param  string|null  $source  源文件路径
     */
    public function thumb(
        bool $replace = false,
        int|string|null $width = 50,
        ?int $height = null,
        ?string $source = null
    ): void {
        // 支持配置 key
        $key = $width.$height;

        if (is_string($width)) {
            $key = $width;
            $height = config('pin.upload.thumb.'.$width.'.height');
            $width = config('pin.upload.thumb.'.$width.'.width');
        }

        $pathname = $replace ? $this->pathname : $this->thumbSaveTo($key);

        $thumb = ImageManager::gd()
            ->read($source ?: $this->pathname)
            ->scaleDown($width, $height)
            ->save($pathname, 100);

        $filesize = filesize($pathname);
        $size = $thumb->size();

        if ($replace) {
            // 覆盖原图
            $this->attributes['original']['size'] = $this->size;
            $this->attributes['original']['width'] = $this->width;
            $this->attributes['original']['height'] = $this->height;

            $this->size = $filesize;
            $this->width = $size->width();
            $this->height = $size->height();
        } else {
            // 保存为缩略图
            $this->attributes['thumb'][$key] = [
                'pathname' => $pathname,
                'path' => $this->path($pathname),
                'name' => basename($pathname),
                'size' => $filesize,
                'width' => $size->width(),
                'height' => $size->height(),
            ];
        }
    }

    /**
     * 获取文件访问 URL
     */
    public function url(?string $path = null, ?string $disk = null): string
    {
        return $this->disk($disk)->url($path ?: $this->path);
    }

    /**
     * 添加水印
     *
     * @param  string|ImageInterface  $image  水印图片
     * @param  string  $position  位置（如 bottom-right）
     * @param  int  $x  偏移 X
     * @param  int  $y  偏移 Y
     * @param  int  $opacity  透明度
     * @param  bool  $replace  是否覆盖原图
     */
    public function water(
        string|ImageInterface $image,
        string $position = 'bottom-right',
        int $x = 0,
        int $y = 0,
        int $opacity = 20,
        bool $replace = true,
    ): void {
        $pathname = $replace ? $this->pathname : $this->waterSaveTo();

        ImageManager::gd()
            ->read($this->pathname)
            ->place($image, $position, $x, $y, $opacity)
            ->save($pathname, 100);

        $filesize = filesize($pathname);

        if ($replace) {
            $this->attributes['original']['size'] = $this->size;
            $this->size = $filesize;
        } else {
            $this->water = [
                'pathname' => $pathname,
                'path' => $this->path($pathname),
                'name' => basename($pathname),
                'size' => $filesize,
            ];
        }
    }

    /**
     * 生成文件名（基于 ID）
     */
    protected function hashName(): string
    {
        return $this->file_id.'.'.$this->extension;
    }

    /**
     * 更新移动后的文件信息
     */
    protected function moved(File $file): void
    {
        $this->file = $file;
        $this->pathname = $file->getPathname();
        $this->path = $this->path();
        $this->name = $file->getFilename();
    }

    /**
     * 解析存储选项
     */
    protected function parseOptions(array|string $options): array
    {
        if (is_string($options)) {
            $options = ['disk' => $options];
        }

        return array_merge(
            ['disk' => $this->uploadConfig['disk'] ?? null],
            $options
        );
    }

    /**
     * 获取相对路径（基于 disk）
     */
    protected function path(?string $pathname = null): string
    {
        return str_replace(
            str_replace('\\', '/', $this->disk()->path('/')),
            '',
            str_replace('\\', '/', $pathname ?: $this->file->getPathname()),
        );
    }

    /**
     * 更新存储后的文件信息
     */
    protected function stored(string $path): void
    {
        $this->pathname = $this->disk()->path($path);
        $this->path = $path;
        $this->name = basename($path);
    }

    /**
     * 缩略图保存路径
     */
    protected function thumbSaveTo(string $key): string
    {
        return dirname($this->pathname).'/thumb_'.$key.'_'.$this->name;
    }

    /**
     * 水印文件保存路径
     */
    protected function waterSaveTo(): string
    {
        return dirname($this->pathname).'/water_'.$this->name;
    }
}
