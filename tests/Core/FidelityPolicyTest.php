<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Core;

use OpenStatSpec\Core\DiagnosticCode;
use OpenStatSpec\Core\FidelityDiagnostic;
use OpenStatSpec\Core\FidelityPolicy;
use OpenStatSpec\Core\FidelitySeverity;
use OpenStatSpec\Core\UnsupportedOperation;
use PHPUnit\Framework\TestCase;

final class FidelityPolicyTest extends TestCase
{
    public function testExportRequiresExplicitAcknowledgementOfEveryKnownLoss(): void
    {
        $diagnostic = new FidelityDiagnostic(
            'deferred-feature',
            'The target engine cannot reproduce this feature.',
            FidelitySeverity::Warning,
            ['feature' => 'example'],
        );

        try {
            FidelityPolicy::assertExportAllowed([$diagnostic], []);
            self::fail('The known loss must require an explicit acknowledgement.');
        } catch (UnsupportedOperation $exception) {
            self::assertSame(DiagnosticCode::FidelityLossRequiresAcceptance, $exception->diagnosticCode);
            self::assertSame('Export requires explicit allowLoss acknowledgement for: deferred-feature', $exception->getMessage());
        }

        FidelityPolicy::assertExportAllowed([$diagnostic], ['deferred-feature']);
        self::assertSame(
            [
                'code' => 'deferred-feature',
                'message' => 'The target engine cannot reproduce this feature.',
                'severity' => 'warning',
                'details' => ['feature' => 'example'],
            ],
            $diagnostic->toArray(),
        );
    }
}
