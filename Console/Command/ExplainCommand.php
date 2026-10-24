<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Console\Command;

use DateTimeImmutable;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AdvancedReport\Api\Data\ReportRequestInterface;
use MageOS\AdvancedReport\Model\Config;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\DataSource\Mysql\SelectBuilder;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavour;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ServerFlavourDetector;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneStrategy;
use MageOS\AdvancedReport\Model\DataSource\Mysql\TimezoneSupportDetector;
use MageOS\AdvancedReport\Model\Definition\Dataset;
use MageOS\AdvancedReport\Model\Definition\DefinitionRegistry;
use MageOS\AdvancedReport\Model\Definition\Preset;
use MageOS\AdvancedReport\Model\Preset\PresetQueryPool;
use MageOS\AdvancedReport\Model\Query\MonetaryRequestDetector;
use MageOS\AdvancedReport\Model\Query\PresetRequestBuilder;
use MageOS\AdvancedReport\Model\Query\QueryPlanner;
use MageOS\AdvancedReport\Model\Query\ResolvedQuery;
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
    private const string OPTION_COMPARE = 'compare';
    private const string OPTION_ANALYZE = 'analyze';
    private const string OPTION_FORMAT = 'format';
    private const string FORMAT_JSON = 'json';
    private const string DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private readonly State $state,
        private readonly DefinitionRegistry $definitionRegistry,
        private readonly PresetRequestBuilder $presetRequestBuilder,
        private readonly QueryPlanner $queryPlanner,
        private readonly SelectBuilder $selectBuilder,
        private readonly ReportConnection $reportConnection,
        private readonly PresetQueryPool $presetQueryPool,
        private readonly TimezoneStrategy $timezoneStrategy,
        private readonly MonetaryRequestDetector $monetaryRequestDetector,
        private readonly ServerFlavourDetector $flavourDetector,
        private readonly Config $config,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('advanced-report:explain')
            ->setDescription('Prints the SQL and EXPLAIN output of report presets')
            ->addOption(self::OPTION_PRESET, null, InputOption::VALUE_REQUIRED, 'Preset code (default: all presets)')
            ->addOption(self::OPTION_FROM, null, InputOption::VALUE_REQUIRED, 'Start date (Y-m-d)')
            ->addOption(self::OPTION_TO, null, InputOption::VALUE_REQUIRED, 'End date (Y-m-d)')
            ->addOption(
                self::OPTION_COMPARE,
                null,
                InputOption::VALUE_REQUIRED,
                'Comparison mode: previous_period or previous_year (default: the preset\'s own)'
            )
            ->addOption(
                self::OPTION_ANALYZE,
                null,
                InputOption::VALUE_NONE,
                'Use EXPLAIN ANALYZE. WARNING: this EXECUTES every statement (bounded by the query timeout)'
            )
            ->addOption(self::OPTION_FORMAT, null, InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table');
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
            $options = new ExplainOptions(
                (bool) $input->getOption(self::OPTION_ANALYZE),
                $input->getOption(self::OPTION_FORMAT) === self::FORMAT_JSON,
                $input->getOption(self::OPTION_COMPARE),
            );
            $entries = [];
            foreach ($presets as $preset) {
                array_push($entries, ...$this->explainPreset($preset, $from, $to, $options, $output));
            }
            if ($options->json) {
                $output->writeln((string) json_encode($entries, JSON_THROW_ON_ERROR));
            }
        } catch (LocalizedException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param Preset $preset
     * @param string $from
     * @param string $to
     * @param ExplainOptions $options
     * @param OutputInterface $output
     * @return array<int,array{preset: string, statement: string, sql: string, plan: array<int,array<string,mixed>>}>
     */
    private function explainPreset(Preset $preset, string $from, string $to, ExplainOptions $options, OutputInterface $output): array
    {
        $overrides = ['dateRange' => ['type' => 'custom', 'from' => $from, 'to' => $to]];
        if ($options->compare !== null) {
            $overrides['compareTo'] = $options->compare;
        }
        $request = $this->presetRequestBuilder->build($preset, $overrides);
        $plan = $this->queryPlanner->plan($request, false);

        if (!$options->json) {
            $output->writeln('<info>Preset: ' . $preset->code . '</info>');
        }
        $statements = $this->collectStatements($preset, $request, $plan);
        if ($statements === null) {
            $output->writeln($options->json ? '' : 'not explainable');

            return [];
        }

        $entries = [];
        foreach ($statements as $label => $select) {
            $entries[] = $this->explainSelect($preset->code, $label, $select, $options, $output);
        }

        return $entries;
    }

    /**
     * Labelled selects a report run executes; null when the preset query cannot expose its select.
     *
     * @param Preset $preset
     * @param ReportRequestInterface $request
     * @param array{main: ResolvedQuery, comparison: ?ResolvedQuery} $plan
     * @return array<string,Select>|null
     */
    private function collectStatements(Preset $preset, ReportRequestInterface $request, array $plan): ?array
    {
        $main = $plan['main'];
        if ($preset->presetQuery !== null) {
            $presetQuery = $this->presetQueryPool->get($preset->presetQuery);

            return method_exists($presetQuery, 'getSelect') ? ['preset_query' => $presetQuery->getSelect($main)] : null;
        }

        $statements = $this->pageStatements($main);
        if ($plan['comparison'] !== null) {
            $statements += [
                'comparison_page' => $this->selectBuilder->buildPage($plan['comparison']),
                'comparison_totals' => $this->selectBuilder->buildTotals($plan['comparison']),
            ];
        }
        if ($main->dataset->hasField(Dataset::FIELD_BASE_CURRENCY)
            && $this->monetaryRequestDetector->isMonetary($main->dataset, $request)
        ) {
            $statements['currency_probe'] = $this->selectBuilder->buildCurrencyProbe($main);
        }

        return $statements;
    }

    /**
     * Page, row count and totals of the main query (fallback select instead of page and row count).
     *
     * @param ResolvedQuery $query
     * @return array<string,Select>
     */
    private function pageStatements(ResolvedQuery $query): array
    {
        if ($this->timezoneStrategy->getName() === TimezoneSupportDetector::STRATEGY_UTC_HOUR_FALLBACK
            && $this->selectBuilder->hasDateBucketDimension($query)
        ) {
            return [
                'fallback' => $this->selectBuilder->buildUtcHourFallback($query)->select,
                'totals' => $this->selectBuilder->buildTotals($query),
            ];
        }

        return [
            'page' => $this->selectBuilder->buildPage($query),
            'row_count' => $this->selectBuilder->buildRowCount($query),
            'totals' => $this->selectBuilder->buildTotals($query),
        ];
    }

    /**
     * @param string $presetCode
     * @param string $label
     * @param Select $select
     * @param ExplainOptions $options
     * @param OutputInterface $output
     * @return array{preset: string, statement: string, sql: string, plan: array<int,array<string,mixed>>}
     */
    private function explainSelect(
        string $presetCode,
        string $label,
        Select $select,
        ExplainOptions $options,
        OutputInterface $output,
    ): array {
        $sql = (string) $select->assemble();
        $rows = $this->fetchPlan($sql, $select->getBind(), $options->analyze);
        if (!$options->json) {
            $output->writeln('<comment>Statement: ' . $this->displayLabel($label) . '</comment>');
            $output->writeln($sql);
            $this->renderRows($rows, $output);
        }

        return ['preset' => $presetCode, 'statement' => $label, 'sql' => $sql, 'plan' => $rows];
    }

    /**
     * @param string $sql
     * @param array<int|string,mixed> $bind
     * @param bool $analyze
     * @return array<int,array<string,mixed>>
     */
    private function fetchPlan(string $sql, array $bind, bool $analyze): array
    {
        $connection = $this->reportConnection->get();
        if (!$analyze) {
            return $connection->fetchAll('EXPLAIN ' . $sql, $bind);
        }
        $timeoutMs = $this->config->getQueryTimeoutMs();
        if ($this->flavourDetector->detect() === ServerFlavour::MariaDb) {
            return $connection->fetchAll(
                \sprintf('SET STATEMENT max_statement_time=%d FOR ANALYZE %s', (int) ceil($timeoutMs / 1000), $sql),
                $bind
            );
        }

        $previous = (int) $connection->fetchOne('SELECT @@SESSION.max_execution_time');
        $connection->query('SET SESSION max_execution_time = ' . $timeoutMs);
        try {
            return $connection->fetchAll('EXPLAIN ANALYZE ' . $sql, $bind);
        } finally {
            $connection->query('SET SESSION max_execution_time = ' . $previous);
        }
    }

    private function displayLabel(string $label): string
    {
        return $label === 'row_count' ? 'row_count (only when the page is full)' : $label;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param OutputInterface $output
     */
    private function renderRows(array $rows, OutputInterface $output): void
    {
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
