<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use SPSS\Sav\Dataset;

/** Typed boundary around the Composer-installed php-spss V3 engine. */
final class PhpSpssEngine implements SpssEngine
{
    public const PACKAGE = 'tiamo/spss';
    public const READER_CLASS = 'SPSS\\Sav\\Reader';
    public const WRITER_CLASS = 'SPSS\\Sav\\Writer';

    public function isAvailable(): bool
    {
        return class_exists(self::READER_CLASS);
    }

    public function read(string $sourcePath): Dataset
    {
        if (!$this->isAvailable()) {
            throw new UnsupportedOperation(
                DiagnosticCode::ExternalEngineUnavailable,
                'The selected SPSS engine is not installed. Install Composer dependencies including tiamo/spss before importing SAV data.',
            );
        }

        $readerClass = self::READER_CLASS;

        return $readerClass::fromFile($sourcePath)->readDataset();
    }

    public function write(string $targetPath, Dataset $dataset): void
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
