"""Offline audit. Standard library only; never writes to MySQL."""
import json, math, statistics, bisect, csv
from pathlib import Path
from collections import Counter, defaultdict
from datetime import datetime

D=Path(__file__).resolve().parents[1]/'storage/research'
def load(n): return json.loads((D/(n+'.json')).read_text(encoding='utf-8'))
def ts(s): return datetime.fromisoformat(s).timestamp()
def avg(xs): return statistics.mean(xs) if xs else None
def sign(x): return 1 if x>0 else -1 if x<0 else 0
P=load('predictions'); M=[x for x in load('market') if x['interval_name']=='1m']
T=load('trades'); O=load('orders'); E=load('equity')
for p in P:
 p['t']=ts(p['created_at']); p['end']=ts(p['target_time'])
 p['signals']={s['module']:s for s in json.loads(p['signals_json'])}
mt=[ts(x['open_time']) for x in M]
audit=Counter(); valid=[]
for p in P:
 if p['return_pct'] is None: audit['unevaluated']+=1; continue
 if int(p['evaluation_version'])!=2: audit['legacy_evaluation']+=1; continue
 i=bisect.bisect_left(mt,p['end'])
 if i==len(M): audit['missing_target_candle']+=1; continue
 p['target_lag_seconds']=mt[i]-p['end']
 if mt[i]-p['end']>120: audit['target_gap_over_120s']+=1; continue
 if abs(float(M[i]['close_price'])-float(p['final_price']))>0.02: audit['target_price_mismatch']+=1; continue
 if p['end']<=p['t']: audit['invalid_horizon']+=1; continue
 p['end']=ts(M[i]['close_time']); p['r']=float(p['return_pct'])
 valid.append(p)

def metrics(rows):
 rows=[p for p in rows if p['actual_direction'] in ('UP','DOWN')]
 n=len(rows)
 if not n:return {'n':0}
 up=avg([p['actual_direction']=='UP' for p in rows]); b=avg([(float(p['probability_up'])-(p['actual_direction']=='UP'))**2 for p in rows])
 return {'n':n,'hit_pct':100*avg([p['predicted_direction']==p['actual_direction'] for p in rows]),'up_pct':100*up,'predicted_up_pct':100*avg([p['predicted_direction']=='UP' for p in rows]),'brier':b,'coin_brier':.25,'constant_in_sample_brier':up*(1-up),'mean_abs_move_pct':avg([abs(p['r']) for p in rows]),'direct_mean_gross_pct':avg([p['r']*(1 if p['predicted_direction']=='UP' else -1) for p in rows])}

forecast={h:metrics([p for p in valid if p['horizon']==h]) for h in ('15m','1h','4h','24h')}
modules=[]
for h in forecast:
 for mod in sorted({m for p in valid for m in p['signals']}):
  rows=[p for p in valid if p['horizon']==h and mod in p['signals']]
  usable=[p for p in rows if p['signals'][mod].get('status','AVAILABLE')=='AVAILABLE' and float(p['signals'][mod]['value'])!=0 and p['actual_direction'] in ('UP','DOWN')]
  modules.append({'horizon':h,'module':mod,'total':len(rows),'status':dict(Counter(p['signals'][mod].get('status','AVAILABLE') for p in rows)),'usable':len(usable),'hit_pct':100*avg([sign(float(p['signals'][mod]['value']))==sign(p['r']) for p in usable]) if usable else None,'gross_mean_pct':avg([sign(float(p['signals'][mod]['value']))*p['r'] for p in usable])})

bots=[]
for c in load('config'):
 mode=c['mode']
 if mode.startswith('LEGACY'):continue
 trades=[t for t in T if t['mode']==mode]; orders=[o for o in O if o['mode']==mode]; equities=[e for e in E if e['mode']==mode]
 net=sum(float(t['net_pnl']) for t in trades); fees=sum(float(t['fees_usd']) for t in trades); slip=sum(float(t['slippage_usd']) for t in trades)
 wins=sum(max(0,float(t['net_pnl'])) for t in trades); losses=-sum(min(0,float(t['net_pnl'])) for t in trades)
 bots.append({'mode':mode,'trades':len(trades),'orders':len(orders),'equity':float(equities[-1]['equity']) if equities else None,'first_snapshot':equities[0]['created_at'] if equities else None,'last_snapshot':equities[-1]['created_at'] if equities else None,'first_trade':trades[0]['entry_at'] if trades else None,'last_trade':trades[-1]['exit_at'] if trades else None,'net_closed':net,'gross_before_all_costs':net+fees+slip,'closed_costs':fees+slip,'all_order_costs':sum(float(o['fee_usd'])+float(o['slippage_usd']) for o in orders),'win_pct':100*sum(float(t['net_pnl'])>0 for t in trades)/len(trades) if trades else None,'profit_factor':wins/losses if losses else None,'mean_hold_minutes':avg([int(t['hold_seconds'])/60 for t in trades]),'max_hold_minutes':max([int(t['hold_seconds'])/60 for t in trades],default=None),'exit_reasons':dict(Counter(t['exit_reason'] for t in trades))})

# Predeclared finite candidates: frozen ensemble and six frozen module signals,
# direct/inverse, confidence thresholds. Final calendar 30% held out with purge.
start=min(p['t'] for p in P); end=max(p['t'] for p in P)
cut=start+.70*(end-start)
def direction(p,source,threshold,invert):
 if source=='ensemble':
  v=float(p['probability_up'])-.5
  if abs(v)<threshold:return 0
 else:
  s=p['signals'].get(source,{})
  if s.get('status','AVAILABLE')!='AVAILABLE':return 0
  v=float(s.get('value',0))*float(s.get('confidence',0))
  if abs(v)<threshold:return 0
 return sign(v)*invert

def simulate(rows,source,threshold,invert,cost=.24):
 equity=100.; peak=100.; dd=0.; next_entry=-math.inf; returns=[]; daily=defaultdict(float)
 for p in rows:
  if p['t']<=next_entry:continue
  s=direction(p,source,threshold,invert)
  if not s:continue
  # Frozen initial/target prices are signal research proxies, not exchange fills.
  net=s*p['r']-cost; pnl=equity*.25*net/100
  equity+=pnl; peak=max(peak,equity);dd=max(dd,100*(peak-equity)/peak)
  next_entry=p['end'];returns.append(net);daily[p['created_at'][:10]]+=net
 gain=sum(max(0,r) for r in returns); loss=-sum(min(0,r) for r in returns)
 return {'trades':len(returns),'return_pct':equity-100,'closed_drawdown_pct':dd,'mean_net_pct':avg(returns),'win_pct':100*sum(r>0 for r in returns)/len(returns) if returns else None,'profit_factor':gain/loss if loss else None,'active_days':len(daily),'positive_days':sum(r>0 for r in daily.values())}

candidates=[]
for h in forecast:
 rows=[p for p in valid if p['horizon']==h]
 train=[p for p in rows if p['end']<cut and ts(p['evaluated_at'])<cut]
 test=[p for p in rows if p['t']>=cut]
 for source in ['ensemble','ATHENA','HERMES','POSEIDON','HEPHAESTUS','CRONOS','MARKET_RELATIONS']:
  for threshold in ([0,.025,.05,.10] if source=='ensemble' else [0,.10,.25]):
   for invert in [1,-1]:
    a=simulate(train,source,threshold,invert); b=simulate(test,source,threshold,invert)
    candidates.append({'horizon':h,'source':source,'threshold':threshold,'invert':invert,'train':a,'test':b,'stress':simulate(test,source,threshold,invert,.60)})
eligible=[c for c in candidates if c['train']['trades']>=30]
winner=max(eligible,key=lambda c:c['train']['return_pct']) if eligible else None
# Walk-forward selection uses only outcomes evaluated before each boundary.
walk=[]
for lo,hi in [(0.5,.65),(.65,.8),(.8,1.000001)]:
 a=start+lo*(end-start);b=start+hi*(end-start); ranked=[]
 for c in candidates:
  rows=[p for p in valid if p['horizon']==c['horizon']]
  train=[p for p in rows if p['end']<a and ts(p['evaluated_at'])<a]
  z=simulate(train,c['source'],c['threshold'],c['invert'])
  if z['trades']>=30:ranked.append((z['return_pct'],c))
 _,c=max(ranked,key=lambda x:x[0]); z=simulate([p for p in valid if p['horizon']==c['horizon'] and a<=p['t'] and p['end']<b],c['source'],c['threshold'],c['invert'])
 walk.append({'from':datetime.fromtimestamp(a).isoformat(),'to':datetime.fromtimestamp(min(b,end)).isoformat(),'selected':{k:c[k] for k in ('horizon','source','threshold','invert')},'test':z})

gaps=[(mt[i]-mt[i-1])/60 for i in range(1,len(mt))]
p15=[p for p in P if p['horizon']=='15m'];pgaps=[(b['t']-a['t'])/60 for a,b in zip(p15,p15[1:])]
summary={'snapshot_clock':load('clock'),'period':[P[0]['created_at'],P[-1]['created_at']],'prediction_count':len(P),'audit_exclusions':dict(audit),'valid_count':len(valid),'split':datetime.fromtimestamp(cut).isoformat(),'forecast':forecast,'modules':modules,'bots':bots,'candidate_count':len(candidates),'selected_on_train':winner,'walk_forward':walk,'market_gaps_over_2min':sum(x>2 for x in gaps),'largest_market_gap_minutes':max(gaps),'prediction_median_gap_minutes':statistics.median(pgaps),'prediction_largest_gap_minutes':max(pgaps),'prediction_gaps_over_60min':sum(x>60 for x in pgaps),'daily_predictions':dict(Counter(p['created_at'][:10] for p in P))}
(D/'analysis.json').write_text(json.dumps(summary,indent=2,ensure_ascii=False),encoding='utf-8')
(D/'validated_ids.json').write_text(json.dumps([p['id'] for p in valid]),encoding='utf-8')
(D/'candidates.json').write_text(json.dumps(candidates,indent=2),encoding='utf-8')
with (D/'candidates.csv').open('w',newline='',encoding='utf-8') as f:
 w=csv.writer(f);w.writerow(['horizon','source','threshold','invert','train_n','train_return_pct','test_n','test_return_pct','stress_return_pct'])
 for c in candidates:w.writerow([c['horizon'],c['source'],c['threshold'],c['invert'],c['train']['trades'],c['train']['return_pct'],c['test']['trades'],c['test']['return_pct'],c['stress']['return_pct']])
print(json.dumps({k:v for k,v in summary.items() if k not in ('modules','bots','daily_predictions')},indent=2))
print('BOTS',json.dumps(bots,ensure_ascii=False,indent=2))
