"""Small, regularized offline probability experiments, chronological and purged."""
import json,math,statistics,random
from pathlib import Path
from datetime import datetime
from collections import defaultdict
D=Path(__file__).resolve().parents[1]/'storage/research'
def load(n):return json.loads((D/(n+'.json')).read_text(encoding='utf-8'))
def ts(s):return datetime.fromisoformat(s).timestamp()
P=load('predictions'); A=load('analysis'); ids=set(load('validated_ids'));cut=ts(A['split'])
mods=['ATHENA','POSEIDON','HEPHAESTUS','CRONOS','MARKET_RELATIONS']
for p in P:
 p['t']=ts(p['created_at']);p['end']=ts(p['target_time'])+120
 p['signals']={s['module']:s for s in json.loads(p['signals_json'])}
P=[p for p in P if p['id'] in ids and p['actual_direction'] in ('UP','DOWN')]
def independent(rows):
 out=[];end=0
 for p in rows:
  if p['t']>end:out.append(p);end=p['end']
 return out
def features(p,kind):
 q=max(.001,min(.999,float(p['probability_up'])))
 if kind=='platt':return [1.,math.log(q/(1-q))]
 selected=mods if kind=='all_modules' else [m for m in mods if m!='CRONOS']
 return [1.]+[float(p['signals'].get(m,{}).get('value',0))*float(p['signals'].get(m,{}).get('confidence',0)) if p['signals'].get(m,{}).get('status')=='AVAILABLE' else 0. for m in selected]
def sigmoid(x):return 1/(1+math.exp(-max(-30,min(30,x))))
def train(rows,kind):
 x=[features(p,kind) for p in rows];y=[float(p['actual_direction']=='UP') for p in rows]
 w=[0.]*len(x[0])
 for epoch in range(600):
  grad=[0.]*len(w)
  for xx,yy in zip(x,y):
   err=sigmoid(sum(a*b for a,b in zip(w,xx)))-yy
   for j in range(len(w)):grad[j]+=err*xx[j]
  w=[v-.2*(grad[j]/len(x)+(.05*v if j else 0)) for j,v in enumerate(w)]
 return w
def score(rows,probs):
 return {'n':len(rows),'brier':statistics.mean((q-(p['actual_direction']=='UP'))**2 for p,q in zip(rows,probs)),'hit_pct':100*statistics.mean((q>.5)==(p['actual_direction']=='UP') for p,q in zip(rows,probs))}
out=[]
for h in ['15m','1h','4h','24h']:
 rows=[p for p in P if p['horizon']==h]
 tr=independent([p for p in rows if p['end']<cut and ts(p['evaluated_at'])<cut]);te=independent([p for p in rows if p['t']>=cut])
 prior=(1+sum(p['actual_direction']=='UP' for p in tr))/(2+len(tr))
 z={'horizon':h,'train_n':len(tr),'test_n':len(te),'original':score(te,[float(p['probability_up']) for p in te]),'constant_from_train':score(te,[prior]*len(te)),'coin':score(te,[.5]*len(te))}
 for kind in ['platt','all_modules','without_macro']:
  w=train(tr,kind);probs=[sigmoid(sum(a*b for a,b in zip(w,features(p,kind)))) for p in te]
  z[kind]={'weights':w,**score(te,probs)}
 out.append(z)
(D/'calibration_experiments.json').write_text(json.dumps(out,indent=2),encoding='utf-8')
print(json.dumps(out,indent=2))
