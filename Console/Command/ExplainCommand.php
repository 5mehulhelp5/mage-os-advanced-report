<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Console\Command;

use DateTimeImmutable;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\SelectBuilder;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Preset\PresetQueryPool;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\QueryPlanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints, for one or all presets, the generated SQL and its `EXPLAIN` output to measure index usage.
 *
 * Heavy collaborators are injected as proxies through `di.xml`: commands are instantiated on every
 * `bin/magento` call, including before the module tables exist.
 */
class ExplainCommand extends Command
{
    private const string OPTION_PRESET = 'preset';
    private const string OPTION_FROM = 'from';
    private const string OPTION_TO = 'to';
    private const string DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private readonly State $state,
        private readonly DefinitionRegistry $definitionRegistry,
        private readonly PresetRequestBuilder $presetRequestBuilder,
        private readonly QueryPlanner $queryPlanner,
        private readonly SelectBuilder $selectBuilder,
        private readonly ResourceConnection $resourceConnection,
        private readonly Config $config,
        private readonly PresetQueryPool $presetQueryPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('advanced-report:explain')
            ->setDescription('Prints the SQL and EXPLAIN output of report presets')
            ->addOption(self::OPTION_PRESET, null, InputOption::VALUE_REQUIRED, 'Preset code (default: all presets)')
            ->addOption(self::OPTION_FROM, null, InputOption::VALUE_REQUIRED, 'Start date (Y-m-d)')
            ->addOption(self::OPTION_TO, null, InputOption::VALUE_REQUIRED, 'End date (Y-m-d)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = $input->getOption(self::OPTION_FROM);
        $to = $input->getOption(self::OPTION_TO);
        if (!$this->isValidDate($from) || !$this->isValidDate($to) || $from > $to) {
            $output->writeln('<error>Provide valid --from and --to dates (Y-m-d), with --from <= --to.</error>');

            return Command::FAILURE;
        }

        $this->setAdminAreaCode();

        try {
            $code = $input->getOption(self::OPTION_PRESET);
            $presets = $code !== null
                ? [$this->definitionRegistry->getPreset($code)]
                : array_values($this->definitionRegistry->getPresets());
            foreach ($presets as $preset) {
                $this->explainPreset($preset, $from, $to, $output);
            }
        } catch (LocalizedException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function explainPreset(Preset $preset, string $from, string $to, OutputInterface $output): void
    {
        $output->writeln('<info>Preset: ' . $preset->code . '</info>');

        $request = $this->presetRequestBuilder->build($preset, [
            'dateRange' => ['type' => 'custom', 'from' => $from, 'to' => $to],
        ]);
        $query = $this->queryPlanner->plan($request, false)['main'];

        if ($preset->presetQuery !== null) {
            $output->writeln('custom query');
            $presetQuery = $this->presetQueryPool->get($preset->presetQuery);
            if (!method_exists($presetQuery, 'getSelect')) {
                return;
            }
            $select = $presetQuery->getSelect($request, $query);
        } else {
            $select = $this->selectBuilder->buildPage($query);
        }

        $this->explainSelect($select, $output);
    }

    private function explainSelect(Select $select, OutputInterface $output): void
    {
        $sql = (string) $select->assemble();
        $output->writeln($sql);

        $connection = $this->resourceConnection->getConnectionByName($this->config->getConnectionName());
        $rows = $connection->fetchAll('EXPLAIN ' . $sql, $select->getBind());
        if ($rows === []) {
            return;
        }

        $table = new Table($output);
        $table->setHeaders(array_keys($rows[0]));
        $table->setRows(array_map(
            static fn (array $row): array => array_map(static fn (mixed $v): string => (string) $v, $row),
            $rows,
        ));
        $table->render();
    }

    private function isValidDate(mixed $value): bool
    {
        if (!\is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value);

        return $date !== false && $date->format(self::DATE_FORMAT) === $value;
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
