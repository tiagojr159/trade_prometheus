<?php
declare(strict_types=1);

namespace Prometheus\core;

final class Signal
{
    public string $module;
    public float $value;
    public float $confidence;
    public array $metadata;

    public function __construct(
        string $module,
        float $value,
        float $confidence,
        array $metadata = []
    ) {
        $this->module = $module;
        $this->value = $value;
        $this->confidence = $confidence;
        $this->metadata = $metadata;
    }

    public function normalized(): self
    {
        return new self($this->module, max(-1.0, min(1.0, $this->value)), max(0.0, min(1.0, $this->confidence)), $this->metadata);
    }

    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'value' => $this->normalized()->value,
            'confidence' => $this->normalized()->confidence,
            'status' => (string)($this->metadata['status'] ?? 'AVAILABLE'),
            'metadata' => $this->metadata,
        ];
    }
}
