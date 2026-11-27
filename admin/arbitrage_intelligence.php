<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use Prometheus\admin\AdminGuard;
use Prometheus\core\Database;
use Prometheus\core\Arbitrage\StrategyEngine;
use Prometheus\core\Arbitrage\StrategyAllocationEngine;
use Prometheus\core\Arbitrage\FundingRateAnalytics;
AdminGuard::requireAdmin();
$e=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$strategies=StrategyEngine::registry();$latest=[];$summary=[];$connectors=[];$equityHistory=[];$backtests=[];$funding=[];$dexPools=[];$chainGas=[];$trades=[];$portfolios=[];$positions=[];$recent=[];$allocationRows=[];$allocation=[];$status=null;$regime='UNKNOWN';
try{
 $rows=Database::fetchAll('SELECT o.* FROM arbitrage_opportunities o JOIN (
   SELECT strategy_key,MAX(id) id FROM arbitrage_opportunities GROUP BY strategy_key
 ) latest ON latest.id=o.id ORDER BY o.score DESC,o.strategy_key');
 foreach($rows as $r)$latest[$r['strategy_key']]=$r;
 $ranking=array_values($latest);usort($ranking,static fn(array $a,array $b):int=>(float)($b['score']??-INF)<=>(float)($a['score']??-INF));
 $summary=Database::fetchAll('SELECT strategy_key,classification,
   SUM(CASE WHEN as_of_utc>=UTC_TIMESTAMP()-INTERVAL 1 DAY THEN net_pnl ELSE 0 END) daily_pnl,
   SUM(CASE WHEN as_of_utc>=UTC_TIMESTAMP()-INTERVAL 7 DAY THEN net_pnl ELSE 0 END) weekly_pnl,
   SUM(CASE WHEN as_of_utc>=UTC_TIMESTAMP()-INTERVAL 30 DAY THEN net_pnl ELSE 0 END) monthly_pnl,
   SUM(net_pnl) cumulative_pnl,MAX(drawdown_pct) max_drawdown,
   (SELECT equity FROM arbitrage_strategy_performance x WHERE x.strategy_key=p.strategy_key AND x.classification=p.classification ORDER BY id DESC LIMIT 1) equity
   FROM arbitrage_strategy_performance p GROUP BY strategy_key,classification ORDER BY strategy_key,classification');
 $connectors=Database::fetchAll('SELECT exchange_name,connector_type,status,last_attempt_at_utc,last_success_at_utc,latency_ms,last_error_code
   FROM arbitrage_connector_status ORDER BY exchange_name,connector_type');
 $equityHistory=Database::fetchAll('SELECT strategy_key,classification,as_of_utc,equity FROM arbitrage_strategy_performance
   WHERE as_of_utc>=UTC_TIMESTAMP()-INTERVAL 90 DAY ORDER BY as_of_utc,id LIMIT 1000');
 $backtests=Database::fetchAll('SELECT b.* FROM arbitrage_strategy_backtests b JOIN (SELECT strategy_key,MAX(id) id
   FROM arbitrage_strategy_backtests GROUP BY strategy_key) x ON x.id=b.id ORDER BY b.strategy_key');
 $allocationRows=Database::fetchAll('SELECT e.strategy_key,e.event_date,e.daily_return,b.test_start_utc,b.test_end_utc FROM arbitrage_backtest_equity e
   JOIN arbitrage_strategy_backtests b ON b.id=e.backtest_id
   JOIN (SELECT strategy_key,MAX(id) id FROM arbitrage_strategy_backtests GROUP BY strategy_key) latest ON latest.id=b.id
   ORDER BY e.event_date,e.strategy_key');
 $oosReturns=[];$windowKeys=[];foreach($allocationRows as $row){$oosReturns[$row['strategy_key']][$row['event_date']]=(float)$row['daily_return'];
   $windowKeys[$row['strategy_key']]=(string)$row['test_start_utc'].'|'.(string)$row['test_end_utc'];}
 $allocation=(new StrategyAllocationEngine())->recommend($oosReturns,['classification'=>'BACKTEST','partition'=>'TEST','minimum_observations'=>20,
   'max_weight_pct'=>25,'evaluation_window_keys'=>$windowKeys]);
 $funding=Database::fetchAll('SELECT f.exchange_name,f.symbol,f.funding_rate,f.interval_hours,f.observed_at_utc,f.source
   FROM arbitrage_funding_rate_history f JOIN (SELECT exchange_name,symbol,MAX(id) id FROM arbitrage_funding_rate_history GROUP BY exchange_name,symbol) x ON x.id=f.id
   ORDER BY f.symbol,f.exchange_name');
 $fundingAnalytics=[];$fundingDb=Database::connection();foreach($funding as &$fundingRow){$key=$fundingRow['symbol'].'|'.$fundingRow['exchange_name'];
   if(!isset($fundingAnalytics[$key]))$fundingAnalytics[$key]=FundingRateAnalytics::summarize($fundingDb,$fundingRow['symbol'],$fundingRow['exchange_name'],gmdate('Y-m-d H:i:s'));
   $fundingRow['reversal']=$fundingAnalytics[$key];}unset($fundingRow); $dexPools=Database::fetchAll('SELECT network,dex_name,pool_address,pool_label,base_token_symbol,quote_token_symbol,
   base_token_price_usd,base_token_balance,quote_token_balance,reserve_usd,volume_24h_usd,observed_at_utc
   FROM arbitrage_dex_pool_snapshots ORDER BY id DESC LIMIT 20');
 $chainGas=Database::fetchAll('SELECT chain_name,gas_price_wei,observed_at_utc FROM arbitrage_chain_gas_snapshots ORDER BY id DESC LIMIT 20');
 $trades=Database::fetchAll('SELECT t.*,p.strategy_key FROM arbitrage_strategy_trades t JOIN arbitrage_strategy_portfolios p ON p.id=t.portfolio_id
   ORDER BY t.id DESC LIMIT 50');
 $portfolios=Database::fetchAll('SELECT strategy_key,portfolio_key,initial_equity,cash,equity,realized_pnl,max_drawdown_pct,status
   FROM arbitrage_strategy_portfolios ORDER BY strategy_key,portfolio_key');
 $positions=Database::fetchAll("SELECT p.strategy_key,sp.leg,sp.side,SUM(sp.quantity*COALESCE(sp.mark_price,sp.entry_price)) notional
   FROM arbitrage_strategy_positions sp JOIN arbitrage_strategy_portfolios p ON p.id=sp.portfolio_id
   WHERE sp.status='OPEN' GROUP BY p.strategy_key,sp.leg,sp.side");
 $recent=Database::fetchAll('SELECT strategy_key,symbol,action,status,observed_at_utc,net_profit,rejection_reasons_json
   FROM arbitrage_opportunities ORDER BY id DESC LIMIT 50');
 $r=Database::fetch('SELECT regime,observed_at_utc FROM arbitrage_market_regimes ORDER BY id DESC LIMIT 1');if($r)$regime=$r['regime'];
}catch(Throwable $x){$status='Schema ainda não instalado ou banco indisponível. Execute php deploy/arbitrage.php schema.';}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Arbitrage Intelligence | Prometheus</title>
<style>body{margin:0;background:#111827;color:#edf2f7;font:15px system-ui,sans-serif}main{max-width:1300px;margin:auto;padding:2rem}a{color:#93c5fd}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:1rem}.card{background:#1e293b;border:1px solid #334155;border-radius:12px;padding:1rem}.muted{color:#aab8ca}.tag{font-size:.78rem;padding:.2rem .5rem;background:#334155;border-radius:1rem}table{width:100%;border-collapse:collapse;margin-top:1rem}td,th{text-align:left;padding:.6rem;border-bottom:1px solid #334155}</style></head><body><main>
<a href="simulation.php">Voltar ao torneio</a><h1>ARBITRAGE INTELLIGENCE</h1><p>Pesquisa, observação e execução exclusivamente simulada. Sem ordens reais; os dados só são exibidos depois de fornecidos ao scanner.</p>
<?php if($status):?><p role="status"><?=$e($status)?></p><?php endif;?>
<p>Regime reportado: <strong><?=$e($regime)?></strong> · Probabilidade de sucesso: não exibida sem calibração.</p><section class="grid">
<?php foreach($strategies as $key=>$name):$r=$latest[$key]??null;?><article class="card"><h2><?=$e($name)?></h2><span class="tag"><?=$e($r['status']??'SEM DADOS')?></span>
<p class="muted"><?=$r?$e($r['symbol'].' · '.$r['action']):'Sem oportunidade persistida'?></p>
<?php if($r):?><p>Lucro líquido estimado: <?=number_format((float)$r['net_profit'],4,',','.')?> USDT<br>Score relativo: <?=$e($r['score']??'—')?> · Qualidade: <?=$r['data_quality']?'válida':'insuficiente'?></p>
<?php $reasons=json_decode((string)$r['rejection_reasons_json'],true)?:[];if($reasons):?><p class="muted">Rejeitada: <?=$e(implode(', ',$reasons))?></p><?php endif;?>
<small>Observado em UTC: <?=$e($r['observed_at_utc'])?></small><?php else:?><p class="muted">Classificação: <?= $key==='MEV_OBSERVATION'?'observação/backtest':'não validada sem feed adequado' ?></p><?php endif;?></article><?php endforeach;?></section>
<h2>Limites e fonte</h2><ul><li>Paper trading e risco máximo inicial de 5% por oportunidade; 25% por corretora.</li><li>O score normaliza retorno, probabilidade validada, liquidez, persistencia, Sharpe comparavel e qualidade. Fatores sem evidencia sao omitidos; probabilidades sem calibracao ficam fora.</li><li>MEV permanece em observação; transações reais não são assinadas ou transmitidas.</li><li>Eventos persistidos são observações ou simulações, nunca lucro realizado.</li></ul>
<h2>Ranking de oportunidades</h2><p>Ordenado pelo último score relativo observado. O net é estimado, não P&amp;L realizado; estratégias sem sinal permanecem sem classificação.</p>
<div style="overflow:auto"><table><thead><tr><th>Posição</th><th>Estratégia</th><th>Score</th><th>Estado</th><th>Net estimado</th><th>Qualidade dos dados</th></tr></thead><tbody>
<?php foreach($ranking as $i=>$r):?><tr><td><?=$i+1?></td><td><?=$e($strategies[$r['strategy_key']]??$r['strategy_key'])?></td><td><?=$e($r['score']??'—')?></td><td><?=$e($r['status'])?></td><td><?=number_format((float)$r['net_profit'],4,',','.')?></td><td><?=$r['data_quality']?'válida':'insuficiente'?></td></tr><?php endforeach;?>
<?php foreach($strategies as $key=>$name)if(!isset($latest[$key])):?><tr><td>—</td><td><?=$e($name)?></td><td>—</td><td>SEM DADOS</td><td>—</td><td>não validada</td></tr><?php endif;?>
</tbody></table></div>
<h2>Alocação virtual por risco</h2><p>Risk parity calculada só com retornos diários de teste fora da amostra e dias sincronizados. É recomendação virtual, sem execução automática.</p>
<?php if(($allocation['status']??'')==='RECOMMENDATION_READY'):?><div style="overflow:auto"><table><thead><tr><th>Estratégia</th><th>Peso sugerido</th><th>Contribuição de risco</th></tr></thead><tbody>
<?php foreach($allocation['weights_pct'] as $key=>$weight):?><tr><td><?=$e($strategies[$key]??$key)?></td><td><?=number_format((float)$weight,2,',','.')?>%</td><td><?=number_format((float)$allocation['risk_contribution_pct'][$key],2,',','.')?>%</td></tr><?php endforeach;?>
</tbody></table></div><p class="muted">Janela sincronizada: <?=$e($allocation['window_start'])?> a <?=$e($allocation['window_end'])?>; <?=$e($allocation['observations'])?> dias.</p>
<?php else:?><p class="muted">Sem recomendação: <?=$e($allocation['status']??'DADOS_OOS_INDISPONIVEIS')?>. Requer pelo menos 20 dias coincidentes de equity de backtest, em duas ou mais estratégias, no mesmo intervalo de teste.</p><?php endif;?>
<h2>Resultados por estratégia</h2><p>Paper e backtest aparecem separados. O P&amp;L usa trades líquidos registrados; o gráfico usa os pontos de patrimônio persistidos.</p>
<h2>Funding observado por corretora</h2><p>Taxas normalizadas por intervalo. A frequência de reversão usa eventos históricos, não é forecast validado nem lucro liquidado.</p>
<div style="overflow:auto"><table><thead><tr><th>Simbolo</th><th>Corretora</th><th>Taxa por intervalo</th><th>Reversão histórica 24 h</th><th>Amostras/eventos</th><th>Status</th><th>Intervalo</th><th>Fonte</th><th>Observado UTC</th></tr></thead><tbody>
<?php foreach($funding as $f):$rev=$f['reversal'];?><tr><td><?=$e($f['symbol'])?></td><td><?=$e($f['exchange_name'])?></td><td><?=number_format((float)$f['funding_rate']*100,6,',','.')?>%</td>
<td><?=$rev['reversal_probability']===null?'—':number_format((float)$rev['reversal_probability']*100,1,',','.').'% (IC 95% '.number_format((float)$rev['reversal_confidence_95_pct']['lower']*100,1,',','.').'–'.number_format((float)$rev['reversal_confidence_95_pct']['upper']*100,1,',','.').'%)'?></td>
<td><?=$e($rev['reversal_observations'].' / '.($rev['reversal_events']??'—'))?></td><td><?=$e($rev['reversal_probability_status'])?></td><td><?=$e($f['interval_hours'])?> h</td><td><?=$e($f['source'])?></td><td><?=$e($f['observed_at_utc'])?></td></tr><?php endforeach;?>
<?php if(!$funding):?><tr><td colspan="9">Sem taxas observadas.</td><?php endif;?></tbody></table></div><h2>Observações DEX e gas EVM</h2><p>Dados observacionais de pools configuradas e RPCs fornecidos. Não representam cotação executável, impacto de preço calculado ou lucro.</p>
<div style="overflow:auto"><table><thead><tr><th>Rede</th><th>DEX / pool</th><th>Par</th><th>Preço base USD</th><th>Saldo base / quote</th><th>Reserva USD</th><th>Volume 24 h USD</th><th>UTC</th></tr></thead><tbody>
<?php foreach($dexPools as $p):?><tr><td><?=$e($p['network'])?></td><td><?=$e(($p['dex_name']??'').' / '.($p['pool_label']??$p['pool_address']))?></td>
<td><?=$e(($p['base_token_symbol']??'?').'/'.($p['quote_token_symbol']??'?'))?></td><td><?=$e($p['base_token_price_usd']??'—')?></td>
<td><?=$e($p['base_token_balance']??'—')?> / <?=$e($p['quote_token_balance']??'—')?></td><td><?=$e($p['reserve_usd']??'—')?></td>
<td><?=$e($p['volume_24h_usd']??'—')?></td><td><?=$e($p['observed_at_utc'])?></td></tr><?php endforeach;?>
<?php if(!$dexPools):?><tr><td colspan="8">Sem pools observadas. Configure PROMETHEUS_DEX_POOLS e execute collect-dex.</td><?php endif;?></tbody></table></div>
<div style="overflow:auto"><table><thead><tr><th>Rede EVM</th><th>Gas price wei</th><th>UTC</th></tr></thead><tbody>
<?php foreach($chainGas as $g):?><tr><td><?=$e($g['chain_name'])?></td><td><?=$e($g['gas_price_wei'])?></td><td><?=$e($g['observed_at_utc'])?></td></tr><?php endforeach;?>
<?php if(!$chainGas):?><tr><td colspan="3">Sem gas observado. Configure PROMETHEUS_EVM_RPC_ENDPOINTS e execute collect-gas.</td><?php endif;?></tbody></table></div>
<h2>Carteiras virtuais</h2><div style="overflow:auto"><table><thead><tr><th>Estrategia</th><th>Carteira</th><th>Estado</th><th>Capital inicial</th><th>Caixa</th><th>Equity</th><th>P&amp;L realizado</th><th>Drawdown</th></tr></thead><tbody>
<?php foreach($portfolios as $p):?><tr><td><?=$e($strategies[$p['strategy_key']]??$p['strategy_key'])?></td><td><?=$e($p['portfolio_key'])?></td><td><?=$e($p['status'])?></td><td><?=number_format((float)$p['initial_equity'],2,',','.')?></td><td><?=number_format((float)$p['cash'],2,',','.')?></td><td><?=number_format((float)$p['equity'],2,',','.')?></td><td><?=number_format((float)$p['realized_pnl'],2,',','.')?></td><td><?=number_format((float)$p['max_drawdown_pct'],3,',','.')?>%</td></tr><?php endforeach;?>
<?php if(!$portfolios):?><tr><td colspan="8">Carteiras nao inicializadas; use init-portfolios.</td><?php endif;?></tbody></table></div>
<p class="muted">Posicoes abertas sao mostradas apenas quando persistidas. Ausencia de registro nao comprova exposicao zero em venues externas.</p>
<div style="overflow:auto"><table><thead><tr><th>Exposicao por estrategia</th><th>Perna</th><th>Lado</th><th>Valor marcado</th></tr></thead><tbody>
<?php foreach($positions as $p):?><tr><td><?=$e($strategies[$p['strategy_key']]??$p['strategy_key'])?></td><td><?=$e($p['leg'])?></td><td><?=$e($p['side'])?></td><td><?=number_format((float)$p['notional'],2,',','.')?></td></tr><?php endforeach;?>
<?php if(!$positions):?><tr><td colspan="4">Nenhuma posicao aberta persistida.</td><?php endif;?></tbody></table></div>
<h2>Oportunidades recentes</h2><div style="overflow:auto"><table><thead><tr><th>Estrategia</th><th>Simbolo</th><th>Acao</th><th>Estado</th><th>Net estimado</th><th>Rejeicoes</th><th>UTC</th></tr></thead><tbody>
<?php foreach($recent as $o):?><tr><td><?=$e($strategies[$o['strategy_key']]??$o['strategy_key'])?></td><td><?=$e($o['symbol'])?></td><td><?=$e($o['action'])?></td><td><?=$e($o['status'])?></td><td><?=number_format((float)$o['net_profit'],4,',','.')?></td><td><?=$e(implode(', ',json_decode($o['rejection_reasons_json'],true)?:[]))?></td><td><?=$e($o['observed_at_utc'])?></td></tr><?php endforeach;?>
<?php if(!$recent):?><tr><td colspan="7">Sem oportunidades.</td><?php endif;?></tbody></table></div>
<h2>Trades simulados recentes</h2><div style="overflow:auto"><table><thead><tr><th>Estrategia</th><th>Classe</th><th>Simbolo</th><th>P&amp;L liquido</th><th>Funding recebido</th><th>Funding pago</th><th>Abertura UTC</th><th>Fechamento UTC</th></tr></thead><tbody>
<?php foreach($trades as $t):?><tr><td><?=$e($strategies[$t['strategy_key']]??$t['strategy_key'])?></td><td><?=$e($t['classification'])?></td><td><?=$e($t['symbol'])?></td><td><?=number_format((float)$t['net_pnl'],4,',','.')?></td><td><?=number_format((float)$t['funding_received'],4,',','.')?></td><td><?=number_format((float)$t['funding_paid'],4,',','.')?></td><td><?=$e($t['opened_at_utc'])?></td><td><?=$e($t['closed_at_utc'])?></td></tr><?php endforeach;?>
<?php if(!$trades):?><tr><td colspan="8">Sem trades fechados.</td><?php endif;?></tbody></table></div>
<div style="max-width:1100px;height:340px"><canvas id="arbEquity"></canvas></div>
<div style="overflow:auto"><table><thead><tr><th>Estratégia</th><th>Classe</th><th>Dia</th><th>Semana</th><th>Mês</th><th>Acumulado</th><th>Drawdown máx.</th><th>Equity</th></tr></thead><tbody>
<?php foreach($summary as $row):?><tr><td><?=$e($strategies[$row['strategy_key']]??$row['strategy_key'])?></td><td><?=$e($row['classification'])?></td>
<td><?=number_format((float)$row['daily_pnl'],4,',','.')?></td><td><?=number_format((float)$row['weekly_pnl'],4,',','.')?></td>
<td><?=number_format((float)$row['monthly_pnl'],4,',','.')?></td><td><?=number_format((float)$row['cumulative_pnl'],4,',','.')?></td>
<td><?=number_format((float)$row['max_drawdown'],4,',','.')?>%</td><td><?=number_format((float)$row['equity'],4,',','.')?></td></tr><?php endforeach;?>
<?php if(!$summary):?><tr><td colspan="8">Ainda não há trades simulados registrados.</td></tr><?php endif;?></tbody></table></div>
<h2>Conectores e latência</h2><div style="overflow:auto"><table><thead><tr><th>Venue</th><th>Feed</th><th>Estado</th><th>Latência</th><th>Última tentativa UTC</th><th>Último sucesso UTC</th><th>Erro</th></tr></thead><tbody>
<?php foreach($connectors as $c):?><tr><td><?=$e($c['exchange_name'])?></td><td><?=$e($c['connector_type'])?></td><td><?=$e($c['status'])?></td>
<td><?=$c['latency_ms']===null?'—':(int)$c['latency_ms'].' ms'?></td><td><?=$e($c['last_attempt_at_utc'])?></td><td><?=$e($c['last_success_at_utc']??'—')?></td><td><?=$e($c['last_error_code']??'—')?></td></tr><?php endforeach;?>
<?php if(!$connectors):?><tr><td colspan="7">Nenhum estado de conector persistido.</td></tr><?php endif;?></tbody></table></div>
<h2>Últimos backtests</h2><div style="overflow:auto"><table><thead><tr><th>Estratégia</th><th>Estado</th><th>Fim da janela</th><th>Dataset SHA-256</th><th>Limitações</th></tr></thead><tbody>
<?php foreach($backtests as $b):?><tr><td><?=$e($strategies[$b['strategy_key']]??$b['strategy_key'])?></td><td><?=$e($b['status'])?></td><td><?=$e($b['ended_at_utc'])?></td>
<td><?=$e(substr($b['dataset_sha256'],0,16))?></td><td><?=$e(implode(', ',json_decode($b['limitations_json'],true)?:[]))?></td></tr><?php endforeach;?>
<?php if(!$backtests):?><tr><td colspan="5">Sem backtests registrados.</td><?php endif;?></tbody></table></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script>
const arbRows=<?=json_encode($equityHistory,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
const arbKeys=[...new Set(arbRows.map(r=>r.strategy_key+' · '+r.classification))];const arbColors=['#38bdf8','#34d399','#fbbf24','#fb7185','#a78bfa','#f97316','#e879f9'];
const arbCanvas=document.getElementById('arbEquity');if(arbCanvas&&arbRows.length)new Chart(arbCanvas,{type:'line',data:{datasets:arbKeys.map((key,i)=>({label:key,data:arbRows.filter(r=>r.strategy_key+' · '+r.classification===key).map(r=>({x:Date.parse(r.as_of_utc+'Z'),y:Number(r.equity)})),borderColor:arbColors[i%arbColors.length],pointRadius:0,tension:.12}))},options:{responsive:true,maintainAspectRatio:false,parsing:false,scales:{x:{type:'linear',ticks:{callback:v=>new Date(v).toLocaleString('pt-BR')}},y:{title:{display:true,text:'Equity virtual'}}}}});
</script>
</main></body></html>
