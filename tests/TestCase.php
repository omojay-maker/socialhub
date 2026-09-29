<?php
/**
 * Minimal, dependency-free test runner.
 *
 * The deployment target is an air-gapped Apache box with no composer vendor
 * directory, so the suite is deliberately self-contained. Run it with:
 *   php tests/run.php
 */

/** Thrown by a failing assertion; caught by the runner. */
class AssertionFailed extends RuntimeException {}

final class TestRunner
{
    private int $passed = 0;
    /** @var array<int, array{0:string,1:string,2:string}> */
    private array $failures = [];
    private string $group = '';

    public function group(string $name): void
    {
        $this->group = $name;
        echo "\n\033[1m$name\033[0m\n";
    }

    public function run(string $description, callable $fn): void
    {
        try {
            $fn();
            $this->passed++;
            echo "  \033[32mPASS\033[0m  $description\n";
        } catch (AssertionFailed $e) {
            $this->failures[] = [$this->group, $description, $e->getMessage()];
            echo "  \033[31mFAIL\033[0m  $description\n";
            echo "        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
        } catch (Throwable $e) {
            $this->failures[] = [$this->group, $description, get_class($e) . ': ' . $e->getMessage()];
            echo "  \033[31mERROR\033[0m $description\n";
            echo "        " . get_class($e) . ': ' . $e->getMessage() . "\n";
            echo "        at " . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    }

    public function summary(): int
    {
        $total = $this->passed + count($this->failures);
        echo "\n" . str_repeat('-', 62) . "\n";
        if (!$this->failures) {
            echo "\033[32mAll $total assertions passed.\033[0m\n";
            return 0;
        }
        echo "\033[31m" . count($this->failures) . " of $total failed:\033[0m\n";
        foreach ($this->failures as [$g, $d, $m]) {
            echo "  - [$g] $d\n    $m\n";
        }
        return 1;
    }
}

/* ------------------------------------------------------------ assertions */

function assert_true($value, string $message = ''): void
{
    if ($value !== true) {
        throw new AssertionFailed($message ?: 'Expected true, got ' . var_export($value, true));
    }
}

function assert_false($value, string $message = ''): void
{
    if ($value !== false) {
        throw new AssertionFailed($message ?: 'Expected false, got ' . var_export($value, true));
    }
}

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(
            ($message ? $message . "\n" : '')
            . 'expected: ' . var_export($expected, true) . "\n"
            . 'actual:   ' . var_export($actual, true)
        );
    }
}

function assert_equals($expected, $actual, string $message = ''): void
{
    if ($expected != $actual) {
        throw new AssertionFailed(
            ($message ? $message . "\n" : '')
            . 'expected: ' . var_export($expected, true) . "\n"
            . 'actual:   ' . var_export($actual, true)
        );
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(
            ($message ? $message . "\n" : '')
            . 'expected to find: ' . $needle . "\n"
            . 'in:               ' . $haystack
        );
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionFailed(
            ($message ? $message . "\n" : '')
            . 'did not expect to find: ' . $needle . "\n"
            . 'in:                     ' . $haystack
        );
    }
}

function assert_throws(string $expectedClass, callable $fn, string $message = ''): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new AssertionFailed(
                ($message ? $message . "\n" : '')
                . 'Expected ' . $expectedClass . ', got ' . get_class($e) . ': ' . $e->getMessage()
            );
        }
        return $e;
    }
    throw new AssertionFailed(($message ? $message . "\n" : '') . 'Expected ' . $expectedClass . ' to be thrown, nothing was.');
}
