<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Sql\PostgreSqlSchema;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgreSqlSchemaTest extends TestCase
{
    public function testCatalogDdlUsesPostgresTypesAndMetadataKeys(): void
    {
        $schema = new PostgreSqlSchema($this->createMock(PDO::class));
        $catalog = implode("\n", $schema->catalogStatements());
        self::assertCount(16, $schema->catalogStatements());
        self::assertStringContainsString('ordinal BIGINT NOT NULL', $catalog);
        self::assertStringContainsString('numeric_value DOUBLE PRECISION NULL', $catalog);
        self::assertStringContainsString('PRIMARY KEY (dataset_name, meta_key)', $catalog);
        self::assertStringContainsString('multiple_response_set_members', $catalog);
    }

    public function testGeneratedWideDdlIsPostgresNativeAndIdentifierSafe(): void
    {
        $long = str_repeat('Very long source variable name ', 4);
        $definition = (new PostgreSqlSchema($this->createMock(PDO::class)))->wideTableDefinition('Example Dataset', [
            ['name' => $long, 'type' => 'numeric'],
            ['name' => $long . '!', 'type' => 'numeric'],
            ['name' => 'answer', 'type' => 'string'],
        ]);
        self::assertStringContainsString('"__case_ordinal" BIGINT NOT NULL PRIMARY KEY', $definition->createSql);
        self::assertStringContainsString('DOUBLE PRECISION NULL', $definition->createSql);
        self::assertStringContainsString('TEXT NOT NULL', $definition->createSql);
        self::assertLessThanOrEqual(63, strlen($definition->tableName));
        self::assertNotSame($definition->columns[0]['columnName'], $definition->columns[1]['columnName']);
    }

    public function testDdlCanBeExecutedThroughThePdoAbstractionWithoutAServer(): void
    {
        $pdo = $this->createMock(PDO::class);
        $schema = new PostgreSqlSchema($pdo);
        $pdo->expects(self::exactly(17))->method('exec')->willReturn(0);
        $schema->createCatalog();
        $schema->createWideTable('fixture', [['name' => 'score', 'type' => 'numeric']]);
    }
}
