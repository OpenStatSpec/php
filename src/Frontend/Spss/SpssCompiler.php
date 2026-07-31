<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Ast\Program;
use OpenStatSpec\Frontend\Spss\Binding\BoundProgram;
use OpenStatSpec\Transformation\Model\TransformationPlan;

/** Public facade for the SPSS lexer/parser/binder/compiler pipeline. */
final class SpssCompiler
{
    public function __construct(
        private readonly Parser $parser = new Parser(),
        private readonly Binder $binder = new Binder(),
        private readonly Compiler $compiler = new Compiler(),
    ) {}

    public function parse(string $source): Program
    {
        return $this->parser->parse($source);
    }

    public function bind(string $datasetId, Program $program): BoundProgram
    {
        return $this->binder->bind($datasetId, $program);
    }

    public function compile(string $source, string $datasetId): TransformationPlan
    {
        return $this->compiler->compile($this->binder->bind($datasetId, $this->parser->parse($source)));
    }

    public function compileForDataset(string $datasetId, string $source): TransformationPlan
    {
        return $this->compile($source, $datasetId);
    }
}
