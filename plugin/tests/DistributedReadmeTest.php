<?php

declare(strict_types=1);

namespace Foreningssystem\Tests;

use Foreningssystem\Infrastructure\WordPress\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * What someone reads before installing: the GitHub page and the readme that travels inside
 * the plugin ZIP. Both must tell the same story in the same order, and neither may lose the
 * warning that 0.1.0 is not for a live association.
 */
final class DistributedReadmeTest extends TestCase
{
    private const WARNING = 'is an early development build. It is NOT ready for production use or live association data';

    private function repositoryRoot(): string
    {
        return dirname(dirname(__DIR__));
    }

    private function githubReadme(): string
    {
        return (string) file_get_contents($this->repositoryRoot() . '/README.md');
    }

    private function packagedReadme(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/readme.txt');
    }

    private function buildScript(): string
    {
        return (string) file_get_contents($this->repositoryRoot() . '/scripts/build-plugin-zip.sh');
    }

    /**
     * @param list<string> $headings
     */
    private function assertHeadingsInOrder(array $headings, string $text, string $where): void
    {
        $previous = -1;
        $previousHeading = '';

        foreach ($headings as $heading) {
            $position = strpos($text, $heading);

            self::assertIsInt($position, $where . ' has no section ' . trim($heading));
            self::assertGreaterThan(
                $previous,
                $position,
                $where . ' lists ' . trim($heading) . ' before ' . trim($previousHeading)
            );

            $previous = $position;
            $previousHeading = $heading;
        }
    }

    public function test_the_github_readme_follows_the_agreed_section_order(): void
    {
        $this->assertHeadingsInOrder(
            [
                "\n## Vad Föreningsplugin är\n",
                "\n## Status\n",
                "\n## Viktig utvecklingsvarning\n",
                "\n## Funktioner\n",
                "\n## Snabbstart\n",
                "\n## Integritet och åtkomst\n",
                "\n## Dokumentation\n",
                "\n## Utveckling\n",
                "\n## Licens\n",
            ],
            $this->githubReadme(),
            'README.md'
        );
    }

    public function test_the_packaged_readme_follows_the_same_section_order(): void
    {
        $this->assertHeadingsInOrder(
            [
                "\n== Vad Föreningsplugin är ==\n",
                "\n== Status ==\n",
                "\n== Viktig utvecklingsvarning ==\n",
                "\n== Funktioner ==\n",
                "\n== Installation ==\n",
                "\n== Integritet och åtkomst ==\n",
                "\n== Dokumentation ==\n",
                "\n== Utveckling ==\n",
                "\n== Licens ==\n",
            ],
            $this->packagedReadme(),
            'plugin/readme.txt'
        );
    }

    public function test_both_readmes_carry_the_development_warning(): void
    {
        self::assertStringContainsString('Föreningsplugin 0.1.0 ' . self::WARNING, $this->githubReadme());
        self::assertStringContainsString('Föreningsplugin 0.1.0 ' . self::WARNING, $this->packagedReadme());
    }

    /**
     * High up, not hidden at the end: before the feature list in both files, and in a
     * GitHub callout rather than an ordinary paragraph.
     */
    public function test_the_warning_stands_above_the_feature_list(): void
    {
        $github = $this->githubReadme();
        $packaged = $this->packagedReadme();

        self::assertLessThan(
            (int) strpos($github, "\n## Funktioner\n"),
            (int) strpos($github, self::WARNING),
            'README.md buries the development warning below the features.'
        );
        self::assertLessThan(
            (int) strpos($packaged, "\n== Funktioner ==\n"),
            (int) strpos($packaged, self::WARNING),
            'plugin/readme.txt buries the development warning below the features.'
        );

        self::assertMatchesRegularExpression(
            '/> \[!WARNING\]\n(>.*\n)*> \*\*Föreningsplugin 0\.1\.0 is an early development build/u',
            $github,
            'README.md states the warning outside a callout.'
        );
    }

    public function test_the_warning_explains_what_is_not_finished(): void
    {
        foreach (['README.md' => $this->githubReadme(), 'plugin/readme.txt' => $this->packagedReadme()] as $where => $text) {
            self::assertStringContainsString('datamodell och migreringar kan fortfarande ändras', $text, $where);
            self::assertStringContainsString('integritet och säkerhet pågår', $text, $where);
            self::assertStringContainsString('personnummer', $text, $where);
            self::assertStringContainsString('testinstallation', $text, $where);
        }
    }

    public function test_no_readme_claims_a_stable_download_or_a_production_ready_build(): void
    {
        foreach (['README.md' => $this->githubReadme(), 'plugin/readme.txt' => $this->packagedReadme()] as $where => $text) {
            foreach (['/download stable/i', '/stable tag/i', '/production[ -]ready/i', '/produktionsklar/i', '/stabil version/i'] as $forbidden) {
                self::assertDoesNotMatchRegularExpression($forbidden, $text, $where . ' makes a stability claim.');
            }
        }
    }

    public function test_the_github_readme_keeps_the_badge_row_above_the_first_section(): void
    {
        $github = $this->githubReadme();
        $badges = strpos($github, '[![Tests](https://img.shields.io/github/actions/workflow/status/');

        self::assertIsInt($badges, 'README.md lost the badge row.');
        self::assertLessThan((int) strpos($github, "\n## "), $badges, 'The badge row sank below the first section.');

        foreach (['PHP-8.3', 'WordPress-7.1', 'GPL--2.0--or--later'] as $badge) {
            self::assertStringContainsString($badge, $github, 'README.md lost the ' . $badge . ' badge.');
        }
    }

    public function test_the_packaged_readme_names_the_running_plugin_version(): void
    {
        self::assertStringContainsString("\nVersion: " . Plugin::VERSION . "\n", $this->packagedReadme());
    }

    /**
     * The readme only helps if it travels with the archive, so the packaging contract has to
     * stage it, require it in the built ZIP, and refuse a ZIP whose readme lost the warning.
     */
    public function test_the_packaging_contract_ships_the_readme(): void
    {
        $script = $this->buildScript();

        self::assertStringContainsString('plugin/readme.txt "$stage/foreningsplugin/"', $script);
        self::assertStringContainsString('require_entry "foreningsplugin/readme.txt"', $script);
        self::assertStringContainsString('unzip -p "$zip_path" foreningsplugin/readme.txt', $script);
        self::assertStringContainsString(self::WARNING, $script);
    }
}
