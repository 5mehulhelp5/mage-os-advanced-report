<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Etc;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MageOS\AdvancedReport\Test\Unit\Helper\XPathTestTrait;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class I18nCoverageTest extends TestCase
{
    use XPathTestTrait;

    private const string MODULE_DIR = __DIR__ . '/../../..';

    public function testItHasAnEnUsEntryForEveryTranslatablePhraseInTheModule(): void
    {
        $keys = array_keys($this->loadCsv('en_US'));
        $missing = array_values(array_diff($this->collectPhrases(), $keys));

        self::assertSame([], $missing, 'Phrases missing from i18n/en_US.csv');
    }

    public function testItHasAnItItTranslationForEveryEnUsEntry(): void
    {
        $enUs = $this->loadCsv('en_US');
        $itIt = $this->loadCsv('it_IT');

        self::assertSame([], array_values(array_diff(array_keys($enUs), array_keys($itIt))));
        self::assertSame([], array_values(array_diff(array_keys($itIt), array_keys($enUs))));
        foreach ($itIt as $phrase => $translation) {
            self::assertNotSame('', $translation, \sprintf('Empty it_IT translation for "%s"', $phrase));
        }
    }

    public function testItHasNoDuplicateKeysInTheI18nCsvFiles(): void
    {
        foreach (['en_US', 'it_IT'] as $locale) {
            $handle = fopen(self::MODULE_DIR . '/i18n/' . $locale . '.csv', 'rb');
            self::assertIsResource($handle);
            $keys = [];
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if (\count($row) >= 2) {
                    $keys[] = (string) $row[0];
                }
            }
            fclose($handle);

            self::assertSame(
                [],
                array_keys(array_filter(array_count_values($keys), static fn (int $n): bool => $n > 1)),
                \sprintf('Duplicate keys in i18n/%s.csv', $locale)
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function loadCsv(string $locale): array
    {
        $handle = fopen(self::MODULE_DIR . '/i18n/' . $locale . '.csv', 'rb');
        self::assertIsResource($handle);
        $rows = [];
        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (\count($row) >= 2) {
                $rows[(string) $row[0]] = (string) $row[1];
            }
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return string[]
     */
    private function collectPhrases(): array
    {
        $phrases = [];
        foreach ($this->sourceFiles() as $file) {
            $contents = (string) file_get_contents($file);
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            $found = match ($extension) {
                'php', 'phtml' => $this->phpPhrases($contents),
                'js' => $this->jsPhrases($contents),
                'xml' => $this->xmlPhrases($file),
                default => [],
            };
            $phrases = [...$phrases, ...$found];
        }

        return array_values(array_unique($phrases));
    }

    /**
     * @return string[]
     */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::MODULE_DIR, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (str_contains($path, '/Test/') || str_contains($path, '/.git/')) {
                continue;
            }
            if (\in_array($file->getExtension(), ['php', 'phtml', 'js', 'xml'], true)) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Literal first arguments of __() and new Phrase(), including concatenated literals.
     *
     * @return string[]
     */
    private function phpPhrases(string $contents): array
    {
        $literal = '\'(?:[^\'\\\\]|\\\\.)*\'';
        $pattern = '/(?:(?<![\w>:$])__|new\s+\\\\?(?:[\w\\\\]*\\\\)?Phrase)\(\s*((?:' . $literal . '\s*\.?\s*)+)/';
        preg_match_all($pattern, $contents, $calls);

        return array_map($this->joinLiterals(...), $calls[1]);
    }

    /**
     * @return string[]
     */
    private function jsPhrases(string $contents): array
    {
        preg_match_all('/\$t\(\s*(\'(?:[^\'\\\\]|\\\\.)*\')/', $contents, $calls);

        return array_map($this->joinLiterals(...), $calls[1]);
    }

    private function joinLiterals(string $expression): string
    {
        preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'/', $expression, $parts);

        return stripslashes(implode('', $parts[1]));
    }

    /**
     * Labels, titles, comments and descriptions declared in XML files.
     *
     * @return string[]
     */
    private function xmlPhrases(string $file): array
    {
        $document = new DOMDocument();
        $document->load($file);
        $xpath = new DOMXPath($document);
        $phrases = [];

        if (str_ends_with($file, 'report_datasets.xml')) {
            foreach (self::queryNodes($xpath, '//*[@label or @description]') as $node) {
                self::assertInstanceOf(DOMElement::class, $node);
                $phrases[] = $node->getAttribute('label');
                $phrases[] = $node->getAttribute('description');
            }
        } elseif (str_ends_with($file, 'acl.xml') || str_ends_with($file, 'menu.xml')) {
            foreach (self::queryNodes($xpath, '//*[@translate="title"]') as $node) {
                self::assertInstanceOf(DOMElement::class, $node);
                $phrases[] = $node->getAttribute('title');
            }
        } elseif (str_ends_with($file, 'system.xml') || str_ends_with($file, 'cache.xml')) {
            foreach (self::queryNodes($xpath, '//label | //comment | //description') as $node) {
                self::assertInstanceOf(DOMElement::class, $node);
                $phrases[] = trim($node->textContent);
            }
        }

        return array_values(array_filter($phrases, static fn (string $phrase): bool => $phrase !== ''));
    }
}
