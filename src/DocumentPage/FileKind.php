<?php

declare(strict_types=1);

namespace App\DocumentPage;

use App\Upload\UploadPolicy;

/**
 * Family of a stored file, from the MIME type detected at upload (never from the extension).
 * Drives what the document page can show: only PDFs and JPEG/PNG images are displayed inline.
 */
enum FileKind
{
    case Pdf;
    case Image;
    case Heic;
    case Word;
    case Spreadsheet;
    case Other;

    public static function fromMimeType(string $mimeType): self
    {
        return match ($mimeType) {
            'application/pdf' => self::Pdf,
            'image/jpeg', 'image/png' => self::Image,
            'image/heic', 'image/heif' => self::Heic,
            UploadPolicy::DOCX_MIME => self::Word,
            UploadPolicy::XLSX_MIME => self::Spreadsheet,
            default => self::Other,
        };
    }

    public function isPreviewable(): bool
    {
        return self::Pdf === $this || self::Image === $this;
    }

    public function icon(): string
    {
        return match ($this) {
            self::Image, self::Heic => 'lucide:image',
            self::Spreadsheet => 'lucide:file-spreadsheet',
            default => 'lucide:file-text',
        };
    }
}
