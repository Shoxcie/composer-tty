<?php

declare(strict_types=1);

namespace Shoxcie\ComposerTty;

use Composer\EventDispatcher\ScriptExecutionException;
use Composer\IO\IOInterface;
use Composer\Script\Event;
use InvalidArgumentException;
use RuntimeException;

/**
 * Runs a PHP command with Composer's console handles instead of pipes,
 * so the command detects a TTY. See README.md for why.
 */
final class Tty
{
    public static function run(Event $event): void
    {
        // Composer splits "@tty rector process" on single spaces, so repeated spaces yield empty arguments.
        $args = array_values(array_diff($event->getArguments(), ['']));

        if ([] === $args) {
            throw new InvalidArgumentException(\sprintf('Usage: composer %s <bin> [arguments...]', $event->getName()));
        }

        $name = $args[0];
        $bin = $event->getComposer()->getConfig()->get('bin-dir').'/'.$name;

        if (is_file($bin)) {
            $args[0] = $bin;
        }

        // Not the STDIN/STDOUT/STDERR constants: they may carry stream filters (e.g. laravel/pao inside Pest),
        // which proc_open can't pass to a child. Reopening the same descriptors drops the filters.
        $process = proc_open([PHP_BINARY, ...$args], [
            ['file', 'php://stdin', 'r'],
            // Dropping stderr changes nothing, since the child inherits the same one, and a dropped stdout can't be observed from a test.
            ['file', 'php://stdout', 'w'], // @pest-mutate-ignore: RemoveArrayItem
            ['file', 'php://stderr', 'w'], // @pest-mutate-ignore: RemoveArrayItem
        ], $pipes);

        if (false === $process) {
            throw new RuntimeException(\sprintf('Could not start %s.', $name));
        }

        $code = proc_close($process);

        if (0 !== $code) {
            $message = \sprintf('%s exited with code %d.', $name, $code);

            // Composer only uses a ScriptExecutionException's code as its exit code and never prints the message.
            $event->getIO()->writeError(\sprintf('<error>%s</error>', $message), true, IOInterface::QUIET);

            throw new ScriptExecutionException($message, $code);
        }
    }
}
