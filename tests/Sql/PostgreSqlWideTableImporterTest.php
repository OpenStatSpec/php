<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Sql\PostgreSqlWideTableImporter;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgreSqlWideTableImporterTest extends TestCase
{
    public function testCreatesCatalogAndStrictWideTableInOneTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('beginTransaction')->willReturn(true);
        $pdo->expects(self::once())->method('commit')->willReturn(true);
        $pdo->expects(self::never())->method('rollBack');
        $pdo->expects(self::exactly(17))->method('exec')->willReturn(0);

        $definition = (new PostgreSqlWideTableImporter($pdo))->createTables([
            'variables' => [
                ['name' => 'Score', 'type' => 'numeric'],
                ['name' => 'Comment', 'type' => 'string'],
            ],
        ], 'Customer survey');

        self::assertSame('dataset_customer_survey', $definition->tableName);
        self::assertSame('score', $definition->columns[0]['columnName']);
        self::assertSame('numeric', $definition->columns[0]['storageKind']);
        self::assertSame('comment', $definition->columns[1]['columnName']);
        self::assertSame('string', $definition->columns[1]['storageKind']);
        self::assertStringContainsString('DOUBLE PRECISION NULL', $definition->createSql);
        self::assertStringContainsString('TEXT NOT NULL', $definition->createSql);
    }
}
