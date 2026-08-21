<?php

declare(strict_types=1);

namespace OpenStatSpec\Tests\Transformation\Conformance;

use OpenStatSpec\Tests\Support\SpecificationManifest;
use PHPUnit\Framework\TestCase;

final class ManifestInventoryTest extends TestCase
{
    public function testPinnedTransformationManifestInventory(): void
    {
        self::assertCount(4, SpecificationManifest::load('conformance/transformation-plan-0.1.json')['cases']);
        self::assertCount(26, SpecificationManifest::load('conformance/transformation-plan-0.2.json')['cases']);
        self::assertCount(44, SpecificationManifest::load('conformance/spss-syntax-frontend-0.2.json')['cases']);
        self::assertCount(6, SpecificationManifest::load('conformance/in-place-transformation-0.1.json')['cases']);
        self::assertCount(11, SpecificationManifest::load('conformance/in-place-transformation-0.2.json')['cases']);
    }
}
