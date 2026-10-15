<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use MageOS\AdvancedReport\Console\Command\InventoryCommand;
use MageOS\AdvancedReport\Model\Audit\InventoryMarkdownRenderer;
use MageOS\AdvancedReport\Model\Audit\MetricInventoryBuilder;
use MageOS\AdvancedReport\Model\Audit\MetricInventoryEntry;
use MageOS\AdvancedReport\Model\Definition\AggregateFunction;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(InventoryCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class InventoryCommandTest extends TestCase
{
    private MetricInventoryBuilder&MockObject $builder;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->builder = $this->createMock(MetricInventoryBuilder::class);
        $this->builder->method('build')->willReturn([$this->entry('a'), $this->entry('b')]);

        $registry = $this->createMock(DefinitionRegistry::class);
        $registry->method('getDatasets')->willReturn(['a' => $this->dataset('a'), 'b' => $this->dataset('b')]);

        $command = new InventoryCommand(
            $this->createStub(State::class),
            $this->builder,
            $registry,
            new InventoryMarkdownRenderer(),
        );
        $this->tester = new CommandTester($command);
    }

    public function testItOutputsMarkdownByDefault(): void
    {
        $exit = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('## a (Dataset a)', $this->tester->getDisplay());
        self::assertStringContainsString('## b (Dataset b)', $this->tester->getDisplay());
    }

    public function testItOutputsValidJsonWhenFormatIsJson(): void
    {
        $exit = $this->tester->execute(['--format' => 'json']);

        $decoded = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(Command::SUCCESS, $exit);
        self::assertCount(2, $decoded);
        self::assertSame('a', $decoded[0]['datasetCode']);
        self::assertSame('sum', $decoded[0]['aggregateFunction']);
    }

    public function testItFiltersOutputToTheRequestedDataset(): void
    {
        $this->tester->execute(['--dataset' => 'b']);
        $display = $this->tester->getDisplay();

        self::assertStringContainsString('## b (', $display);
        self::assertStringNotContainsString('## a (', $display);

        $this->tester->execute(['--dataset' => 'b', '--format' => 'json']);
        $decoded = json_decode($this->tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['b'], array_column($decoded, 'datasetCode'));
    }

    public function testItWritesTheRenderedOutputToTheFileGivenByTheOutputOption(): void
    {
        $path = sys_get_temp_dir() . '/inventory-' . uniqid('', true) . '.md';

        try {
            $exit = $this->tester->execute(['--output' => $path]);

            self::assertSame(Command::SUCCESS, $exit);
            self::assertStringContainsString('## a (Dataset a)', (string) file_get_contents($path));
            self::assertStringNotContainsString('## a (', $this->tester->getDisplay());
        } finally {
            @unlink($path);
        }
    }

    public function testItFailsWithErrorExitCodeForUnknownDatasetOrFormat(): void
    {
        self::assertSame(Command::FAILURE, $this->tester->execute(['--dataset' => 'nope']));
        self::assertStringContainsString('nope', $this->tester->getDisplay());

        self::assertSame(Command::FAILURE, $this->tester->execute(['--format' => 'xml']));
        self::assertStringContainsString('xml', $this->tester->getDisplay());
    }

    public function testItFailsWithErrorExitCodeWhenDefinitionsAreInvalid(): void
    {
        $builder = $this->createMock(MetricInventoryBuilder::class);
        $builder->method('build')->willThrowException(new LocalizedException(new Phrase('Broken definitions')));
        $command = new InventoryCommand(
            $this->createStub(State::class),
            $builder,
            $this->createStub(DefinitionRegistry::class),
            new InventoryMarkdownRenderer(),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Broken definitions', $tester->getDisplay());
    }

    private function dataset(string $code): Dataset
    {
        return new Dataset($code, 'Dataset ' . $code, 'acl', 'admin', false, 't', 'main', null, [], [], [], [], []);
    }

    private function entry(string $dataset): MetricInventoryEntry
    {
        return new MetricInventoryEntry(
            $dataset,
            'm',
            'M',
            MetricInventoryEntry::KIND_AGGREGATE,
            false,
            AggregateFunction::SUM,
            'main.f',
            't',
            null,
            [],
            [],
            [],
            [],
            false,
        );
    }
}
