<?php

declare(strict_types=1);

namespace OpenStatSpec\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use SPSS\Sav\Dataset;
use Throwable;

/**
 * Import boundary that keeps an ephemeral descriptor path inside the engine.
 *
 * The adapter sees only source.sav or source.zsav. Inner read failures are
 * deliberately replaced without exception chaining so physical paths cannot
 * enter operation or fidelity journals.
 */
final readonly class GuardedImportSpssEngine implements SpssEngine
{
    private string $logicalPath;
    private string $format;

    public function __construct(
        private SpssEngine $inner,
        private string $physicalDescriptorPath,
        string $format,
    ) {
        if (!in_array($format, ['sav', 'zsav'], true)) {
            throw new UnsupportedOperation(
                DiagnosticCode::UnsupportedSourceFormat,
                'The guarded SPSS source format must be SAV or ZSAV.',
            );
        }
        if (preg_match(
            '~\A(?:/proc/(?:self|thread-self|[0-9]+)/fd/[0-9]+|/dev/fd/[0-9]+)\z~',
            $physicalDescriptorPath,
        ) !== 1) {
            throw new UnsupportedOperation(
                DiagnosticCode::InvalidSourceDataset,
                'The guarded SPSS source must use a supported ephemeral descriptor path.',
            );
        }

        $this->format = $format;
        $this->logicalPath = 'source.' . $format;
    }

    /** @return array<string, mixed> */
    public function identity(): array
    {
        try {
            $identity = $this->inner->identity();
            $this->assertIdentitySafe($identity);

            return $identity;
        } catch (Throwable) {
            throw $this->sanitizedIdentityFailure();
        }
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        return $this->inner->capabilities();
    }

    public function read(string $sourcePath): Dataset
    {
        if (!hash_equals($this->logicalPath, $sourcePath)) {
            throw $this->sanitizedReadFailure();
        }

        try {
            $dataset = $this->inner->read($this->physicalDescriptorPath);
            if (!in_array($dataset->technicalMetadata->sourceFormat, ['sav', 'zsav'], true)
                || !hash_equals($this->format, $dataset->technicalMetadata->sourceFormat)
            ) {
                throw $this->sanitizedReadFailure();
            }

            return $dataset;
        } catch (Throwable) {
            throw $this->sanitizedReadFailure();
        }
    }

    public function write(string $targetPath, Dataset $dataset): void
    {
        $this->inner->write($targetPath, $dataset);
    }

    public function logicalPath(): string
    {
        return $this->logicalPath;
    }

    private function assertIdentitySafe(mixed $value, int $depth = 0): void
    {
        if ($depth > 32) {
            throw $this->sanitizedIdentityFailure();
        }
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1
                || str_contains($value, $this->physicalDescriptorPath)
                || preg_match(
                    '~(?:/proc/(?:self|thread-self|[0-9]+)/fd/[0-9]+|/dev/fd/[0-9]+)~',
                    $value,
                ) === 1
            ) {
                throw $this->sanitizedIdentityFailure();
            }

            return;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw $this->sanitizedIdentityFailure();
            }

            return;
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $this->assertIdentitySafe($key, $depth + 1);
                }
                $this->assertIdentitySafe($item, $depth + 1);
            }

            return;
        }
        if ($value !== null && !is_bool($value) && !is_int($value)) {
            throw $this->sanitizedIdentityFailure();
        }
    }

    private function sanitizedIdentityFailure(): UnsupportedOperation
    {
        return new UnsupportedOperation(
            DiagnosticCode::InvalidSourceDataset,
            'The guarded SPSS engine identity is not safe for journaling.',
        );
    }

    private function sanitizedReadFailure(): UnsupportedOperation
    {
        return new UnsupportedOperation(
            DiagnosticCode::InvalidSourceDataset,
            'The guarded SPSS source could not be read for logical path ' . $this->logicalPath . '.',
        );
    }
}
