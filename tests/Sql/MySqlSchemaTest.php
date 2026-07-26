<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Sql;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\UnsupportedOperation;
use OpenStatSpec\Sql\MySqlSchema;
use PDO;
use PHPUnit\Framework\TestCase;

final class MySqlSchemaTest extends TestCase
{
    public function testCatalogDdlUsesUtf8mb4SafeKeysAndLongPayloads(): void
    {
        $schema = new MySqlSchema($this->createMock(PDO::class));
        $catalog = implode("\n", $schema->catalogStatements());

        self::assertCount(16, $schema->catalogStatements());
        self::assertStringContainsString('dataset_name VARCHAR(94) NOT NULL', $catalog);
        self::assertStringContainsString('meta_key VARCHAR(94) NOT NULL', $catalog);
        self::assertStringContainsString('attribute_name VARCHAR(94) NOT NULL', $catalog);
        self::assertStringContainsString('label LONGTEXT NULL', $catalog);
        self::assertStringContainsString('text LONGTEXT NOT NULL', $catalog);
        self::assertStringContainsString('numeric_value DOUBLE NULL', $catalog);
        self::assertStringContainsString('PRIMARY KEY (dataset_name, attribute_name, ordinal)', $catalog);
        self::assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $catalog);
    }

    public function testGeneratedWideDdlIsMysqlNativeAndIdentifierSafe(): void
    {
        $long = str_repeat('Very long source variable name ', 4);
        $definition = (new MySqlSchema($this->createMock(PDO::class)))->wideTableDefinition('Select Dataset', [
            ['name' => $long, 'type' => 'numeric'],
            ['name' => $long . '!', 'type' => 'numeric'],
            ['name' => 'select', 'type' => 'string'],
        ]);

        self::assertStringContainsString(chr(96) . '__case_ordinal' . chr(96) . ' BIGINT NOT NULL PRIMARY KEY', $definition->createSql);
        self::assertStringContainsString('DOUBLE NULL', $definition->createSql);
        self::assertStringContainsString('LONGTEXT NOT NULL', $definition->createSql);
        self::assertStringContainsString(chr(96) . 'select' . chr(96) . ' LONGTEXT NOT NULL', $definition->createSql);
        self::assertStringContainsString('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', $definition->createSql);
        self::assertLessThanOrEqual(64, strlen($definition->tableName));
        self::assertNotSame($definition->columns[0]['columnName'], $definition->columns[1]['columnName']);
    }

    public function testWideTableRejectsColumnsBeyondMysqlInnoDbCapability(): void
    {
        $schema = new MySqlSchema($this->createMock(PDO::class));
        $variables = [];
        for ($index = 1; $index <= 1017; ++$index) {
            $variables[] = ['name' => 'v' . $index, 'type' => 'numeric'];
        }

        try {
            $schema->wideTableDefinition('fixture', $variables);
            self::fail('Expected MySQL source-variable capability preflight to fail.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::TargetCapabilityExceeded, $exception->diagnosticCode);
        }
    }

    public function testDdlCanBeExecutedThroughThePdoAbstractionWithoutAServer(): void
    {
        $pdo = $this->createMock(PDO::class);
        $schema = new MySqlSchema($pdo);
        $pdo->expects(self::exactly(17))->method('exec')->willReturn(0);
        $schema->createCatalog();
        $schema->createWideTable('fixture', [['name' => 'score', 'type' => 'numeric']]);
    }
}
