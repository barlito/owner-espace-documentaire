<?php

declare(strict_types=1);

namespace App\Tests\Unit\DocumentPage;

use App\DocumentPage\FileKind;
use App\Upload\UploadPolicy;
use PHPUnit\Framework\TestCase;

final class FileKindTest extends TestCase
{
    public function testEveryAcceptedUploadFormatIsClassified(): void
    {
        foreach (new UploadPolicy()->mimeTypes() as $mimeType) {
            $this->assertNotSame(FileKind::Other, FileKind::fromMimeType($mimeType), $mimeType);
        }
    }

    public function testOnlyPdfsAndBrowserImagesArePreviewable(): void
    {
        $previewable = array_filter(FileKind::cases(), static fn (FileKind $kind): bool => $kind->isPreviewable());

        $this->assertSame([FileKind::Pdf, FileKind::Image], array_values($previewable));
        $this->assertFalse(FileKind::fromMimeType('image/heic')->isPreviewable());
        $this->assertFalse(FileKind::fromMimeType('text/html')->isPreviewable());
    }
}
