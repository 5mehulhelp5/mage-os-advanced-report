<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

use Magento\Framework\Config\DataInterface;
use Magento\Framework\Phrase;

/**
 * Serves the semantic layer (datasets, presets) as immutable value objects, built lazily
 * once per instance from the merged `report_datasets.xml` configuration.
 *
 * The build pipeline is intentionally kept in one place, in explicit steps, so later
 * tasks can extend it without restructuring it:
 * 1. read the converted array ({@see \MageOS\AdvancedReport\Model\Config\Data});
 * 2. build the value objects ({@see DefinitionFactory});
 * 3. dataset extenders (task 021, e.g. synthesized attribute dimensions);
 * 4. preset extenders (task 031, e.g. alternate dimension presets);
 * 5. validation (task 008).
 */
class DefinitionRegistry
{
    /**
     * @var array<string,Dataset>|null
     */
    private ?array $datasets = null;

    /**
     * @var array<string,Preset>|null
     */
    private ?array $presets = null;

    /**
     * @var string|null
     */
    private ?string $configVersion = null;

    /**
     * @param DataInterface $configData
     * @param DefinitionFactory $definitionFactory
     * @param DefinitionValidator $definitionValidator
     * @param DatasetExtenderInterface[] $datasetExtenders
     * @param PresetExtenderInterface[] $presetExtenders
     */
    public function __construct(
        private readonly DataInterface $configData,
        private readonly DefinitionFactory $definitionFactory,
        private readonly DefinitionValidator $definitionValidator,
        private readonly array $datasetExtenders = [],
        private readonly array $presetExtenders = [],
    ) {
    }

    /**
     * Looks up a declared dataset by code.
     *
     * @param string $code
     * @throws NoSuchDefinitionException
     */
    public function getDataset(string $code): Dataset
    {
        $this->build();

        return $this->datasets[$code] ?? throw $this->noSuchDefinition('dataset', $code);
    }

    /**
     * All declared datasets, keyed by code.
     *
     * @return array<string,Dataset>
     */
    public function getDatasets(): array
    {
        $this->build();

        return $this->datasets;
    }

    /**
     * Looks up a declared preset by code.
     *
     * @param string $code
     * @throws NoSuchDefinitionException
     */
    public function getPreset(string $code): Preset
    {
        $this->build();

        return $this->presets[$code] ?? throw $this->noSuchDefinition('preset', $code);
    }

    /**
     * Declared presets, keyed by code, optionally filtered by area (sales|catalog|customers).
     *
     * @param string|null $area
     * @return array<string,Preset>
     */
    public function getPresets(?string $area = null): array
    {
        $this->build();

        if ($area === null) {
            return $this->presets;
        }

        return array_filter($this->presets, static fn (Preset $preset): bool => $preset->area === $area);
    }

    /**
     * A hash of the converted configuration, stable across requests and changing only
     * when the merged `report_datasets.xml` content changes. Used as part of the report
     * result cache key (task 016).
     */
    public function getConfigVersion(): string
    {
        $this->build();

        return $this->configVersion;
    }

    /**
     * Builds datasets and presets once per instance, in explicit pipeline steps.
     *
     * @phpstan-assert !null $this->datasets
     * @phpstan-assert !null $this->presets
     * @phpstan-assert !null $this->configVersion
     */
    private function build(): void
    {
        if ($this->datasets !== null) {
            return;
        }

        // DataInterface::get() is documented as taking a string $key, but the concrete
        // Magento\Framework\Config\Data treats a null key as "return the whole merged array".
        // @phpstan-ignore-next-line argument.type
        $converted = $this->configData->get(null) ?? [];
        $this->configVersion = hash('sha256', (string) json_encode($converted));

        $datasets = $this->definitionFactory->createDatasets($converted['datasets'] ?? []);
        $datasets = $this->extendDatasets($datasets);

        $presets = $this->definitionFactory->createPresets($converted['presets'] ?? []);
        $presets = $this->extendPresets($presets);

        $validated = $this->validate($datasets, $presets);

        $this->datasets = $validated['datasets'];
        $this->presets = $validated['presets'];
    }

    /**
     * Applies the dataset extenders (e.g. synthesized attribute dimensions) to every dataset.
     *
     * @param array<string,Dataset> $datasets
     * @return array<string,Dataset>
     */
    private function extendDatasets(array $datasets): array
    {
        foreach ($this->datasetExtenders as $extender) {
            $datasets = array_map($extender->extend(...), $datasets);
        }

        return $datasets;
    }

    /**
     * Applies the preset extenders (e.g. resolving the first report attribute placeholder).
     *
     * @param array<string,Preset> $presets
     * @return array<string,Preset>
     */
    private function extendPresets(array $presets): array
    {
        foreach ($this->presetExtenders as $extender) {
            $presets = $extender->extend($presets);
        }

        return $presets;
    }

    /**
     * Extension point for the definition validator (task 008).
     *
     * Drops or throws on invalid datasets/presets depending on the application mode.
     *
     * @param array<string,Dataset> $datasets
     * @param array<string,Preset> $presets
     * @throws InvalidDefinitionException
     * @return array{datasets: array<string,Dataset>, presets: array<string,Preset>}
     */
    private function validate(array $datasets, array $presets): array
    {
        return $this->definitionValidator->validate($datasets, $presets);
    }

    /**
     * Builds the "no such definition" exception for a missing top level lookup code.
     *
     * @param string $kind
     * @param string $code
     */
    private function noSuchDefinition(string $kind, string $code): NoSuchDefinitionException
    {
        return new NoSuchDefinitionException(new Phrase('No such %1 "%2".', [$kind, $code]));
    }
}
