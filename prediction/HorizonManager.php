<?php
declare(strict_types=1);

namespace Prometheus\prediction;

final class HorizonManager
{
    public function all(): array
    {
        return prometheus_config('horizons', []);
    }
}
