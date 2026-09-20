<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Pin\Upload\Errors;
use Pin\Upload\Rules\Upload;
use Pin\Upload\UploadedFile as ValidatedFile;

beforeEach(function () {
    $this->upload = $this->invoker(new Upload()->max('5M'));
    $this->upload->file = UploadedFile::fake()->image('test.png');
});

it('validates upload successfully', function () {
    $errors = [];

    $this->upload->validate(
        'file',
        $this->upload->file,
        function ($message) use (&$errors) {
            $errors[] = $message;
        }
    );

    expect($errors)->toBe([]);
});

it('fails when extension is not allowed (with code)', function () {
    $errors = [];

    (new Upload())
        ->extensions('php')
        ->validate(
            'file',
            $this->upload->file,
            function ($message) use (&$errors) {
                $errors[] = $message;
            }
        );

    expect($errors[0])->toBe(
        Errors::UploadExtensionInvalid->code().'|'.Errors::UploadExtensionInvalid->message()
    );
});

it('fails when extension is not allowed (message only)', function () {
    $errors = [];

    (new Upload(false))
        ->extensions('php')
        ->validate(
            'file',
            $this->upload->file,
            function ($message) use (&$errors) {
                $errors[] = $message;
            }
        );

    expect($errors[0])->toBe(Errors::UploadExtensionInvalid->message());
});

it('validates min size', function () {
    expect($this->upload->validateMin())->toBe(0);

    $this->upload->min(PHP_INT_MAX);
    expect($this->upload->validateMin())
        ->toBe(Errors::UploadSizeTooSmall->code());
});

it('validates max size', function () {
    expect($this->upload->validateMax())->toBe(0);

    $this->upload->max(1);
    expect($this->upload->validateMax())
        ->toBe(Errors::UploadSizeTooLarge->code());
});

it('validates extension', function () {
    expect($this->upload->validateExtension())->toBe(0);

    $this->upload->file = UploadedFile::fake()->create('test.php');
    expect($this->upload->validateExtension())
        ->toBe(Errors::UploadExtensionInvalid->code());
});

it('validates mime type', function () {
    expect($this->upload->validateMimeType())->toBe(0);

    $this->upload->extensions([]);
    expect($this->upload->validateMimeType())
        ->toBe(Errors::UploadMimeTypeInvalid->code());
});

it('sets disk config', function () {
    expect($this->upload->config['disk'])->toBeNull();

    $this->upload->disk('test');
    expect($this->upload->config['disk'])->toBe('test');
});

it('does not retain validation errors between files', function () {
    $rule = new Upload();
    $invalid = UploadedFile::fake()->create('test.txt', 1, 'text/plain');
    $valid = UploadedFile::fake()->image('test.png');
    $validator = Validator::make(['first' => $invalid, 'second' => $valid], [
        'first' => $rule,
        'second' => $rule,
    ]);

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->has('first'))->toBeTrue()
        ->and($validator->errors()->has('second'))->toBeFalse()
        ->and(ValidatedFile::item($invalid)->errors)->not->toBeEmpty()
        ->and(ValidatedFile::item($valid)->errors)->toBe([]);
});

it('accepts inclusive size limits and treats zero as unlimited', function () {
    $file = UploadedFile::fake()->image('test.png')->size(1);

    $bounded = Validator::make(['file' => $file], ['file' => new Upload()->min(1024)->max(1024)]);
    $unlimited = Validator::make(['file' => $file], ['file' => new Upload()->min(0)->max(0)]);

    expect($bounded->passes())->toBeTrue()
        ->and($unlimited->passes())->toBeTrue();
});

it('rejects negative size limits', function (string $method) {
    expect(fn () => (new Upload())->$method(-1))->toThrow(InvalidArgumentException::class);
})->with(['min', 'max']);

it('normalizes extension settings and accepts equivalent JPEG extensions', function () {
    $file = UploadedFile::fake()->image('test.jpg');
    $rule = new Upload()->extensions(' JPEG ,jpeg, ');

    expect(Validator::make(['file' => $file], ['file' => $rule])->passes())->toBeTrue()
        ->and(ValidatedFile::item($file)->uploadConfig['extensions'])->toBe(['jpeg']);
});

it('replaces the MIME allowlist when extensions change', function () {
    $rule = new Upload()->extensions('png')->extensions(['txt']);
    $file = UploadedFile::fake()->image('test.png');

    expect(Validator::make(['file' => $file], ['file' => $rule])->fails())->toBeTrue()
        ->and(ValidatedFile::item($file)->errors)->toHaveKeys([
            Errors::UploadExtensionInvalid->code(),
            Errors::UploadMimeTypeInvalid->code(),
        ]);
});

it('rejects non-file input without throwing a type error', function (mixed $value) {
    $errors = [];
    (new Upload())->validate('file', $value, function (string $message) use (&$errors) {
        $errors[] = $message;
    });

    expect($errors)->toBe([Errors::UploadInvalid->code().'|'.Errors::UploadInvalid->message()])
        ->and(ValidatedFile::items())->toBe([]);
})->with([null, false, 123, 'not a file', ['array']]);

it('rejects failed PHP uploads before reading a missing temporary file', function () {
    $file = new UploadedFile('/missing/upload', 'photo.png', 'image/png', UPLOAD_ERR_PARTIAL, true);
    $errors = [];
    (new Upload(false))->validate('file', $file, function (string $message) use (&$errors) {
        $errors[] = $message;
    });

    expect($errors)->toBe([Errors::UploadInvalid->message()])
        ->and(ValidatedFile::items())->toBe([]);
});

it('detects the actual content instead of trusting the client filename and MIME type', function () {
    $source = UploadedFile::fake()->createWithContent('source.txt', 'hello world');
    $file = new UploadedFile($source->getPathname(), 'photo.png', 'image/png', null, true);

    expect(Validator::make(['file' => $file], ['file' => new Upload()])->fails())->toBeTrue()
        ->and(ValidatedFile::item($file)->mime_type)->toBe('text/plain');
});

it('reuses an unknown MIME result across content checks', function () {
    $source = UploadedFile::fake()->createWithContent('test.bin', 'unknown');
    $file = Mockery::mock(UploadedFile::class, [
        $source->getPathname(), 'test.bin', 'application/octet-stream', null, true,
    ])->makePartial();
    $file->shouldReceive('getMimeType')->twice()->andReturnNull();

    expect(Validator::make(['file' => $file], ['file' => new Upload()])->fails())->toBeTrue()
        ->and(ValidatedFile::item($file)->mime_type)->toBeNull();
});

it('uses the new MIME allowlist when reusing a rule', function () {
    $rule = new Upload()->extensions('png');
    $image = UploadedFile::fake()->image('test.png');
    $text = UploadedFile::fake()->createWithContent('test.txt', 'hello world');

    expect(Validator::make(['file' => $image], ['file' => $rule])->passes())->toBeTrue();

    $rule->extensions('txt');

    expect(Validator::make(['file' => $text], ['file' => $rule])->passes())->toBeTrue()
        ->and(Validator::make(['file' => $image], ['file' => $rule])->fails())->toBeTrue();
});
