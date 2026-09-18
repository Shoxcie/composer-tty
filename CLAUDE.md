# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`shoxcie/composer-tty`: a Composer package with a single class, `src/Tty.php`, that runs a PHP tool with Composer's console handles instead of pipes. On Windows, tools started from Composer scripts never see a TTY, and some of them (Rector ≥ 2.6.7) ignore `--ansi` on non-TTY output. README.md has the full explanation and user-facing usage. Requires PHP ^8.5.

## Commands

```
composer quality        # phpstan, rector:check, cs:check, test:types, test:coverage (CI runs it on Ubuntu and Windows)
composer test           # Pest
composer test:types     # Pest type coverage, must stay at 100%
composer test:coverage  # line coverage, must stay at 100%; needs Xdebug or PCOV. Currently meaningless, see "Upstream bugs"
composer test:mutate    # mutation score, must stay at 100%; needs Xdebug or PCOV. Not part of quality; only meaningful on Linux, see "Upstream bugs"
composer phpstan        # level 10
composer rector:check   # dry run; rector:fix applies
composer cs:check       # php-cs-fixer dry run; cs:fix applies
```

Run a single test file or filter: `vendor/bin/pest tests/ArchTest.php` or `composer test -- --filter=<name>`.

`laravel/pao` is installed, so when an AI agent runs Pest, PHPStan or Rector, their normal output is replaced by a compact JSON summary (e.g. `{"tool":"pest","result":"passed",...}`). A clean PHPStan run may print nothing at all. Rely on the exit code, not on missing output.

After editing `composer.json`, run `composer normalize` (`ergebnis/composer-normalize` is installed as a plugin).

## How `Tty::run` works

- It's a Composer PHP callback, so it runs inside Composer's own process. Classes under `Composer\` are available at runtime, but `composer/composer` is only a dev dependency (for static analysis). The only runtime requirements are PHP and `composer-runtime-api`; don't add runtime dependencies.
- The arguments come from Composer splitting the script string on single spaces (no quoting support), so empty strings from repeated spaces are filtered out.
- The first argument is resolved against Composer's `bin-dir`. If no such file exists, it's used as-is, as a path to a PHP script. Either way, it's run with `PHP_BINARY`.
- `proc_open` is given the console's own descriptors (`['file', 'php://stdout', 'w']` etc.) rather than pipes. That is what makes the child detect a TTY, so don't switch to Symfony Process or pipes. Don't pass the `STDIN`/`STDOUT`/`STDERR` constants either: `laravel/pao` attaches stream filters to them inside Pest when an agent runs the tests, and `proc_open` can't pass a filtered stream to a child ("Cannot cast a filtered stream").
- A non-zero exit becomes a `ScriptExecutionException` carrying the child's exit code, which makes Composer stop a script chain. Composer never prints that exception's message, so the failure line is written through `$event->getIO()` first. Returning `false` instead would lose the exit code and wouldn't stop a chain started via `@tty`.

The repo uses its own callback: `rector:check` and `rector:fix` go through `@tty` (Rector ignores `--ansi` without a TTY), while PHPStan and php-cs-fixer just pass `--ansi`. A broken `src/Tty.php` therefore also breaks the Rector scripts.

## Tooling notes

- `tests/TtyTest.php` runs `Tty::run` for real against the fixture scripts in `tests/Fixtures/bin/`, which report only through their exit code. They're extensionless like real `vendor/bin` entries, so tests call them by name, but deliberately have no shebang: a fixture that can't run on its own proves `Tty` launched it with `PHP_BINARY`, even where the files are executable. With a shebang, the mutant that drops `PHP_BINARY` survives on Linux. The child writes to the real console, so fixtures must stay silent; tests observe the result only through exit codes, exceptions and a `BufferIO` (decorated, so the `<error>` styling is asserted too). The TTY behavior itself can't be tested, since test runs have no console.
- The test file controls descriptor 0 with its `stdin()` helper: `fclose(STDIN)` frees it, and the next file opened takes it over. That is how tests feed the child a known stdin, and how they make `proc_open` fail (fd 0 closed means `php://stdin` can't be reopened). By default it points at the null device, so a command never waits for input. Don't use the `STDIN` constant in tests.
- `tests/ArchTest.php` applies Pest's `php`, `security` and `strict` architecture presets.
- Test files for `src/` classes declare `covers(Class::class)`, which scopes coverage and marks them for mutation testing. `// @pest-mutate-ignore: <Mutator>` is only for mutants no test can observe, with a comment saying why (see the stdout/stderr descriptors in `Tty.php`). Don't lower `--min` instead.
- CI (`.github/workflows/ci.yml`) runs `composer quality` and then `composer test:mutate` on Ubuntu and Windows with PHP 8.5 and Xdebug, without a lock file.
- Rector and PHPStan run on `src/`, `tests/`, `rector.php` and `.php-cs-fixer.dist.php`, and php-cs-fixer on the whole repo (`@PhpCsFixer` + risky rules, which enforces e.g. Yoda comparisons and `\sprintf`-style native function calls). Use `cs:fix`/`rector:fix` instead of hand-formatting.
- `.gitattributes` export-ignores tests and tool configs, and `composer.lock` is gitignored because this is a library.

## Upstream bugs worked around

Recheck these whenever `composer update` brings a newer version of the package (`composer show <package>` prints the installed one). As an agent, set `PAO_DISABLE=1` so the output isn't condensed.

### phpunit/php-code-coverage 14.3.3: `test:coverage` always reports 100%

`ProcessedCodeCoverageData::renameFile()` deletes a file's data when the old and new names are equal, which happens when `src/` holds a single file. The report then has 0 of 0 lines, which is shown as 100%. It happens on every OS. Until it's fixed, see real line coverage with `vendor/bin/pest --coverage-clover=<file>` and look for `count="0"` lines.

- **Check:** run `vendor/bin/pest --coverage`. While the bug is there, only `Total: 100.0 %` is printed. Once it's fixed, a row for `Tty` appears above the total.
- **Clean up:** delete this entry and the "currently meaningless" remark on `test:coverage` under Commands. Nothing in the code depends on it.

### pestphp/pest-plugin-mutate v5.0.2: broken on Windows

These bugs make `composer test:mutate` meaningless on Windows. CI still runs it there, where it passes no matter what, and the Ubuntu job gives the real score. It's kept out of `quality` so local runs don't show that fake result:

1. It wraps `--filter` in literal quotes, so cmd treats the `|` in it as a pipe. Every mutation run fails, and every mutant counts as killed, so the score is always 100%.
2. It splits the source on `PHP_EOL`, which is `\r\n` on Windows, so with LF files every `@pest-mutate-ignore` comment lands on line 1.
3. It treats `C:\…` paths as relative, so without `--path` it finds no files. The `--path=src` in `test:mutate` works around this.

A fourth bug makes the child command fail the same way as bug 1 when Pest is started as `vendor/bin/pest --mutate`, so run these checks through Composer.

If only bug 1 gets fixed, the Windows CI job starts failing at about 94.9%, while Ubuntu stays at 100%. Mutation testing then works, but bug 2 keeps the two ignore comments from applying. That's the signal to run the checks below.

- **Check (on Windows):**
  1. Delete the two `// @pest-mutate-ignore: RemoveArrayItem` comments in `src/Tty.php` and run `composer test:mutate`. Bug 1 is fixed if it reports 2 untested mutants on those lines, and still there if it reports 100%. Restore the comments.
  2. Only once bug 1 is fixed, run `composer test:mutate` with the comments in place. Bug 2 is fixed if it reports 100%, and still there if the same 2 mutants survive.
  3. Run `composer exec pest -- --mutate`. Bug 3 is fixed if it reports `N Mutations for 1 Files created`, and still there if it reports `0 Mutations for 0 Files created`.
- **Clean up:**
  - Once bugs 1 and 2 are fixed: Windows gives a real score, so drop the "only meaningful on Linux" remark on `test:mutate` in the Commands block.
  - Once bug 3 is fixed: drop `--path=src` from `test:mutate`, so `covers()` narrows which tests run again.
  - Update this entry, or delete it once all three are fixed.

### rector/rector 2.6.7: named arguments for `@no-named-arguments` APIs

`AddNameToBooleanArgumentRector` names arguments even when the callee is marked `@no-named-arguments`, like PHP CS Fixer's `Config::setRiskyAllowed()`. PHPStan rejects that (`argument.named`), so the two tools loop, and that's why the rule is skipped in `rector.php`. If PHPStan reports `argument.named`, drop the argument name rather than suppressing the error. `AddNameToNullArgumentRector` has the same flaw but hasn't caused a loop here.

- **Check:** remove the rule from `withSkip()` in `rector.php` and run `composer rector:check`. It's fixed if Rector no longer wants `setRiskyAllowed(isRiskyAllowed: true)` in `.php-cs-fixer.dist.php`. Named booleans it proposes elsewhere are fine.
- **Clean up:** delete the `withSkip()` call and its import, run `composer rector:fix` and then `composer phpstan`, and delete this entry.
