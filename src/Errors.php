<?php

declare(strict_types=1);

namespace Pin\Upload;

use Pin\Errors\Attribute\Group;
use Pin\Errors\Errorful;
use Pin\Errors\IError;

/**
 * 上传错误码定义
 */
#[Group('pin-upload::upload')]
enum Errors: string implements IError
{
    use Errorful;

    // upload
    case UploadExtensionInvalid = '3000|422|upload_extension_invalid';
    case UploadMimeTypeInvalid = '3001|422|upload_mime_type_invalid';
    case UploadSizeTooSmall = '3002|422|upload_size_too_small';
    case UploadSizeTooLarge = '3003|422|upload_size_too_large';
}
