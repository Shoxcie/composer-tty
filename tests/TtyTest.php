<?php

declare(strict_types=1);

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\ScriptExecutionException;
use Composer\IO\BufferIO;
use Composer\Script\Event;
use Shoxcie\ComposerTty\Tty;
use Symfony\Component\Console\Formatter\OutputFormatter;

covers(Tty::class);

/**
 * Points descriptor 0, the stdin Tty passes on to the command, at $path, or leaves it closed for null.
 * Closing it frees the descriptor, and the OS gives the lowest free one to the next file opened.
 */
function stdin(?string $path): void
{
    static $handle = STDIN;

    if (is_resource($handle)) {
        fclose($handle);
    }

    $handle = null === $path ? null : fopen($path, 'r');
}

/**
 * @param list<string> $args
 */
function scriptEvent(array $args, BufferIO $bufferIO = new BufferIO()): Event
{
    $config = new Config(useEnvironment: false);
    $config->merge(['config' => ['bin-dir' => __DIR__.'/Fixtures/bin']]);

    $composer = new Composer();
    $composer->setConfig($config);

    return new Event('tty', $composer, $bufferIO, args: $args);
}

beforeEach(function (): void {
    // Commands must never wait for input from the console running the tests.
    stdin('Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null');
});

it('requires a command', function (Event $event): void {
    Tty::run($event);
})->with([
    'no arguments' => fn (): Event => scriptEvent([]),
    'blank arguments' => fn (): Event => scriptEvent(['', '']),
])->throws(InvalidArgumentException::class, 'Usage: composer tty <bin> [arguments...]');

it('ignores blank arguments', function (): void {
    Tty::run(scriptEvent(['', 'exit-code', '', '3']));
})->throws(ScriptExecutionException::class, exceptionCode: 3);

it('runs a binary from bin-dir', function (): void {
    Tty::run(scriptEvent(['exit-code', '0']));
})->throwsNoExceptions();

it('runs a script path that is not in bin-dir', function (): void {
    Tty::run(scriptEvent([__DIR__.'/Fixtures/bin/exit-code', '0']));
})->throwsNoExceptions();

it('passes its stdin to the command', function (): void {
    stdin(__FILE__);

    Tty::run(scriptEvent(['stdin-equals', __FILE__]));
})->throwsNoExceptions();

it('reports a command that cannot be started', function (): void {
    stdin(path: null);
    // proc_open warns about the stdin it can't reopen before returning false.
    set_error_handler(static fn (): bool => true, E_WARNING);

    try {
        Tty::run(scriptEvent(['exit-code', '0']));
    } finally {
        restore_error_handler();
    }
})->throws(RuntimeException::class, 'Could not start exit-code.');

it('passes the exit code through', function (int $code): void {
    $this->expectException(ScriptExecutionException::class);
    $this->expectExceptionCode($code);

    Tty::run(scriptEvent(['exit-code', (string) $code]));
})->with([1, 3, 255]);

it('reports a failure as an error under the name it was given', function (): void {
    $io = new BufferIO(formatter: new OutputFormatter(decorated: true));

    expect(function () use ($io): void {
        Tty::run(scriptEvent(['exit-code', '3'], $io));
    })
        ->toThrow(ScriptExecutionException::class, 'exit-code exited with code 3.')
        ->and($io->getOutput())->toBe("\e[37;41mexit-code exited with code 3.\e[39;49m".PHP_EOL)
    ;
});
