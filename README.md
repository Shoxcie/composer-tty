# composer-tty

Run Composer scripts attached to the real console, so tools that ignore `--ansi` without a TTY keep their colors on Windows.

## Why

Composer runs command scripts through Symfony Process, which captures their output with pipes and can't use TTY mode on Windows. On Windows, every tool started from a Composer script therefore sees a non-TTY, even when you run Composer from an interactive terminal. Most tools still honor an explicit `--ansi`, but not all of them. Since Rector 2.6.7, `--ansi` is ignored on non-TTY output ([rector-src#8453](https://github.com/rectorphp/rector-src/pull/8453), [rector#9906](https://github.com/rectorphp/rector/issues/9906)).

PHP callbacks, unlike command scripts, run inside Composer's own process. This package is one such callback: it starts the tool with Composer's console handles instead of pipes, so the tool detects a TTY and keeps its colors.

On macOS and Linux, Composer already gives command scripts a TTY in interactive runs, so there the package only matters with `--no-interaction`. When output really is piped, as in CI or `| tee`, the tool sees the pipe and stays plain.

## Install

Requires PHP 8.5+ and Composer 2.2+.

```bash
composer require --dev shoxcie/composer-tty
```

## Usage

Register the callback once, then reference it from any script:

```json
"scripts": {
    "tty": "Shoxcie\\ComposerTty\\Tty::run",
    "rector:check": "@tty rector process --dry-run",
    "rector:fix": "@tty rector process"
}
```

Everything after `@tty` is the command. The first word is a binary from Composer's `bin-dir` (usually `vendor/bin`), or otherwise a path to a PHP script (`@tty bin/console lint:container`). Either way, it runs with the PHP binary that runs Composer, so it must be a PHP script, not a shell script or native executable.

Composer splits the command on spaces and doesn't support quoting, so arguments can't contain spaces.

Arguments given on the command line are appended, as in `composer rector:fix src/Foo.php`. You can also run a one-off command, as in `composer tty phpstan analyse`. As with any Composer script, put `--` before options, or Composer drops them along with everything after them: `composer rector:fix -- --dry-run`.

Exit codes pass through, and a failing command stops a chain of scripts, as with ordinary Composer scripts. Unlike command scripts, `@tty` commands aren't subject to Composer's `process-timeout`.

## License

MIT
