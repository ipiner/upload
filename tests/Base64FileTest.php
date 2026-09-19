<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Pin\Upload\Base64File;

it('stores base64 file into request attributes', function () {
    new Base64File(trim(file_get_contents(__DIR__.'/resources/base64')), 'avatar');

    expect(app()->request->attributes->get('base64file.avatar'))
        ->toBeInstanceOf(Base64File::class);
});

it('decodes the file content and detects its actual MIME type', function () {
    $content = trim(file_get_contents(__DIR__.'/resources/base64'));
    $file = new Base64File($content);

    expect($file->getContent())->toBe(base64_decode(explode(',', $content, 2)[1], true))
        ->and($file->getMimeType())->toBe('image/png');
});

it('supports data URI parameters without trusting the declared type', function () {
    $file = new Base64File('data:image/png;charset=utf-8;base64,'.base64_encode('hello world'));

    expect($file->getContent())->toBe('hello world')
        ->and($file->getMimeType())->toBe('text/plain');
});

it('rejects malformed or empty base64 files', function (string $content) {
    expect(fn () => new Base64File($content))->toThrow(InvalidArgumentException::class);
})->with([
    '',
    'hello',
    'data:image/png;base64',
    'data:image/png,SGVsbG8=',
    'data:image/png;base64,',
    'data:image/png;base64,invalid content!',
    'data:image/png;base64,SGVsbG8=,extra',
]);

it('removes the temporary file when the object is released', function () {
    $file = new Base64File('data:text/plain;base64,'.base64_encode('hello world'));
    $pathname = $file->getPathname();

    expect(is_file($pathname))->toBeTrue();
    unset($file);
    clearstatcache(true, $pathname);
    expect(is_file($pathname))->toBeFalse();
});

it('keeps a moved file after releasing the temporary resource', function () {
    $disk = Storage::fake('base64');
    $file = new Base64File('data:text/plain;base64,'.base64_encode('hello world'));
    $moved = $file->move($disk->path(''), 'hello.txt');
    unset($file);

    expect($moved->getContent())->toBe('hello world');
    $disk->assertExists('hello.txt');
});
