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
 * 上传文件。
 *
 * @property string $pathname 本地源文件绝对路径
 * @property string $path 相对路径（基于 disk）
 * @property string $name 文件名
 * @property string $file_id 文件 UUID
 * @property int $size 文件大小（字节）
 * @property int|null $width 图片宽度
 * @property int|null $height 图片高度
 * @property string $extension 扩展名
 * @property string|null $mime_type MIME 类型
 * @property array $original 客户端原始信息
 * @property array|null $thumb 缩略图信息
 * @property array|null $water 水印信息
 * @property string|null $disk 存储磁盘
 * @property array<int, string> $errors 验证错误
 */
class UploadedFile extends Fluent
{
    /**
     * 本地文件。
     */
    public File $file;

    /**
     * @param  array<int, string>  $errors  验证错误
     */
    public function __construct(HttpUploadedFile $file, array $errors, public array $uploadConfig)
    {
        $this->file = $file;
        $mimeType = $file->getMimeType();

        parent::__construct([
            'file_id' => Str::uuid()->toString(),

            'pathname' => $file->getPathname(),
            'path' => $this->path(),
            'name' => $file->getFilename(),

            'extension' => MimeTypes::getDefault()->getExtensions($mimeType ?? '')[0]
                ?? $file->clientExtension() ?? '',
            'size' => $file->getSize(),
            'mime_type' => $mimeType,

            'original' => [
                'name' => $file->getClientOriginalName(),
                'extension' => $file->getClientOriginalExtension(),
                'mime_type' => $file->getClientMimeType(),
            ],

            'disk' => $this->uploadConfig['disk'] ?? null,
            'errors' => $errors,
        ]);

        if ($this->isImage()) {
            [$this->width, $this->height] = @getimagesize($this->pathname) ?: [0, 0];
        }
    }

    /**
     * 获取上传验证结果。
     */
    public static function item(HttpUploadedFile|string $hash): ?static
    {
        $hash = is_string($hash) ? $hash : spl_object_hash($hash);

        return static::items()[$hash] ?? null;
    }

    /**
     * 获取当前请求的上传验证结果。
     *
     * @return array<string, static>
     */
    public static function items(): array
    {
        return app()->request->attributes->get('uploaded-files', []);
    }

    /**
     * 记录上传验证结果。
     *
     * @param  array<int, string>  $errors
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
     * @param  array  $replace  占位符替换
     * @return array<int, string>|null
     */
    public function getErrors(array $replace = []): ?array
    {
        if (! $this->errors) {
            return null;
        }

        $replace = array_merge(['attribute' => ''], $replace);

        return Arr::map(
            $this->errors,
            static fn (string $message): string => Translator::trans($message, $replace)
        );
    }

    /**
     * 是否为图片
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }

    /**
     * 移动本地文件。
     *
     * @param  string  $path  相对路径
     * @param  string|null  $name  文件名
     */
    public function move(string $path, ?string $name = null): File
    {
        $name = $name ?: $this->hashName();
        $disk = $this->disk();

        if (! $this->isLocalDisk($disk)) {
            throw new LogicException('Moving uploaded files requires a local disk.');
        }

        $path = (new WhitespacePathNormalizer())->normalizePath($path);
        $file = $this->file->move($disk->path($path), $name);

        $this->moved($file);

        return $file;
    }

    /**
     * 存储文件。
     */
    public function storeAs(
        string $path,
        ?string $name = null,
        array|string $options = []
    ): string|false {
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
        [$key, $width, $height] = $this->thumbDimensions($width, $height);
        $pathname = $replace ? $this->pathname : $this->thumbSaveTo($key);

        $thumb = $this->imageManager()
            ->read($source ?: $this->pathname)
            ->scaleDown($width, $height)
            ->save($pathname, quality: 100);

        clearstatcache(true, $pathname);
        $fileSize = filesize($pathname);
        $dimensions = $thumb->size();

        if ($replace) {
            $this->rememberOriginal();

            $this->size = $fileSize;
            $this->width = $dimensions->width();
            $this->height = $dimensions->height();

            return;
        }

        $this->attributes['thumb'][$key] = [
            'pathname' => $pathname,
            'path' => $this->path($pathname),
            'name' => basename($pathname),
            'size' => $fileSize,
            'width' => $dimensions->width(),
            'height' => $dimensions->height(),
        ];
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
        $fileSize = filesize($pathname);

        if ($replace) {
            $this->rememberOriginal();
            $this->size = $fileSize;

            return;
        }

        $this->water = [
            'pathname' => $pathname,
            'path' => $this->path($pathname),
            'name' => basename($pathname),
            'size' => $fileSize,
        ];
    }

    /**
     * 生成文件名。
     */
    protected function hashName(): string
    {
        return $this->file_id.($this->extension ? '.'.$this->extension : '');
    }

    /**
     * 获取图片处理器。
     */
    protected function imageManager(): ImageManager
    {
        return ImageManager::gd();
    }

    /**
     * 是否为本地磁盘。
     */
    protected function isLocalDisk(Filesystem $disk): bool
    {
        return $disk instanceof FilesystemAdapter
            && $disk->getAdapter() instanceof LocalFilesystemAdapter;
    }

    /**
     * 保留原图信息。
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

        if (! $this->isLocalDisk($disk)) {
            return;
        }

        $this->file = new File($disk->path($path));
        $this->pathname = $this->file->getPathname();
    }

    /**
     * 写入文件。
     */
    protected function storeFile(
        Filesystem $disk,
        string $path,
        string $name,
        array $options
    ): string|false {
        if (! $this->isLocalDisk($disk)) {
            return $disk->putFileAs($path, $this->file, $name, $options);
        }

        $targetPath = (new WhitespacePathNormalizer())->normalizePath(trim($path.'/'.$name, '/'));
        $target = realpath($disk->path($targetPath));

        if (! $target || $target !== $this->file->getRealPath()) {
            return $disk->putFileAs($path, $this->file, $name, $options) === false
                ? false : $targetPath;
        }

        // 同一文件仅更新权限。
        if (isset($options['visibility'])
            && ! $disk->setVisibility($targetPath, $options['visibility'])) {
            return false;
        }

        return $targetPath;
    }

    /**
     * 解析缩略图尺寸。
     *
     * @return array{string, int|null, int|null}
     */
    protected function thumbDimensions(int|string|null $width, ?int $height): array
    {
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
            throw new InvalidArgumentException(
                'Thumbnail dimensions must be positive integers with at least one dimension set.'
            );
        }

        return [$key, $width, $height];
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
