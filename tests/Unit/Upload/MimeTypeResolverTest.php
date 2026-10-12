<?php

declare(strict_types=1);

namespace App\Tests\Unit\Upload;

use App\Upload\MimeTypeResolver;
use App\Upload\UploadPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MimeTypeResolverTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->temporaryFiles);
    }

    #[DataProvider('fixtures')]
    public function testDetectsTheTypeFromTheContent(string $fixture, string $expected): void
    {
        $this->assertSame($expected, new MimeTypeResolver()->resolve($this->upload(__DIR__ . '/../../Fixtures/files/' . $fixture, $fixture)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fixtures(): iterable
    {
        yield 'pdf' => ['sample.pdf', 'application/pdf'];
        yield 'png' => ['sample.png', 'image/png'];
        yield 'heic' => ['sample.heic', 'image/heic'];
        yield 'docx' => ['sample.docx', UploadPolicy::DOCX_MIME];
        yield 'xlsx' => ['sample.xlsx', UploadPolicy::XLSX_MIME];
        yield 'text' => ['sample.txt', 'text/plain'];
    }

    public function testTheClientDeclaredTypeAndExtensionDoNotOverrideTheContent(): void
    {
        $file = new UploadedFile(__DIR__ . '/../../Fixtures/files/sample.txt', 'invoice.pdf', 'application/pdf', null, true);

        $this->assertSame('text/plain', new MimeTypeResolver()->resolve($file));
    }

    public function testAZipReportedByFileinfoIsAcceptedAsDocxWhenItHasWordParts(): void
    {
        // [Content_Types].xml is not the first entry: fileinfo falls back to a generic zip.
        $path = $this->zip(['docProps/app.xml' => '<a/>', '[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<d/>']);

        $this->assertSame(UploadPolicy::DOCX_MIME, new MimeTypeResolver()->resolve($this->upload($path, 'contract.docx')));
    }

    public function testAZipReportedByFileinfoIsAcceptedAsXlsxWhenItHasSpreadsheetParts(): void
    {
        $path = $this->zip(['docProps/app.xml' => '<a/>', '[Content_Types].xml' => '<Types/>', 'xl/workbook.xml' => '<w/>']);

        $this->assertSame(UploadPolicy::XLSX_MIME, new MimeTypeResolver()->resolve($this->upload($path, 'balance.xlsx')));
    }

    /**
     * @param array<string, string> $entries
     */
    #[DataProvider('refusedArchives')]
    public function testOtherZipsKeepTheirGenericType(string $name, array $entries): void
    {
        $this->assertSame('application/zip', new MimeTypeResolver()->resolve($this->upload($this->zip($entries), $name)));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function refusedArchives(): iterable
    {
        yield 'zip renamed to docx' => ['archive.docx', ['hello.txt' => 'x']];
        yield 'xlsx name with word parts' => ['sheet.xlsx', ['docProps/app.xml' => '<a/>', '[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<d/>']];
    }

    private function upload(string $path, string $name): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @param array<string, string> $entries
     */
    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $this->assertIsString($path);
        $this->temporaryFiles[] = $path;

        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }
}
