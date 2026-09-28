from pathlib import Path
import re

roots = [Path(r'C:\xampp\htdocs\trade_prometheus\config'), Path(r'C:\xampp\htdocs\trade_indicador')]
pattern = re.compile(r'(DB_PASS|DB_USER|PASSWORD|API[_-]?KEY|SECRET|TOKEN)', re.I)
provider = re.compile(r'(getenv|\$_ENV|\$_SERVER|config|env\(|require|include|PROMETHEUS_CONFIG)', re.I)
for root in roots:
    if not root.exists():
        continue
    for path in root.rglob('*'):
        if not path.is_file() or path.suffix.lower() not in {'.php', '.ini', '.json', '.env', '.txt'}:
            continue
        try:
            source = path.read_text(encoding='utf-8', errors='ignore')
        except OSError:
            continue
        labels = set()
        literal = False
        details = []
        for line in source.splitlines():
            if pattern.search(line):
                labels.update(re.findall(r'DB_PASS|DB_USER|PASSWORD|API[_-]?KEY|SECRET|TOKEN', line, re.I))
                if re.search(r'(=>|=|define\s*\()', line) and not provider.search(line):
                    literal = True
                details.append(re.sub(r"(['\"]).*?\1", '[redacted]', line).strip())
        if labels:
            print(f'{path}: credential_refs={",".join(sorted(labels))}; possible_literal_assignment={"yes" if literal else "no"}')
            for detail in details:
                print('  ' + detail)
