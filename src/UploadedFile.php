<?php

declare(strict_types=1);

namespace Pin\Upload;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Fluent;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\WhitespacePathNormalizer;
use LogicException;
use Pin\Errors\Translator;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Mime\MimeTypes;

/**
 * 封装上传验证结果、存储信息及本地图片处理。
 *
 * @property string $pathname 本地源文件绝对路径
 * @property string $path 相对路径（基于 disk）
 * @property string $name 文件名
 * @property string $file_id 文件uuid
 * @property int $size 文件大小（字节）
 * @property int|null $width 图片宽度
 * @property int|null $height 图片高度
 * @property string $extension 扩展名
 * @property string|null $mime_type MIME 类型
 * @property array $original 客户端原始信息
 * @property array|null $thumb 缩略图信息
 * @property array|null $water 水印信息
 * @property string|null $disk 存储磁盘
 * @property array $errors 验证错误
 */
class UploadedFile extends Fluent
{
    /**
     * 当前本地文件，存储或移动到本地磁盘后同步更新。
     */
    public File $file;

    /**
     * 文件验证错误也会保留，供请求结束时记录上传日志。
     */
    public function __construct(HttpUploadedFile $file, array $errors, public array $uploadConfig)
    {
        $this->file = $file;
        $mimeType = $file->getMimeType();

        parent::__construct([
            'file_id' => Str::uuid()->toString(),

            // 文件路径信息
            'pathname' => $file->getPathname(),
            'path' => $this->path(),
            'name' => $file->getFilename(),

            // 文件基本属性
            'extension' => MimeTypes::getDefault()->getExtensions($mimeType ?? '')[0] ?? $file->clientExtension() ?? '',
            'size' => $file->getSize(),
            'mime_type' => $mimeType,

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
            // 部分 image/* 格式或损坏图片可能无法读取尺寸。
            [$this->width, $this->height] = @getimagesize($this->pathname) ?: [0, 0];
        }
    }

    /**
     * 获取经过验证的文件（包含验证失败的文件）。
     */
    public static function item(HttpUploadedFile|string $hash): ?static
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
        HttpUploadedFile $file,
        array $errors,
        array $uploadConfig
    ): void {
        $items = static::items();

        $items[spl_object_hash($file)] = app(
            static::class,
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

        return Arr::map($this->errors, static fn (string $message): string => Translator::trans($message, $replace));
    }

    /**
     * 是否为图片
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    /**
     * 在本地磁盘上物理移动文件。
     *
     * @param  string  $path  相对路径
     * @param  string|null  $name  文件名
     */
    public function move(string $path, ?string $name = null): ?File
    {
        $name = $name ?: $this->hashName();
        $disk = $this->disk();

        if (! $this->isLocalDisk($disk)) {
            throw new LogicException('Moving uploaded files requires a local disk.');
        }

        $file = $this->file->move($disk->path($path), $name);

        $this->moved($file);

        return $file;
    }

    /**
     * 流式存储当前文件，失败时保留原有文件信息。
     */
    public function storeAs(string $path, ?string $name = null, array|string $options = []): false|null|string
    {
        $name = $name ?: $this->hashName();
        $options = $this->parseOptions($options);
        $diskName = Arr::pull($options, 'disk') ?? Storage::getDefaultDriver();

        $path = $this->storeFile(Storage::disk($diskName), $path, $name, $options);

        if ($path === false) {
            return false;
        }

        $this->uploadConfig['disk'] = $diskName;
        $this->disk = $diskName;
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
        $key = (string) $width.($height === null ? '' : 'x'.$height);

        if (is_string($width)) {
            $key = $width;
            $dimensions = config('pin.upload.thumb.'.$key);

            if (! is_array($dimensions) || strpbrk($key, "/\\\0") !== false) {
                throw new InvalidArgumentException('Unknown or invalid thumbnail preset: '.$key);
            }

            $width = $dimensions['width'] ?? null;
            $height = $dimensions['height'] ?? null;
        }

        if (($width === null && $height === null)
            || ($width !== null && (! is_int($width) || $width <= 0))
            || ($height !== null && (! is_int($height) || $height <= 0))) {
            throw new InvalidArgumentException('Thumbnail dimensions must be positive integers with at least one dimension set.');
        }

        $pathname = $replace ? $this->pathname : $this->thumbSaveTo($key);

        $thumb = $this->imageManager()
            ->read($source ?: $this->pathname)
            ->scaleDown($width, $height)
            ->save($pathname, quality: 100);

        clearstatcache(true, $pathname);
        $filesize = filesize($pathname);
        $size = $thumb->size();

        if ($replace) {
            // 覆盖原图
            $this->rememberOriginal();

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

        $this->imageManager()
            ->read($this->pathname)
            ->place($image, $position, $x, $y, $opacity)
            ->save($pathname, quality: 100);

        clearstatcache(true, $pathname);
        $filesize = filesize($pathname);

        if ($replace) {
            $this->rememberOriginal();
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
        return $this->file_id.($this->extension ? '.'.$this->extension : '');
    }

    /**
     * 可在子类中替换为 Imagick 等图像驱动。
     */
    protected function imageManager(): ImageManager
    {
        return ImageManager::gd();
    }

    /**
     * 判断磁盘是否使用本地文件系统。
     */
    protected function isLocalDisk(Filesystem $disk): bool
    {
        return $disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter;
    }

    /**
     * 多次处理图片时，保留首次处理前的信息。
     */
    protected function rememberOriginal(): void
    {
        $this->attributes['original'] += [
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
        ];
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
        $root = rtrim(str_replace('\\', '/', $this->disk()->path('/')), '/').'/';
        $pathname = str_replace('\\', '/', $pathname ?? $this->file->getPathname());

        return str_starts_with($pathname, $root) ? substr($pathname, strlen($root)) : $pathname;
    }

    /**
     * 更新存储后的文件信息
     */
    protected function stored(string $path): void
    {
        $this->path = $path;
        $this->name = basename($path);
        $disk = $this->disk();

        // 远程存储保留本地源文件。
        if (! $this->isLocalDisk($disk)) {
            return;
        }

        // 本地存储切换到新副本，后续图片处理和移动使用同一文件。
        $this->file = new File($disk->path($path));
        $this->pathname = $this->file->getPathname();
    }

    /**
     * 同一本地文件无需再次复制，避免读写同一路径时清空源文件。
     */
    protected function storeFile(Filesystem $disk, string $path, string $name, array $options): string|false
    {
        if (! $this->isLocalDisk($disk)) {
            return $disk->putFileAs($path, $this->file, $name, $options);
        }

        $targetPath = (new WhitespacePathNormalizer())->normalizePath(trim($path.'/'.$name, '/'));
        $target = realpath($disk->path($targetPath));

        if ($target === false || $target !== $this->file->getRealPath()) {
            return $disk->putFileAs($path, $this->file, $name, $options);
        }

        if (isset($options['visibility']) && ! $disk->setVisibility($targetPath, $options['visibility'])) {
            return false;
        }

        return $targetPath;
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
