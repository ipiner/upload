<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Pin\Upload\Errors;
use Pin\Upload\Rules\Upload;

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

    $this->upload->max(PHP_INT_MIN);
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
    expect($this->upload->validateExtension())->toBe(0);

    $this->upload->config = array_merge($this->upload->config, [
        'mimetypes' => [],
    ]);
    expect($this->upload->validateMimeType())
        ->toBe(Errors::UploadMimeTypeInvalid->code());
});

it('sets disk config', function () {
    expect($this->upload->config['disk'])->toBeNull();

    $this->upload->disk('test');
    expect($this->upload->config['disk'])->toBe('test');
});
