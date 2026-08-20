<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Frontend\Spss\Binding;

use OpenStatSpec\Frontend\Spss\Binding\SchemaState;
use OpenStatSpec\Frontend\Spss\Request\InputSchema;
use OpenStatSpec\Frontend\Spss\Request\InputVariable;
use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use PHPUnit\Framework\TestCase;

final class SchemaStateTest extends TestCase
{
    public function testUnicodeVariableNamesResolveCaseInsensitively(): void
    {
        $variable = new InputVariable('Ärger', 'numeric');
        $schema = new SchemaState(new InputSchema([$variable]));

        self::assertSame($variable, $schema->resolve('ärger', new SourceSpan(0, 5)));
        self::assertTrue($schema->contains('ÄRGER'));
    }
}
