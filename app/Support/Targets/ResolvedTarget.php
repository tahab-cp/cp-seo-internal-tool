<?php

namespace App\Support\Targets;

/**
 * One resolved monthly target for a project: the package default, the
 * project override (if any) and the value that wins.
 */
final readonly class ResolvedTarget
{
    public function __construct(
        public string $targetKey,
        public string $label,
        public int $packageValue,
        public ?int $overrideValue,
        public int $sortOrder,
    ) {}

    public function resolvedValue(): int
    {
        return $this->overrideValue ?? $this->packageValue;
    }

    public function isOverridden(): bool
    {
        return $this->overrideValue !== null;
    }

    /**
     * @return array{target_key: string, label: string, package_value: int, override_value: int|null, resolved_value: int, sort_order: int}
     */
    public function toArray(): array
    {
        return [
            'target_key' => $this->targetKey,
            'label' => $this->label,
            'package_value' => $this->packageValue,
            'override_value' => $this->overrideValue,
            'resolved_value' => $this->resolvedValue(),
            'sort_order' => $this->sortOrder,
        ];
    }
}
