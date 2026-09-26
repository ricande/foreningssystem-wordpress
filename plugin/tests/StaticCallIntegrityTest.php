<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use PHPUnit\Framework\TestCase;

/**
 * A guard against the class of mistake where a refactor keeps `self::guard(...)` but drops
 * guard(). PHP only notices when the line runs, which for an admin-post route means in
 * production. This walks the source instead.
 */
final class StaticCallIntegrityTest extends TestCase
{
    public function test_every_self_call_in_the_plugin_resolves_to_a_method(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->sourceFiles() as $file) {
            $known = $this->knownMethods($file);

            foreach ($this->selfCalls($file) as $call) {
                $checked++;

                if (! in_array(strtolower($call['method']), $known, true)) {
                    $missing[] = $this->relative($file) . ':' . $call['line'] . ' calls ' . $call['keyword'] . '::' . $call['method'] . '()';
                }
            }
        }

        self::assertGreaterThan(100, $checked, 'The source scan found almost no static calls, so it is not looking at the plugin.');
        self::assertSame([], $missing, 'A static call has no method behind it.');
    }

    public function test_the_scan_finds_a_call_without_a_method(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'assoc-scan-');
        file_put_contents($file, <<<'PHP'
        <?php

        namespace Foreningssystem\Broken;

        final class Broken
        {
            public static function run(): void
            {
                self::guard('assoc_action');
                static::alsoMissing();
                self::present();
            }

            private static function present(): void
            {
            }
        }
        PHP);

        $known = $this->knownMethods($file);
        $found = [];

        foreach ($this->selfCalls($file) as $call) {
            if (! in_array(strtolower($call['method']), $known, true)) {
                $found[] = $call['method'];
            }
        }

        unlink($file);

        self::assertSame(['guard', 'alsoMissing'], $found);
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $directory = new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS);

        /** @var \SplFileInfo $entry */
        foreach (new \RecursiveIteratorIterator($directory) as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every method name the file can reach: the ones it declares, plus everything the
     * classes in it inherit.
     *
     * @return list<string>
     */
    private function knownMethods(string $file): array
    {
        $tokens = $this->tokens($file);
        $names = [];
        $namespace = '';
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->nameAfter($tokens, $index);

                continue;
            }

            if ($token[0] === T_FUNCTION) {
                $name = $this->nameAfter($tokens, $index);

                if ($name !== '') {
                    $names[] = strtolower($name);
                }

                continue;
            }

            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $name = $this->nameAfter($tokens, $index);

                if ($name === '' || $namespace === '') {
                    continue;
                }

                $class = $namespace . '\\' . $name;

                if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                    foreach (get_class_methods($class) as $method) {
                        $names[] = strtolower($method);
                    }

                    foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                        $names[] = strtolower($method->getName());
                    }
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<array{keyword: string, method: string, line: int}>
     */
    private function selfCalls(string $file): array
    {
        $tokens = $this->tokens($file);
        $calls = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token)) {
                continue;
            }

            $keyword = strtolower((string) $token[1]);

            if (($token[0] !== T_STRING && $token[0] !== T_STATIC) || ! in_array($keyword, ['self', 'static'], true)) {
                continue;
            }

            $operator = $tokens[$index + 1] ?? null;

            if (! is_array($operator) || $operator[0] !== T_DOUBLE_COLON) {
                continue;
            }

            $name = $tokens[$index + 2] ?? null;

            if (! is_array($name) || $name[0] !== T_STRING) {
                continue;
            }

            if ($this->nextSymbol($tokens, $index + 3) !== '(') {
                continue;
            }

            $calls[] = [
                'keyword' => $keyword,
                'method' => (string) $name[1],
                'line' => (int) $name[2],
            ];
        }

        return $calls;
    }

    /**
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private function tokens(string $file): array
    {
        return token_get_all((string) file_get_contents($file));
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function nameAfter(array $tokens, int $index): string
    {
        $count = count($tokens);

        for ($next = $index + 1; $next < $count; $next++) {
            $token = $tokens[$next];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                continue;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                return (string) $token[1];
            }

            return '';
        }

        return '';
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function nextSymbol(array $tokens, int $index): string
    {
        $count = count($tokens);

        for ($next = $index; $next < $count; $next++) {
            $token = $tokens[$next];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return is_array($token) ? (string) $token[1] : $token;
        }

        return '';
    }

    private function relative(string $file): string
    {
        return str_replace(dirname(dirname(__DIR__)) . '/', '', $file);
    }
}
