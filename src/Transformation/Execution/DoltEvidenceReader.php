<?php

declare(strict_types=1);

namespace OpenStatSpec\Transformation\Execution;

interface DoltEvidenceReader
{
    public function read(): DoltEvidence;
}
