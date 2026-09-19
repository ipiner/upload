<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Pin\Upload\Base64File as DecodedFile;
use Pin\Upload\Rules\Base64File;

it('validates the decoded content and registers accepted files', function () {
    $content = trim(file_get_contents(__DIR__.'/resources/base64'));
    $validator = Validator::make(['avatar' => $content], ['avatar' => new Base64File(['image/png'])]);

    expect($validator->passes())->toBeTrue()
        ->and(app()->request->attributes->get('base64file.avatar'))->toBeInstanceOf(DecodedFile::class);
});

it('rejects invalid values through the validation callback', function (mixed $value) {
    $errors = [];
    (new Base64File())->validate('avatar', $value, function (string $message) use (&$errors) {
        $errors[] = $message;
    });

    expect($errors)->toHaveCount(1)
        ->and(app()->request->attributes->has('base64file.avatar'))->toBeFalse();
})->with([null, false, 123, ['array'], '', 'invalid', 'data:image/png;base64,invalid!']);

it('uses the actual MIME type and removes a previous result on failure', function () {
    $rule = new Base64File(['image/png']);
    $content = trim(file_get_contents(__DIR__.'/resources/base64'));
    $rule->validate('avatar', $content, fn () => test()->fail('The PNG should be accepted.'));
    $pathname = app()->request->attributes->get('base64file.avatar')->getPathname();
    $errors = [];

    $rule->validate('avatar', 'data:image/png;base64,'.base64_encode('hello world'), function (string $message) use (&$errors) {
        $errors[] = $message;
    });

    expect($errors)->toHaveCount(1)
        ->and(app()->request->attributes->has('base64file.avatar'))->toBeFalse()
        ->and(is_file($pathname))->toBeFalse();
});
