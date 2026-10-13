<?php

declare(strict_types=1);

namespace App\Upload;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Detects the MIME type from the file content (fileinfo), never from what the client declares.
 *
 * fileinfo recognises OOXML documents by looking at the first entries of the archive. Files produced by
 * some tools get reported as a plain zip (or octet-stream) although they are valid .docx/.xlsx. In that
 * case, and only when the extension also claims .docx/.xlsx, the archive structure is checked
 * (a word/ or xl/ part next to [Content_Types].xml) before the canonical OOXML type is assigned.
 * Anything else keeps its detected type and is refused by the policy.
 */
final class MimeTypeResolver
{
    private const array GENERIC_TYPES = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];

    public function resolve(UploadedFile $file): string
    {
        $detected = $file->getMimeType() ?? 'application/octet-stream';

        if (!\in_array($detected, self::GENERIC_TYPES, true)) {
            return $detected;
        }

        return match (strtolower($file->getClientOriginalExtension())) {
            'docx' => $this->hasOoxmlPart($file->getPathname(), 'word/') ? UploadPolicy::DOCX_MIME : $detected,
            'xlsx' => $this->hasOoxmlPart($file->getPathname(), 'xl/') ? UploadPolicy::XLSX_MIME : $detected,
            default => $detected,
        };
    }

    private function hasOoxmlPart(string $path, string $directory): bool
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::RDONLY)) {
            return false;
        }

        try {
            if (false === $zip->locateName('[Content_Types].xml')) {
                return false;
            }

            for ($i = 0; $i < $zip->numFiles; ++$i) {
                if (str_starts_with((string) $zip->getNameIndex($i), $directory)) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }
}
