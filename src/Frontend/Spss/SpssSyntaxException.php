<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use OpenStatSpec\Transformation\Diagnostic\SourceSpan;
use OpenStatSpec\Transformation\Diagnostic\TransformationDiagnostic;
use OpenStatSpec\Transformation\Diagnostic\TransformationFailure;

final class SpssSyntaxException extends TransformationFailure
{
    /**
     * @param non-empty-list<Diagnostic|TransformationDiagnostic> $diagnostics
     */
    public function __construct(array $diagnostics)
    {
        parent::__construct(array_map(
            static fn(Diagnostic|TransformationDiagnostic $diagnostic): TransformationDiagnostic => $diagnostic instanceof TransformationDiagnostic
                ? $diagnostic
                : new TransformationDiagnostic(
                    'spss_syntax_error',
                    '$.source_text',
                    $diagnostic->message,
                    new SourceSpan(0, 0, $diagnostic->line, $diagnostic->column, $diagnostic->line, $diagnostic->column),
                ),
            $diagnostics,
        ));
    }
}
