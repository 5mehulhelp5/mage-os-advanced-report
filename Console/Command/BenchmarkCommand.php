<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Console\Command;

use DateTimeImmutable;
use Magento\Framework\App\Area;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use MageOS\AdvancedReport\Model\Benchmark\BarrierClock;
use MageOS\AdvancedReport\Model\Benchmark\BenchmarkRunner;
use MageOS\AdvancedReport\Model\Benchmark\ConcurrencySummary;
use MageOS\AdvancedReport\Model\Benchmark\ConcurrentRunner;
use MageOS\AdvancedReport\Model\Benchmark\Measurement;
use MageOS\AdvancedReport\Model\Benchmark\Scenario;
use MageOS\AdvancedReport\Model\Benchmark\ScenarioProvider;
use MageOS\AdvancedReport\Model\Benchmark\ScenarioResult;
use MageOS\AdvancedReport\Model\DataSource\Mysql\Guardrails;
use MageOS\AdvancedReport\Model\DataSource\Mysql\ReportConnection;
use MageOS\AdvancedReport\Model\Query\CompareMode;
use MageOS\AdvancedReport\Model\Query\DateRange;
use MageOS\AdvancedReport\Model\Query\DateRangeType;
use MageOS\AdvancedReport\Model\Security\TrustedCliContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs the benchmark scenario matrix through the real report runner (read-only) and prints cold and warm
 * measurements. The cold median of every scenario can be checked against a performance budget.
 *
 * The CLI is trusted (like the other commands): after the area code is set, the {@see TrustedCliContext}
 * is enabled because no admin session exists to authorize the report datasets. Heavy collaborators are
 * injected as proxies through `di.xml`.
 */
class BenchmarkCommand extends Command
{
    private const string OPTION_PRESET = 'preset';
    private const string OPTION_RANGE = 'range';
    private const string OPTION_FROM = 'from';
    private const string OPTION_TO = 'to';
    private const string OPTION_STORE = 'store';
    private const string OPTION_COMPARE = 'compare';
    private const string OPTION_REPEAT = 'repeat';
    private const string OPTION_FORMAT = 'format';
    private const string OPTION_OUTPUT = 'output';
    private const string OPTION_BUDGET = 'budget-ms';
    private const string OPTION_LABEL = 'profile-label';
    private const string OPTION_CONCURRENCY = 'concurrency';
    private const string OPTION_WORKER = 'worker';
    private const string OPTION_START_AT = 'start-at';
    private const string RANGE_LAST_30_DAYS = 'last_30_days';
    private const string RANGE_LAST_12_MONTHS = 'last_12_months';
    private const string RANGE_CUSTOM = 'custom';
    private const string FORMAT_TABLE = 'table';
    private const string FORMAT_JSON = 'json';
    private const string FORMAT_MARKDOWN = 'markdown';
    private const string DATE_FORMAT = 'Y-m-d';
    private const string NOT_AVAILABLE = 'n/a';
    private const int DEFAULT_REPEAT = 3;
    private const array HEADERS = [
        'Scenario',
        'Cold median (ms)',
        'Cold p95 (ms)',
        'SQL statements',
        'Rows examined',
        'Warm median (ms)',
        'Warm SQL statements',
        'Peak memory (MB)',
    ];

    public function __construct(
        private readonly State $state,
        private readonly TrustedCliContext $trustedCliContext,
        private readonly ScenarioProvider $scenarioProvider,
        private readonly BenchmarkRunner $benchmarkRunner,
        private readonly ReportConnection $reportConnection,
        private readonly Guardrails $guardrails,
        private readonly Filesystem $filesystem,
        private readonly ConcurrentRunner $concurrentRunner,
        private readonly BarrierClock $barrierClock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('advanced-report:benchmark')
            ->setDescription('Benchmarks report scenarios (cold and warm cache), read-only')
            ->addOption(
                self::OPTION_PRESET,
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Preset code, repeatable (default: full matrix)'
            )
            ->addOption(
                self::OPTION_RANGE,
                null,
                InputOption::VALUE_REQUIRED,
                'Date range: last_30_days, last_12_months or custom (default: all)'
            )
            ->addOption(self::OPTION_FROM, null, InputOption::VALUE_REQUIRED, 'Start date (Y-m-d), with --range=custom')
            ->addOption(self::OPTION_TO, null, InputOption::VALUE_REQUIRED, 'End date (Y-m-d), with --range=custom')
            ->addOption(
                self::OPTION_STORE,
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Store id, repeatable (default: the matrix stores)'
            )
            ->addOption(
                self::OPTION_COMPARE,
                null,
                InputOption::VALUE_REQUIRED,
                'Comparison: none, previous_period or previous_year (default: the matrix ones)'
            )
            ->addOption(
                self::OPTION_REPEAT,
                null,
                InputOption::VALUE_REQUIRED,
                'Cold and warm runs per scenario',
                (string) self::DEFAULT_REPEAT
            )
            ->addOption(self::OPTION_FORMAT, null, InputOption::VALUE_REQUIRED, 'table, json or markdown', 'table')
            ->addOption(self::OPTION_OUTPUT, null, InputOption::VALUE_REQUIRED, 'File to write, relative to var/')
            ->addOption(
                self::OPTION_BUDGET,
                null,
                InputOption::VALUE_REQUIRED,
                'Cold median budget in ms: scenarios over it are listed and the exit code is 1'
            )
            ->addOption(self::OPTION_LABEL, null, InputOption::VALUE_REQUIRED, 'Data profile label (small, medium, large...)')
            ->addOption(
                self::OPTION_CONCURRENCY,
                null,
                InputOption::VALUE_REQUIRED,
                'Run one scenario in N parallel worker processes (1-32); cleans the whole advanced_report cache type'
            )
            ->addOption(self::OPTION_WORKER, null, InputOption::VALUE_NONE, 'Internal: one measurement, one JSON line')
            ->addOption(self::OPTION_START_AT, null, InputOption::VALUE_REQUIRED, 'Internal: worker start instant (epoch ms)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $error = $this->validate($input);
        if ($error !== null) {
            $output->writeln('<error>' . $error . '</error>');

            return Command::FAILURE;
        }

        $this->setAdminAreaCode();
        $this->trustedCliContext->enable();

        $scenarios = $this->filterScenarios($input);
        if ($scenarios === []) {
            $output->writeln('<error>No benchmark scenario matches the given options.</error>');

            return Command::FAILURE;
        }

        if ($input->getOption(self::OPTION_WORKER)) {
            return $this->runWorker($scenarios, $input, $output);
        }
        if ($input->getOption(self::OPTION_CONCURRENCY) !== null) {
            return $this->runConcurrent($scenarios, $input, $output);
        }

        $repeat = (int) $input->getOption(self::OPTION_REPEAT);
        $results = [];
        foreach ($scenarios as $scenario) {
            $results[] = $this->benchmarkRunner->run($scenario, $repeat);
        }

        $budget = $input->getOption(self::OPTION_BUDGET);
        $overBudget = $budget === null ? [] : $this->overBudget($results, (float) $budget);
        $content = $this->render(
            $results,
            $this->buildHeader((string) $input->getOption(self::OPTION_LABEL)),
            $overBudget,
            (string) $input->getOption(self::OPTION_FORMAT),
        );

        $this->emit($content, $input, $output);

        return $overBudget === [] ? Command::SUCCESS : Command::FAILURE;
    }

    private function emit(string $content, InputInterface $input, OutputInterface $output): void
    {
        $file = $input->getOption(self::OPTION_OUTPUT);
        if ($file === null) {
            $output->writeln($content);
        } else {
            $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR)->writeFile($file, $content . "\n");
            $output->writeln('Benchmark written to var/' . $file);
        }
    }

    /**
     * Worker process of the concurrent mode: waits for the shared start instant, then measures once.
     *
     * @param Scenario[] $scenarios
     */
    private function runWorker(array $scenarios, InputInterface $input, OutputInterface $output): int
    {
        if (\count($scenarios) !== 1) {
            $output->writeln('<error>A worker needs options selecting exactly one scenario.</error>');

            return Command::FAILURE;
        }
        $startAt = $input->getOption(self::OPTION_START_AT);
        if ($startAt !== null) {
            $this->barrierClock->waitUntil((int) $startAt);
        }
        $measuredAt = $this->barrierClock->nowMs();
        $measurement = $this->benchmarkRunner->runOnce($scenarios[0]);
        $output->writeln((string) json_encode([
            'wall_ms' => $measurement->wallMs,
            'cached' => $measurement->cached,
            'sql_statements' => $measurement->sqlQueryCount,
            'measured_at_ms' => $measuredAt,
        ], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    /**
     * @param Scenario[] $scenarios
     */
    private function runConcurrent(array $scenarios, InputInterface $input, OutputInterface $output): int
    {
        if (\count($scenarios) !== 1) {
            $output->writeln(
                '<error>--concurrency needs options selecting exactly one scenario (e.g. --preset, --range, --store, --compare).</error>'
            );

            return Command::FAILURE;
        }

        $output->writeln(
            '<comment>Notice: the whole advanced_report cache type is cleaned first, so all cached reports were dropped.</comment>'
        );
        try {
            $summary = $this->concurrentRunner->run(
                $this->scenarioArguments($input),
                (int) $input->getOption(self::OPTION_CONCURRENCY)
            );
        } catch (\RuntimeException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $this->emit(
            $this->renderConcurrency(
                $scenarios[0],
                $summary,
                $this->buildHeader((string) $input->getOption(self::OPTION_LABEL)),
                (string) $input->getOption(self::OPTION_FORMAT)
            ),
            $input,
            $output
        );

        return Command::SUCCESS;
    }

    /**
     * @param InputInterface $input
     * @return string[] Options that make a worker select the same scenario.
     */
    private function scenarioArguments(InputInterface $input): array
    {
        $arguments = [];
        foreach ($input->getOption(self::OPTION_PRESET) as $preset) {
            $arguments[] = '--preset=' . $preset;
        }
        foreach ([self::OPTION_RANGE, self::OPTION_FROM, self::OPTION_TO] as $name) {
            if ($input->getOption($name) !== null) {
                $arguments[] = '--' . $name . '=' . $input->getOption($name);
            }
        }
        foreach ($input->getOption(self::OPTION_STORE) as $storeId) {
            $arguments[] = '--store=' . $storeId;
        }
        if ($input->getOption(self::OPTION_COMPARE) !== null) {
            $arguments[] = '--compare=' . $input->getOption(self::OPTION_COMPARE);
        }

        return $arguments;
    }

    /**
     * @param Scenario $scenario
     * @param ConcurrencySummary $summary
     * @param array<string,mixed> $header
     * @param string $format
     */
    private function renderConcurrency(
        Scenario $scenario,
        ConcurrencySummary $summary,
        array $header,
        string $format
    ): string {
        $data = $summary->toArray();
        if ($format === self::FORMAT_JSON) {
            return (string) json_encode(
                ['header' => $header, 'scenario' => $scenario->code] + $data,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
            );
        }

        $buffer = new BufferedOutput();
        $table = new Table($buffer);
        $table->setHeaders(['Worker', 'Wall (ms)', 'Cached', 'SQL statements', 'Late']);
        foreach ($data['workers'] as $index => $worker) {
            $table->addRow([
                $index + 1,
                $this->formatMs($worker['wall_ms']),
                $worker['cached'] ? 'yes' : 'no',
                $worker['sql_statements'],
                $worker['late'] ? 'yes' : 'no',
            ]);
        }
        $table->render();

        return implode("\n", [
            'Profile: ' . $header['profile'],
            'sales_order rows: ' . $header['sales_order_count'],
            'sales_order_item rows: ' . $header['sales_order_item_count'],
            'Server: ' . $header['server_version'],
            'Scenario: ' . $scenario->code,
            '',
            rtrim($buffer->fetch()),
            '',
            'Executed SQL: ' . $summary->executedSql(),
            'Served from cache: ' . $summary->servedFromCache(),
            'Late workers: ' . $summary->lateWorkers(),
            'Max wall (ms): ' . $this->formatMs($summary->maxWallMs()),
            'Median wall (ms): ' . $this->formatMs($summary->medianWallMs()),
        ]);
    }

    private function validate(InputInterface $input): ?string
    {
        $range = $input->getOption(self::OPTION_RANGE);
        $from = $input->getOption(self::OPTION_FROM);
        $to = $input->getOption(self::OPTION_TO);
        $budget = $input->getOption(self::OPTION_BUDGET);
        $file = $input->getOption(self::OPTION_OUTPUT);

        return match (true) {
            $range !== null
                && !\in_array($range, [self::RANGE_LAST_30_DAYS, self::RANGE_LAST_12_MONTHS, self::RANGE_CUSTOM], true)
                => 'Invalid --range: use last_30_days, last_12_months or custom.',
            $range === self::RANGE_CUSTOM && (!$this->isValidDate($from) || !$this->isValidDate($to) || $from > $to)
                => 'Provide valid --from and --to dates (Y-m-d), with --from <= --to.',
            $input->getOption(self::OPTION_COMPARE) !== null
                && CompareMode::tryFrom((string) $input->getOption(self::OPTION_COMPARE)) === null
                => 'Invalid --compare: use none, previous_period or previous_year.',
            filter_var($input->getOption(self::OPTION_REPEAT), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                => 'Invalid --repeat: use an integer >= 1.',
            !\in_array($input->getOption(self::OPTION_FORMAT), [self::FORMAT_TABLE, self::FORMAT_JSON, self::FORMAT_MARKDOWN], true)
                => 'Invalid --format: use table, json or markdown.',
            $budget !== null && !is_numeric($budget) => 'Invalid --budget-ms: use a number.',
            array_filter($input->getOption(self::OPTION_STORE), static fn (string $id): bool => !ctype_digit($id)) !== []
                => 'Invalid --store: use numeric store ids.',
            $input->getOption(self::OPTION_CONCURRENCY) !== null && !$this->isValidConcurrency($input)
                => 'Invalid --concurrency: use an integer between 1 and 32.',
            $input->getOption(self::OPTION_START_AT) !== null && !ctype_digit((string) $input->getOption(self::OPTION_START_AT))
                => 'Invalid --start-at: use a unix epoch in milliseconds.',
            $file !== null && !$this->isRelativeVarPath((string) $file)
                => 'Invalid --output: use a path relative to var/ without ".." segments.',
            default => null,
        };
    }

    /**
     * @param InputInterface $input
     * @return Scenario[]
     */
    private function filterScenarios(InputInterface $input): array
    {
        $presets = $input->getOption(self::OPTION_PRESET);
        $range = $input->getOption(self::OPTION_RANGE);
        $stores = array_map(intval(...), $input->getOption(self::OPTION_STORE));
        $compare = $input->getOption(self::OPTION_COMPARE);

        $scenarios = [];
        foreach ($this->scenarioProvider->getScenarios() as $scenario) {
            if (($presets !== [] && !\in_array($scenario->preset, $presets, true)) || !$this->matchesRange($scenario, $range)) {
                continue;
            }
            $scenario = $this->override($scenario, $input, $stores, $compare);
            $scenarios[$scenario->code] = $scenario;
        }

        return array_values($scenarios);
    }

    private function matchesRange(Scenario $scenario, ?string $range): bool
    {
        return match ($range) {
            self::RANGE_LAST_30_DAYS => $scenario->dateRange->type === DateRangeType::LAST_30_DAYS,
            self::RANGE_LAST_12_MONTHS => $scenario->dateRange->type === DateRangeType::CUSTOM,
            default => true,
        };
    }

    /**
     * Applies the stores, comparison and custom range options; the code gets a suffix so overridden
     * scenarios that collapse into the same case are run once.
     *
     * @param Scenario $scenario
     * @param InputInterface $input
     * @param int[] $stores
     * @param string|null $compare
     */
    private function override(Scenario $scenario, InputInterface $input, array $stores, ?string $compare): Scenario
    {
        $code = $scenario->code;
        $dateRange = $scenario->dateRange;
        $storeIds = $scenario->storeIds;
        $compareTo = $scenario->compareTo;
        if ($input->getOption(self::OPTION_RANGE) === self::RANGE_CUSTOM) {
            $dateRange = new DateRange(
                DateRangeType::CUSTOM,
                $input->getOption(self::OPTION_FROM),
                $input->getOption(self::OPTION_TO),
            );
            $code .= '@' . $dateRange->from . '..' . $dateRange->to;
        }
        if ($stores !== []) {
            $storeIds = $stores;
            $code = $this->stripSegment($code) . '@stores=' . implode(',', $stores);
        }
        if ($compare !== null) {
            $compareTo = CompareMode::from($compare);
            $code = $this->stripSegment($code, '@compare=') . '@compare=' . $compare;
        }

        return new Scenario($code, $scenario->preset, $scenario->request, $dateRange, $storeIds, $compareTo);
    }

    private function stripSegment(string $code, string $marker = '@stores='): string
    {
        $position = strpos($code, $marker);

        return $position === false ? $code : substr($code, 0, $position);
    }

    /**
     * @param ScenarioResult[] $results
     * @param float $budgetMs
     * @return string[] Codes of the scenarios whose cold median is over budget.
     */
    private function overBudget(array $results, float $budgetMs): array
    {
        $codes = [];
        foreach ($results as $result) {
            $median = $result->coldStats()['median'] ?? 0.0;
            if ($median > $budgetMs) {
                $codes[] = $result->scenario->code;
            }
        }

        return $codes;
    }

    /**
     * @param string $label
     * @return array{profile: string, sales_order_count: int, sales_order_item_count: int, server_version: string}
     */
    private function buildHeader(string $label): array
    {
        $connection = $this->reportConnection->get();

        return [
            'profile' => $label === '' ? self::NOT_AVAILABLE : $label,
            'sales_order_count' => $this->countRows('sales_order'),
            'sales_order_item_count' => $this->countRows('sales_order_item'),
            'server_version' => (string) $connection->fetchOne('SELECT VERSION()'),
        ];
    }

    private function countRows(string $table): int
    {
        $connection = $this->reportConnection->get();
        $sql = 'SELECT COUNT(*) FROM ' . $connection->getTableName($table);

        return (int) $connection->fetchOne($this->guardrails->applyTimeout($sql));
    }

    /**
     * @param ScenarioResult[] $results
     * @param array<string,mixed> $header
     * @param string[] $overBudget
     * @param string $format
     */
    private function render(array $results, array $header, array $overBudget, string $format): string
    {
        if ($format === self::FORMAT_JSON) {
            return (string) json_encode(
                [
                    'header' => $header,
                    'results' => array_map($this->toArray(...), $results),
                    'over_budget' => $overBudget,
                ],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT
            );
        }

        $lines = [
            'Profile: ' . $header['profile'],
            'sales_order rows: ' . $header['sales_order_count'],
            'sales_order_item rows: ' . $header['sales_order_item_count'],
            'Server: ' . $header['server_version'],
            '',
            $format === self::FORMAT_MARKDOWN ? $this->markdown($results) : $this->table($results),
        ];
        if ($overBudget !== []) {
            $lines[] = '';
            $lines[] = 'Over budget (cold median): ' . implode(', ', $overBudget);
        }

        return implode("\n", $lines);
    }

    /**
     * @param ScenarioResult[] $results
     */
    private function table(array $results): string
    {
        $buffer = new BufferedOutput();
        $table = new Table($buffer);
        $table->setHeaders(self::HEADERS);
        $table->setRows(array_map($this->toRow(...), $results));
        $table->render();

        return rtrim($buffer->fetch());
    }

    /**
     * @param ScenarioResult[] $results
     */
    private function markdown(array $results): string
    {
        $lines = [
            '| ' . implode(' | ', self::HEADERS) . ' |',
            '|' . str_repeat(' --- |', \count(self::HEADERS)),
        ];
        foreach ($results as $result) {
            $lines[] = '| ' . implode(' | ', $this->toRow($result)) . ' |';
        }

        return implode("\n", $lines);
    }

    /**
     * @param ScenarioResult $result
     * @return string[]
     */
    private function toRow(ScenarioResult $result): array
    {
        $cold = $result->cold[0] ?? null;
        $warm = $result->warm[0] ?? null;

        return [
            $result->scenario->code,
            $this->formatMs($result->coldStats()['median'] ?? null),
            $this->formatMs($result->coldStats()['p95'] ?? null),
            $cold === null ? self::NOT_AVAILABLE : (string) $cold->sqlQueryCount,
            $cold === null ? self::NOT_AVAILABLE : (string) ($cold->rowsExamined ?? self::NOT_AVAILABLE),
            $this->formatMs($result->warmStats()['median'] ?? null),
            $warm === null ? self::NOT_AVAILABLE : (string) $warm->sqlQueryCount,
            $cold === null ? self::NOT_AVAILABLE : number_format($cold->peakMemoryBytes / 1048576, 1, '.', ''),
        ];
    }

    private function formatMs(?float $value): string
    {
        return $value === null ? self::NOT_AVAILABLE : number_format($value, 1, '.', '');
    }

    /**
     * @param ScenarioResult $result
     * @return array<string,mixed>
     */
    private function toArray(ScenarioResult $result): array
    {
        $toArray = static fn (Measurement $measurement): array => get_object_vars($measurement);

        return [
            'code' => $result->scenario->code,
            'cold' => array_map($toArray, $result->cold),
            'warm' => array_map($toArray, $result->warm),
            'cold_stats' => $result->coldStats(),
            'warm_stats' => $result->warmStats(),
        ];
    }

    private function isValidConcurrency(InputInterface $input): bool
    {
        return filter_var(
            $input->getOption(self::OPTION_CONCURRENCY),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => ConcurrentRunner::MIN_CONCURRENCY, 'max_range' => ConcurrentRunner::MAX_CONCURRENCY]]
        ) !== false;
    }

    private function isRelativeVarPath(string $path): bool
    {
        return $path !== ''
            && !str_starts_with($path, '/')
            && !str_starts_with($path, '\\')
            && preg_match('#^[a-zA-Z]:#', $path) !== 1
            && !\in_array('..', preg_split('#[\\\\/]+#', $path) ?: [], true);
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
