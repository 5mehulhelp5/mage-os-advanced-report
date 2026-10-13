<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\Definition;

/**
 * Adjusts the declared presets (resolve placeholders, drop presets that cannot run) before validation.
 */
interface PresetExtenderInterface
{
    /**
     * Returns the presets after extension, keyed by code.
     *
     * @param array<string,Preset> $presets
     * @return array<string,Preset>
     */
    public function extend(array $presets): array;
}
