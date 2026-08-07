<?php

declare(strict_types=1);

use Pin\Upload\Base64File;

it('stores base64 file into request attributes', function () {
    new Base64File(trim(file_get_contents(__DIR__.'/resources/base64')), 'avatar');

    expect(app()->request->attributes->get('base64file.avatar'))
        ->toBeInstanceOf(Base64File::class);
});
