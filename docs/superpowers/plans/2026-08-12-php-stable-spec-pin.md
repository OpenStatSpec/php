# PHP Stable Specification Pin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pin the PHP adapter and its CI conformance fixtures to specification `v0.3.0` at exact commit `cd8f198c68b849eb8ed018a894670a0904c2181d`.

**Architecture:** Keep the pin in `CapabilityDeclaration` constants and reuse those constants for the top-level and per-profile machine-readable declarations. Update every CI checkout to the same immutable commit and add a focused test that detects workflow drift; do not broaden transformation claims.

**Tech Stack:** PHP 8.4+, Composer, PHPUnit 11, PHP-CS-Fixer, PHPStan, GitHub Actions.

## Global Constraints

- Specification commit: `cd8f198c68b849eb8ed018a894670a0904c2181d`.
- Specification release: `v0.3.0`.
- Specification status: `stable`.
- Keep official Transformation Plan and SPSS Frontend conformance unclaimed.
- Do not reformat unrelated files or change the existing package dependency set.

---

### Task 1: Add the failing stable-pin assertions

**Files:**
- Modify: `tests/Core/CapabilityDeclarationTest.php`
- Create: `tests/Release/SpecificationPinTest.php`

- [x] **Step 1: Update declaration expectations and add CI-ref coverage.**

Change the existing declaration assertions to expect `stable`, `v0.3.0`, and
the exact commit. Add `tests/Release/SpecificationPinTest.php` with a
workflow-drift assertion that scans every workflow file and requires an exact
`ref` in the same mapping for every specification repository entry:

```php
public function testEverySpecificationCheckoutUsesTheStableReleaseCommit(): void
{
    $workflowFiles = glob(dirname(__DIR__, 2) . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE);
    self::assertNotFalse($workflowFiles);
    $refs = [];
    foreach ($workflowFiles as $workflowFile) {
        $workflow = file_get_contents($workflowFile);
        self::assertIsString($workflow);
        $lines = preg_split('/\R/', $workflow);
        self::assertIsArray($lines);
        foreach ($lines as $index => $line) {
            if (!preg_match('/^(\s*)repository:\s*OpenStatSpec\/specification\s*$/', $line, $repository)) {
                continue;
            }
            $indent = strlen($repository[1]);
            $ref = null;
            for ($next = $index + 1; $next < count($lines); ++$next) {
                if (trim($lines[$next]) === '') {
                    continue;
                }
                $nextIndent = strlen($lines[$next]) - strlen(ltrim($lines[$next]));
                if ($nextIndent < $indent) {
                    break;
                }
                if (preg_match('/^\s*ref:\s*(\S+)\s*$/', $lines[$next], $match)) {
                    $ref = $match[1];
                    break;
                }
            }
            self::assertNotNull($ref, "Missing immutable ref for {$workflowFile}");
            $refs[] = $ref;
        }
    }
    self::assertCount(5, $refs);
    self::assertSame(
        array_fill(0, count($refs), CapabilityDeclaration::SPECIFICATION_COMMIT),
        $refs,
    );
}
```

- [x] **Step 2: Run the focused tests to verify they fail.**

Run:

```bash
OPENSTATSPEC_SPECIFICATION_DIR=/tmp/openstatspec-spec-roadmap-v030 vendor/bin/phpunit tests/Core/CapabilityDeclarationTest.php tests/Release/SpecificationPinTest.php
```

Expected: failure because the declaration still reports `v0.1.0` and the CI
workflow still checks out commit `d287c2cde9ade71f04e27dd012caec876901aed5`.

- [x] **Step 3: Commit the red tests.**

```bash
git add tests/Core/CapabilityDeclarationTest.php tests/Release/SpecificationPinTest.php
git commit -m "test: require PHP adapter stable specification pin"
```

### Task 2: Implement the exact stable pin

**Files:**
- Modify: `src/Core/CapabilityDeclaration.php`
- Modify: `.github/workflows/ci.yml`

- [x] **Step 1: Update the declaration constants and usages.**

Define `SPECIFICATION_STATUS = 'stable'`, set `SPECIFICATION_RELEASE` to
`'v0.3.0'`, set `SPECIFICATION_COMMIT` to the exact 40-character commit, and
use the status constant wherever the declaration currently emits
`specification_status`.

- [x] **Step 2: Update all CI specification checkout refs.**

Replace each old specification checkout ref in `.github/workflows/ci.yml`
with `cd8f198c68b849eb8ed018a894670a0904c2181d`; keep the checkout repository,
paths, and job matrices unchanged.

- [x] **Step 3: Run the focused tests to verify they pass.**

Run the command from Task 1. Expected: all focused tests pass.

- [x] **Step 4: Commit the implementation.**

```bash
git add src/Core/CapabilityDeclaration.php .github/workflows/ci.yml
git commit -m "release: pin PHP adapter to specification v0.3.0"
```

### Task 3: Update release documentation

**Files:**
- Modify: `CHANGELOG.md`
- Create: `docs/release-readiness.md`

- [x] **Step 1: Document the stable pin without claiming a release.**

Add an Unreleased changelog entry and a concise release-readiness page stating
the exact specification release/commit, required local and CI gates, and the
fact that transformation conformance remains a separate prerequisite.

- [x] **Step 2: Run the full local gate.**

Run:

```bash
OPENSTATSPEC_SPECIFICATION_DIR=/tmp/openstatspec-spec-roadmap-v030 composer check
```

Composer validation, lint, PHPStan, and PHPUnit completed successfully. The
PHP-CS-Fixer step is blocked locally by the existing CRLF checkout drift (it
reports 144 existing files), so no unrelated mass reformat was included.

- [x] **Step 3: Commit the documentation.**

```bash
git add CHANGELOG.md docs/release-readiness.md
git commit -m "docs: record PHP stable specification release readiness"
```
