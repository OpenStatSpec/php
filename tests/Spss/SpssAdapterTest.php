<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\PhpSpssEngine;
use OpenStatSpec\Spss\SpssAdapter;
use PDO;
use PHPUnit\Framework\TestCase;

final class SpssAdapterTest extends TestCase
{
    public function testImportReportsAnUnavailableExternalEngineExplicitly(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        self::assertFalse((new PhpSpssEngine())->isAvailable());

        $adapter = new SpssAdapter(new PDO('sqlite::memory:'));

        try {
            $adapter->import('fixture.sav', 'fixture');
            self::fail('Expected explicit external-engine diagnostic.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::ExternalEngineUnavailable, $exception->diagnosticCode);
        }
    }
}
