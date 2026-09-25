<?php
declare(strict_types=1);

namespace Prometheus\intelligence;

use Prometheus\modules\AthenaTechnical;
use Prometheus\modules\CronosMacro;
use Prometheus\modules\HephaestusDerivatives;
use Prometheus\modules\HermesNews;
use Prometheus\core\DirectionPolicy;
use Prometheus\modules\MarketRelations;
use Prometheus\modules\PoseidonOnChain;
use Prometheus\prediction\ProbabilityCalculator;

final class PrometheusEngine
{
    private array $modules;
    private RegimeDetector $regimeDetector;
    private EnsembleEngine $ensemble;
    private ProbabilityCalculator $probability;
    private SignalNormalizer $normalizer;

    public function __construct(
        ?RegimeDetector $regimeDetector = null,
        ?EnsembleEngine $ensemble = null,
        ?ProbabilityCalculator $probability = null,
        ?SignalNormalizer $normalizer = null
    ) {
        $this->regimeDetector = $regimeDetector ?: new RegimeDetector();
        $this->ensemble = $ensemble ?: new EnsembleEngine();
        $this->probability = $probability ?: new ProbabilityCalculator();
        $this->normalizer = $normalizer ?: new SignalNormalizer();
        $this->modules = [
            new AthenaTechnical(),
            new HermesNews(),
            new PoseidonOnChain(),
            new HephaestusDerivatives(),
            new CronosMacro(),
            new MarketRelations(),
        ];
    }

    private function moduleName(object $module): string
    {
        $names = [AthenaTechnical::class => 'ATHENA', HermesNews::class => 'HERMES', PoseidonOnChain::class => 'POSEIDON', HephaestusDerivatives::class => 'HEPHAESTUS', CronosMacro::class => 'CRONOS', MarketRelations::class => 'MARKET_RELATIONS'];
        return $names[get_class($module)] ?? 'UNKNOWN';
    }

    public function analyze(string $horizon, string $symbol = 'BTCUSDT'): array
    {
        $regime = $this->regimeDetector->detect($symbol);
        $signals = [];
        foreach ($this->modules as $module) {
            try {
                $signal = $module->signal($horizon, $symbol);
            } catch (\Throwable $e) {
                $signal = new \Prometheus\core\Signal($this->moduleName($module), 0.0, 0.0, [
                    'status' => 'ERROR', 'reason' => 'module_processing_failed',
                    'error' => $e->getMessage(), 'horizon' => $horizon,
                ]);
            }
            $signals[] = $this->normalizer->normalize($signal);
        }
        $combined = $this->ensemble->combine($signals, $horizon, $regime['regime']);
        $prob = $this->probability->calculate($combined['signal'], $combined['confidence']);
        $dir = DirectionPolicy::predict($prob['up'], $prob['down']);
        return [
            'regime' => $regime,
            'signals' => $signals,
            'ensemble_signal' => $combined['signal'],
            'confidence' => $combined['confidence'],
            'probability_up' => $prob['up'],
            'probability_down' => $prob['down'],
            'direction' => $dir['direction'],
            'edge' => $dir['edge'],
            'probability_margin' => $dir['margin'],
            'weights' => $combined['weights'],
            'contributions' => $combined['contributions'],
            'missing_modules' => $combined['missing_modules'],
            'coverage' => $combined['coverage'],
        ];
    }
}
