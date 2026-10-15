<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AdvancedReport\Model\Audit\InventoryMarkdownRenderer;
use MageOS\AdvancedReport\Model\Audit\MetricInventoryBuilder;
use MageOS\AdvancedReport\Model\Audit\MetricInventoryEntry;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the metric inventory (tables, joins, aggregation, fan-out risk) as Markdown or JSON.
 *
 * Collaborators that read the definitions are injected as proxies through `di.xml`: commands are
 * instantiated on every `bin/magento` call, including before the module is installed.
 */
class InventoryCommand extends Command
{
    private const string OPTION_FORMAT = 'format';
    private const string OPTION_DATASET = 'dataset';
    private const string OPTION_OUTPUT = 'output';
    private const string FORMAT_MARKDOWN = 'md';
    private const string FORMAT_JSON = 'json';

    public function __construct(
        private readonly State $state,
        private readonly MetricInventoryBuilder $inventoryBuilder,
        private readonly DefinitionRegistry $definitionRegistry,
        private readonly InventoryMarkdownRenderer $markdownRenderer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('advanced-report:inventory')
            ->setDescription((string) __('Prints the metric inventory of the report datasets'))
            ->addOption(
                self::OPTION_FORMAT,
                null,
                InputOption::VALUE_REQUIRED,
                (string) __('Output format: md or json'),
                self::FORMAT_MARKDOWN
            )
            ->addOption(self::OPTION_DATASET, null, InputOption::VALUE_REQUIRED, (string) __('Dataset code (default: all datasets)'))
            ->addOption(
                self::OPTION_OUTPUT,
                null,
                InputOption::VALUE_REQUIRED,
                (string) __('Write the output to this file instead of stdout')
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption(self::OPTION_FORMAT);
        if (!\in_array($format, [self::FORMAT_MARKDOWN, self::FORMAT_JSON], true)) {
            $output->writeln('<error>' . __('Unknown format "%1", use md or json.', $format) . '</error>');

            return Command::FAILURE;
        }

        $this->setAdminAreaCode();

        try {
            $datasets = $this->definitionRegistry->getDatasets();
            $code = $input->getOption(self::OPTION_DATASET);
            if ($code !== null && !isset($datasets[$code])) {
                $output->writeln('<error>' . __('Unknown dataset "%1".', $code) . '</error>');

                return Command::FAILURE;
            }

            $entries = array_values(array_filter(
                $this->inventoryBuilder->build(),
                static fn (MetricInventoryEntry $entry): bool => $code === null || $entry->datasetCode === $code
            ));
            $document = $format === self::FORMAT_JSON
                ? $this->renderJson($entries)
                : $this->markdownRenderer->render(
                    $entries,
                    array_map(static fn (Dataset $dataset): string => $dataset->label, $datasets)
                );
        } catch (LocalizedException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return $this->write($document, $input->getOption(self::OPTION_OUTPUT), $output);
    }

    private function write(string $document, mixed $path, OutputInterface $output): int
    {
        if ($path === null) {
            $output->write($document, false, OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }
        if (@file_put_contents((string) $path, $document) === false) {
            $output->writeln('<error>' . __('Cannot write to "%1".', $path) . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param MetricInventoryEntry[] $entries
     */
    private function renderJson(array $entries): string
    {
        return json_encode(
            array_map(get_object_vars(...), $entries),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    private function setAdminAreaCode(): void
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException) {
            // Area code already set.
        }
    }
}
