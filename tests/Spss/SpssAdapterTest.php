<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Spss;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Spss\SpssAdapter;
use PDO;
use PHPUnit\Framework\TestCase;

final class SpssAdapterTest extends TestCase
{
    public function testImportFailsExplicitlyUntilAReaderIsImplemented(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is not available in this PHP environment.');
        }

        $adapter = new SpssAdapter(new PDO('sqlite::memory:'));

        try {
            $adapter->import('fixture.sav', 'fixture');
            self::fail('Expected explicit unsupported-operation diagnostic.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::UnsupportedOperation, $exception->diagnosticCode);
        }
    }
}
