<?php

declare(strict_types=1);

use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Pin\Upload\UploadedFile as PinUploadedFile;

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
    expect(str_contains($path, $path))->toBeTrue();
});

it('applies watermark', function () {
    $item = new PinUploadedFile(
        UploadedFile::fake()->image(Str::random().'.png'),
        [],
        []
    );

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
