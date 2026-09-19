<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Pin\Upload\UploadedFile as PinUploadedFile;

beforeEach(function () {
    config(['filesystems.default' => 'local']);
    Storage::fake('local');
});

it('validates uploaded files and stores them', function () {
    $avatar = UploadedFile::fake()->image(Str::random().'.png');
    $php = UploadedFile::fake()->create(Str::random().'.php');

    PinUploadedFile::validated($avatar, [], []);
    PinUploadedFile::validated($php, [], []);

    expect(PinUploadedFile::items())->toHaveCount(2);

    expect(PinUploadedFile::item($avatar)->name)
        ->toBe($avatar->getFilename());

    expect(PinUploadedFile::item(spl_object_hash($php))->name)
        ->toBe($php->getFilename());

    expect(get_class(PinUploadedFile::item($php)->file))
        ->toBe(File::class);

    expect(PinUploadedFile::item(Str::random()))->toBeNull();
});

it('stores and moves uploaded files', function () {
    $avatar = UploadedFile::fake()->image(Str::random().'.png');

    PinUploadedFile::validated($avatar, [], []);
    $item = PinUploadedFile::item($avatar);

    $oldName = $item->name;

    $item->storeAs(Str::random());
    expect(PinUploadedFile::item($avatar)->name)->not->toBe($oldName);

    $item->move('avatar');

    expect(PinUploadedFile::item($avatar)->name)->not->toBe($oldName);

    expect(get_class($item->file))
        ->toBe(Symfony\Component\HttpFoundation\File\File::class);
});

it('handles thumbnails', function () {
    $item = new PinUploadedFile(
        UploadedFile::fake()->image(Str::random().'.png'),
        [],
        []
    );

    expect($item->original['width'] ?? null)->toBeNull();
    $item->storeAs('images');

    $item->thumb(true);
    expect($item->original['width'])->not->toBeNull();
    expect($item->thumb)->toBeNull();

    $item->thumb(false, 's');
    expect($item->thumb['s'])->not->toBeNull();
});

it('generates url', function () {
    $item = new PinUploadedFile(
        UploadedFile::fake()->image(Str::random().'.png'),
        [],
        []
    );

    expect(str_contains($item->url(), $item->path))->toBeTrue();

    $path = uniqid();
    expect($item->url($path))->toBe(Storage::disk('local')->url($path));
});

it('applies watermark', function () {
    $item = new PinUploadedFile(
        UploadedFile::fake()->image(Str::random().'.png'),
        [],
        []
    );
    $item->storeAs('images');

    $item->water(__DIR__.'/resources/water.png');
    expect($item->water)->toBeNull();

    $item->water(
        image: ImageManager::gd()->read(__DIR__.'/resources/water.png')->rotate(30),
        replace: false
    );

    expect($item->water)->not->toBeNull();
});

it('parses options', function () {
    $item = new PinUploadedFile(
        UploadedFile::fake()->image(Str::random().'.png'),
        [],
        []
    );

    expect($this->invoker($item)->parseOptions([])['disk'])->toBeNull();
    expect($this->invoker($item)->parseOptions('test')['disk'])->toBe('test');
});

it('returns errors', function () {
    $item = new PinUploadedFile(
        UploadedFile::fake()->image(Str::random().'.png'),
        [],
        []
    );
    expect($item->getErrors())->toBeNull();

    $item->errors = [1 => ':attribute后缀不允许'];
    expect(array_first($item->getErrors()))->toBe('后缀不允许');
});

it('keeps the selected disk and file metadata in sync', function () {
    $other = Storage::fake('other');
    $source = UploadedFile::fake()->image('avatar.png');
    $item = new PinUploadedFile($source, [], ['disk' => 'local']);

    expect($item->storeAs('avatars', 'saved.png', 'other'))->toBe('avatars/saved.png')
        ->and($item->disk)->toBe('other')
        ->and($item->uploadConfig['disk'])->toBe('other')
        ->and($item->pathname)->toBe($other->path('avatars/saved.png'))
        ->and($item->file->getPathname())->toBe($item->pathname)
        ->and($item->url())->toBe($other->url('avatars/saved.png'));

    $other->assertExists('avatars/saved.png');
    Storage::disk('local')->assertMissing('avatars/saved.png');
});

it('stores the current file after resizing and moving it', function () {
    $source = UploadedFile::fake()->image('avatar.png', 120, 60);
    $item = new PinUploadedFile($source, [], []);
    $item->storeAs('avatars', 'original.png');
    $item->thumb(true, 60);
    $item->move('moved', 'avatar.png');

    expect($item->storeAs('copies', 'avatar.png'))->toBe('copies/avatar.png')
        ->and(getimagesize($item->pathname)[0])->toBe(60)
        ->and(getimagesize($source->getPathname())[0])->toBe(120);

    Storage::disk('local')->assertMissing('avatars/original.png');
    Storage::disk('local')->assertExists(['moved/avatar.png', 'copies/avatar.png']);
});

it('keeps file contents when storing to the same local path again', function (string $path) {
    $item = new PinUploadedFile(UploadedFile::fake()->image('avatar.png'), [], []);
    $item->storeAs('avatars', 'avatar.png');
    $content = $item->file->getContent();

    expect($item->storeAs($path, 'avatar.png', ['visibility' => 'public']))->toBe('avatars/avatar.png')
        ->and($item->file->getContent())->toBe($content)
        ->and(Storage::disk('local')->getVisibility('avatars/avatar.png'))->toBe('public');
})->with(['avatars', 'avatars/.']);

it('does not change metadata when storage returns false', function () {
    $item = new PinUploadedFile(UploadedFile::fake()->image('avatar.png'), [], ['disk' => 'local']);
    $attributes = $item->toArray();
    $file = $item->file;
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('putFileAs')->once()->with('avatars', $file, 'avatar.png', [])->andReturn(false);
    Storage::shouldReceive('disk')->with('broken')->andReturn($disk);

    expect($item->storeAs('avatars', 'avatar.png', 'broken'))->toBeFalse()
        ->and($item->toArray())->toBe($attributes)
        ->and($item->uploadConfig)->toBe(['disk' => 'local'])
        ->and($item->file)->toBe($file);
});

it('retains the local source after storing to a remote disk', function () {
    $source = UploadedFile::fake()->image('avatar.png');
    $item = new PinUploadedFile($source, [], []);
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('putFileAs')->once()->with('avatars', $source, 'avatar.png', ['visibility' => 'public'])
        ->andReturn('avatars/avatar.png');
    $disk->shouldReceive('getAdapter')->andReturn(Mockery::mock(League\Flysystem\FilesystemAdapter::class));
    $disk->shouldReceive('url')->with('avatars/avatar.png')->andReturn('https://files.example/avatars/avatar.png');
    Storage::shouldReceive('disk')->with('remote')->andReturn($disk);

    expect($item->storeAs('avatars', 'avatar.png', ['disk' => 'remote', 'visibility' => 'public']))->toBe('avatars/avatar.png')
        ->and($item->file)->toBe($source)
        ->and($item->pathname)->toBe($source->getPathname())
        ->and($item->disk)->toBe('remote')
        ->and($item->url())->toBe('https://files.example/avatars/avatar.png');

    expect(fn () => $item->move('moved'))->toThrow(LogicException::class);
});

it('preserves the original metadata across repeated image edits', function () {
    $item = new PinUploadedFile(UploadedFile::fake()->image('avatar.png', 120, 60), [], []);
    $size = $item->size;
    $item->storeAs('images');
    $item->thumb(true, 60);
    $item->thumb(true, 30);
    $item->water(__DIR__.'/resources/water.png');
    clearstatcache(true, $item->pathname);

    expect($item->original['width'])->toBe(120)
        ->and($item->original['height'])->toBe(60)
        ->and($item->original['size'])->toBe($size)
        ->and($item->width)->toBe(30)
        ->and($item->height)->toBe(15)
        ->and($item->size)->toBe(filesize($item->pathname));
});

it('uses distinct keys for different thumbnail dimensions', function () {
    $item = new PinUploadedFile(UploadedFile::fake()->image('avatar.png', 120, 60), [], []);
    $item->storeAs('images');
    $item->thumb(false, 1, 23);
    $item->thumb(false, 12, 3);

    expect($item->thumb)->toHaveKeys(['1x23', '12x3'])
        ->and($item->thumb['1x23']['pathname'])->not->toBe($item->thumb['12x3']['pathname']);

    Storage::disk('local')->assertExists([$item->thumb['1x23']['path'], $item->thumb['12x3']['path']]);
});

it('validates thumbnail dimensions before processing the image', function (int|string|null $width, ?int $height) {
    $item = new PinUploadedFile(UploadedFile::fake()->image('avatar.png'), [], []);

    expect(fn () => $item->thumb(false, $width, $height))->toThrow(InvalidArgumentException::class);
})->with([
    [null, null],
    [0, null],
    [-1, null],
    [50, 0],
    [50, -1],
    ['missing-preset', null],
]);

it('supports height-only thumbnail dimensions', function () {
    $item = new PinUploadedFile(UploadedFile::fake()->image('avatar.png', 120, 60), [], []);
    $item->storeAs('images');
    $item->thumb(false, null, 30);

    expect($item->thumb['x30']['width'])->toBe(60)
        ->and($item->thumb['x30']['height'])->toBe(30);
});

it('handles unreadable image dimensions without warnings', function () {
    $source = UploadedFile::fake()->createWithContent('avatar.png', 'invalid image');
    $item = new PinUploadedFile($source, [], []);

    expect($item->isImage())->toBeTrue()
        ->and($item->width)->toBe(0)
        ->and($item->height)->toBe(0);
});

it('removes the disk root only from the beginning of the path', function () {
    $item = new PinUploadedFile(UploadedFile::fake()->image('avatar.png'), [], []);
    $root = Storage::disk('local')->path('/');
    $pathname = $root.'nested'.$root.'avatar.png';

    expect($this->invoker($item)->path($pathname))->toBe('nested'.$root.'avatar.png');
});
