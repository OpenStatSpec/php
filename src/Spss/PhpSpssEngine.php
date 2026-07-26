<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;

/**
 * Transition boundary around the Composer-installed TonisOrmisson/php-spss V3 engine.
 *
 * Typed V3 Dataset/Variable catalogue mapping is implemented separately.
 */
final class PhpSpssEngine implements SpssEngine
{
    public const PACKAGE = 'tiamo/spss';
    public const READER_CLASS = 'SPSS\\Sav\\Reader';
    public const WRITER_CLASS = 'SPSS\\Sav\\Writer';

    public function isAvailable(): bool
    {
        return class_exists(self::READER_CLASS);
    }

    /**
     * @return array{header: mixed, variables: array<int, mixed>, valueLabels: array<int, mixed>, documents: array<int, mixed>, info: array<int, mixed>, data: array<int, mixed>}
     */
    public function read(string $sourcePath): array
    {
        if (!$this->isAvailable()) {
            throw new UnsupportedOperation(
                DiagnosticCode::ExternalEngineUnavailable,
                'The selected SPSS engine is not installed. Install Composer dependencies including tiamo/spss before importing SAV data.',
            );
        }

        $readerClass = self::READER_CLASS;
        $reader = $readerClass::fromFile($sourcePath)->read();

        return [
            'header' => $reader->header,
            'variables' => $reader->variables,
            'valueLabels' => $reader->valueLabels,
            'documents' => $reader->documents,
            'info' => $reader->info,
            'data' => $reader->data,
        ];
    }

    public function write(string $targetPath, array $dataset): void
    {
        if (!class_exists(self::WRITER_CLASS)) {
            throw new UnsupportedOperation(
                DiagnosticCode::ExternalEngineUnavailable,
                'The selected SPSS engine is not installed. Install Composer dependencies including tiamo/spss before exporting SAV data.',
            );
        }

        $writerClass = self::WRITER_CLASS;
        $writer = $writerClass::createInFile($targetPath, $dataset);
        $writer->close();
    }

}
