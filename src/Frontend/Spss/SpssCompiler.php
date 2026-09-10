<?php

declare(strict_types=1);

namespace OpenStatSpec\Frontend\Spss;

use OpenStatSpec\Frontend\Spss\Ast\Program;
use OpenStatSpec\Frontend\Spss\Binding\BoundProgram;
use OpenStatSpec\Frontend\Spss\Request\InputSchema;
use OpenStatSpec\Frontend\Spss\Request\SpssFrontendRequest;

/** Public facade for the official SPSS lexer/parser/binder/compiler pipeline. */
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

    public function bind(string $inputAlias, InputSchema $inputSchema, Program $program): BoundProgram
    {
        return $this->binder->bind($inputAlias, $inputSchema, $program);
    }

    public function compile(SpssFrontendRequest $request): SpssCompilationResult
    {
        $sourceHash = $request->sourceHash();
        $program = $this->parser->parse($request->sourceText, $request->contract === SpssFrontendRequest::CONTRACT_V03);
        $bound = $this->binder->bind($request->inputAlias, $request->inputSchema, $program);

        return new SpssCompilationResult($this->compiler->compile($bound), $sourceHash);
    }
}
