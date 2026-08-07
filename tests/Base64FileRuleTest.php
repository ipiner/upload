<?php

declare(strict_types=1);

use Pin\Upload\Rules\Base64File;

it('validates base64 file rule', function () {
    $content = trim(file_get_contents(__DIR__.'/resources/base64'));

    $errors = 0;

    $fail = function () use (&$errors) {
        $errors++;

        return fn () => $errors;
    };

    (new Base64File())->validate('avatar', $content, $fail); // true
    (new Base64File(['text/plain']))->validate('avatar', $content, $fail); // true
    (new Base64File(['image/jpeg']))->validate('avatar', $content, $fail); // false
    (new Base64File())->validate('avatar', 'data:image/png;base64,invalid content', $fail); // false

    expect($errors)->toBe(1);
});
