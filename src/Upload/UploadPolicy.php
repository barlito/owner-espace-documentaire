<?php

declare(strict_types=1);

namespace App\Upload;

/**
 * Single source of truth for what a client may upload: used by the validator, the controllers,
 * the upload page and (through data attributes) the Stimulus controller.
 */
final class UploadPolicy
{
    public const int MAX_FILE_SIZE = 20 * 1024 * 1024;
    public const int MAX_FILE_SIZE_MB = 20;
    public const int MAX_FILES_PER_BATCH = 20;
    /** Matches post_max_size in .docker/php/app.ini: it only constrains the no-JS fallback. */
    public const int MAX_BATCH_SIZE_MB = 100;

    public const string DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    public const string XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * Accepted formats: label shown to users, MIME types detected server-side, file extensions.
     * HEIC is accepted on purpose (iPhone photos), even if previews or analysis may not support it later.
     *
     * @var list<array{label: string, mimeTypes: list<string>, extensions: list<string>}>
     */
    private const array FORMATS = [
        ['label' => 'PDF', 'mimeTypes' => ['application/pdf'], 'extensions' => ['pdf']],
        ['label' => 'JPEG', 'mimeTypes' => ['image/jpeg'], 'extensions' => ['jpg', 'jpeg']],
        ['label' => 'PNG', 'mimeTypes' => ['image/png'], 'extensions' => ['png']],
        ['label' => 'HEIC', 'mimeTypes' => ['image/heic', 'image/heif'], 'extensions' => ['heic', 'heif']],
        ['label' => 'Word (.docx)', 'mimeTypes' => [self::DOCX_MIME], 'extensions' => ['docx']],
        ['label' => 'Excel (.xlsx)', 'mimeTypes' => [self::XLSX_MIME], 'extensions' => ['xlsx']],
    ];

    /**
     * @return list<string>
     */
    public function formatLabels(): array
    {
        return array_column(self::FORMATS, 'label');
    }

    /**
     * @return list<string>
     */
    public function mimeTypes(): array
    {
        return array_merge(...array_column(self::FORMATS, 'mimeTypes'));
    }

    /**
     * @return list<string>
     */
    public function extensions(): array
    {
        return array_merge(...array_column(self::FORMATS, 'extensions'));
    }

    public function isAcceptedMimeType(string $mimeType): bool
    {
        return \in_array($mimeType, $this->mimeTypes(), true);
    }

    /**
     * Whether the file name announces the format detected in its content (case-insensitive), so that a download
     * never carries a misleading extension (a PDF named .html, an image named .exe).
     */
    public function extensionMatches(string $mimeType, string $extension): bool
    {
        return \in_array(strtolower($extension), $this->formatOf($mimeType)['extensions'] ?? [], true);
    }

    /**
     * Value of the "accept" attribute of the file input (a hint for the file picker, never a guarantee).
     */
    public function acceptAttribute(): string
    {
        return implode(',', array_map(static fn (string $extension): string => '.' . $extension, $this->extensions()));
    }

    public function unsupportedFormatMessage(): string
    {
        return \sprintf('Ce format n\'est pas accepté. Formats acceptés : %s.', implode(', ', $this->formatLabels()));
    }

    public function extensionMismatchMessage(string $mimeType): string
    {
        $format = $this->formatOf($mimeType) ?? throw new \LogicException(\sprintf('"%s" is not an accepted MIME type.', $mimeType));

        return \sprintf('Le nom de ce fichier ne correspond pas à son contenu (%s). Renommez-le avec l\'extension .%s, puis déposez-le à nouveau.', $format['label'], $format['extensions'][0]);
    }

    public function tooLargeMessage(): string
    {
        return \sprintf('Ce fichier dépasse %d Mo.', self::MAX_FILE_SIZE_MB);
    }

    public function emptyFileMessage(): string
    {
        return 'Ce fichier est vide.';
    }

    public function interruptedMessage(): string
    {
        return 'L\'envoi de ce fichier a été interrompu, réessayez.';
    }

    /**
     * @return array{label: string, mimeTypes: list<string>, extensions: list<string>}|null
     */
    private function formatOf(string $mimeType): ?array
    {
        foreach (self::FORMATS as $format) {
            if (\in_array($mimeType, $format['mimeTypes'], true)) {
                return $format;
            }
        }

        return null;
    }
}
