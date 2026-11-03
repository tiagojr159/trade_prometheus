"""Verify exported ledger arithmetic and conservative execution sensitivity."""
import json,statistics,math,bisect,random
from pathlib import Path
from datetime import datetime
from collections import defaultdict
D=Path(__file__).resolve().parents[1]/'storage/research'
def load(n):return json.loads((D/(n+'.json')).read_text(encoding='utf-8'))
def ts(s):return datetime.fromisoformat(s).timestamp()
A=load('analysis');P=load('predictions');T=load('trades');O=load('orders');M=[m for m in load('market') if m['interval_name']=='1m']; ids=set(load('validated_ids'))
assert len({p['id'] for p in P})==len(P)
assert len(P)==A['valid_count']+sum(A['audit_exclusions'].values())
assert all(abs(float(t['net_pnl'])-(float(t['gross_pnl'])-float(t['fees_usd'])))<.000001 for t in T)
for a in load('accounts'):
 if a['mode'].startswith('LEGACY'):continue
 orders=[o for o in O if o['mode']==a['mode']]
 assert abs(sum(float(o['fee_usd']) for o in orders)-float(a['total_fees']))<.000001
 assert abs(sum(float(o['slippage_usd']) for o in orders)-float(a['total_slippage']))<.000001
c=A['selected_on_train']; cut=ts(A['split']);mt=[ts(m['close_time']) for m in M]
equity=100.;busy=0;rs=[];daily=defaultdict(list)
for p in P:
 if p['id'] not in ids or p['horizon']!=c['horizon'] or ts(p['created_at'])<cut:continue
 start=ts(p['created_at']);end=ts(p['target_time']);sig={s['module']:s for s in json.loads(p['signals_json'])}
 s=sig.get(c['source'],{}); v=float(s.get('value',0))*float(s.get('confidence',0))
 if s.get('status')!='AVAILABLE' or abs(v)<c['threshold'] or start<=busy:continue
 i=bisect.bisect_right(mt,start);j=bisect.bisect_left(mt,end+60)
 if j>=len(M) or i>=len(M) or mt[i]-start>120 or mt[j]-end>180:continue
 direction=(1 if v>0 else -1)*c['invert'];r=direction*(float(M[j]['close_price'])/float(M[i]['close_price'])-1)*100-.24
 equity*=1+.25*r/100;rs.append(r);busy=mt[j];daily[p['created_at'][:10]].append(r)
random.seed(20261102);days=list(daily);boots=[]
for _ in range(3000):
 sample=[r for day in random.choices(days,k=len(days)) for r in daily[day]]
 boots.append(statistics.mean(sample))
boots.sort()
lags=[ts(o['prediction_created_at'])-ts(o['price_timestamp']) for o in O if not o['mode'].startswith('LEGACY')]
out={'ledger_checks':'PASS','next_closed_candle_selected_candidate':{'trades':len(rs),'return_pct':equity-100,'mean_net_trade_pct':statistics.mean(rs),'daily_block_bootstrap_mean_95pct':[boots[75],boots[2924]],'note':'Exploratory interval, few active days; no multiple-selection correction. Close-price fills remain hypothetical.'},'order_reference_price_age_seconds':{'median':statistics.median(lags),'min':min(lags),'max':max(lags)},'cost_scenarios':[]}
for b in A['bots']:
 out['cost_scenarios'].append({'mode':b['mode'],'closed_trade_pnl_at_half_cost':b['gross_before_all_costs']-.5*b['closed_costs'],'closed_trade_pnl_at_stress_cost':b['gross_before_all_costs']-2.5*b['closed_costs'],'break_even_cost_fraction_of_current':b['gross_before_all_costs']/b['closed_costs'] if b['closed_costs'] else None})
(D/'verification.json').write_text(json.dumps(out,indent=2),encoding='utf-8');print(json.dumps(out,indent=2))
