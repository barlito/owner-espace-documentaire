<?php

declare(strict_types=1);

namespace App\Upload;

/**
 * A file was refused. The message is meant for the end user (French), not for developers.
 */
final class UploadRejected extends \RuntimeException
{
}
