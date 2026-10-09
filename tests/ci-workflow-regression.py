#!/usr/bin/env python3
"""Static CI safety/structure gate; does not run GitHub, Docker, SQL or setup-php."""
from pathlib import Path
import re
import yaml
root=Path(__file__).resolve().parent.parent
path=root/'.github/workflows/sqlserver-acceptance.yml'
raw=path.read_text()
w=yaml.load(raw,Loader=yaml.BaseLoader)
n=0
def check(ok,label):
 global n
 n+=1
 if not ok: raise AssertionError(label)
check(set(w['on'])=={'workflow_dispatch','workflow_call'},'Manual entry/reusable call only')
check(w['on']['workflow_dispatch']['inputs']['confirm_synthetic_only']['default']=='false','Explicit confirmation defaults false')
check(w['permissions']=={'contents':'read'},'Read-only workflow token')
check(w['concurrency']['cancel-in-progress']=='false','No accidental concurrent cancellation')
check(set(w['jobs'])=={'sqlserver'},'Single smallest SQL job')
j=w['jobs']['sqlserver']
check(j['runs-on']=='ubuntu-22.04','Standard x64 Linux runner')
check(int(j['timeout-minutes'])<=20,'Bounded CI time')
check('husodrn46/Lumen' in j['if'] and 'inputs.confirm_synthetic_only' in j['if'] and "github.event_name == 'workflow_dispatch'" in j['if'],'Repo/confirmation guard')
service=j['services']['sqlserver']
check(bool(re.fullmatch(r'mcr\.microsoft\.com/mssql/server:2022-latest@sha256:[0-9a-f]{64}',service['image'])),'Official digest-pinned SQL image')
check(service['ports']==['127.0.0.1:1433:1433'],'SQL host port bound to loopback')
check(service['env']['MSSQL_PID']=='Developer' and service['env']['ACCEPT_EULA']=='Y','Explicit nonproduction SQL licensing')
check(service['env']['MSSQL_SA_PASSWORD']==j['env']['LUMEN_CI_SQL_SA_PASSWORD'],'Matching ephemeral service/bootstrap identity')
check('${{ github.run_id }}' in j['env']['LUMEN_CI_SQL_SA_PASSWORD'],'Run-specific bootstrap credential')
check('${{ secrets.' not in raw and 'pull_request_target' not in raw,'No stored secret or privileged PR trigger')
check('deploy' not in ' '.join(step.get('run','') for step in j['steps']),'No deployment command')
for step in j['steps']:
 if 'uses' in step: check(bool(re.fullmatch(r'[^@]+@[0-9a-f]{40}',step['uses'])),'Action SHA pinned')
check(j['steps'][0]['with']['persist-credentials']=='false','Checkout credential not persisted')
setup=next(s for s in j['steps'] if s.get('uses','').startswith('shivammathur/setup-php'))
check(setup['with']['php-version']=='8.3' and 'pdo_sqlsrv-5.13.3' in setup['with']['extensions'],'Supported fixed PHP/SQL driver')
run='\n'.join(s.get('run','') for s in j['steps'])
for command in ['composer audit --locked','composer check-platform-reqs','php scripts/dependency-smoke.php','php tests/ci/sqlserver-prepare.php','php tests/sqlserver-regression.php','php tests/sqlserver-idempotency.php','php tests/sqlserver-favorites.php','php tests/sqlserver-favorite-transfer.php']:
 check(command in run,'Required acceptance step: '+command)
check('--no-scripts --no-plugins' in run,'Install avoids package scripts/plugins')
check('upload-artifact' not in raw and 'actions/cache' not in raw,'No retained credential/data artifacts or cache')
check(run.index('composer audit')<run.index('sqlserver-prepare.php'),'Audit before DB write')
check('LumenTest_Start_CI' in raw and 'LumenTest_Idem_CI' in raw and 'LumenTest_Fav_CI' in raw,'Separate fresh DBs')
check(all(s.get('env',{}).get('LUMEN_TEST_SQL_DSN','').startswith('sqlsrv:Server=127.0.0.1,1433;') for s in j['steps'] if 'LUMEN_TEST_SQL_DSN' in s.get('env',{})),'Test DSNs match IPv4 loopback binding')
check('continue-on-error' not in raw,'No skipped SQL failure gate')
lint=yaml.load((root/'.github/workflows/lint.yml').read_text(),Loader=yaml.BaseLoader)
caller=lint['jobs']['sql-acceptance']
check(caller['uses']=='./.github/workflows/sqlserver-acceptance.yml' and 'secrets' not in caller,'Same-ref reusable call without secret inheritance')
check(lint['on']['workflow_dispatch']['inputs']['confirm_synthetic_only']['default']=='false' and "github.event_name == 'workflow_dispatch'" in caller['if'],'Existing Lint manual call opt-in; PR/push cannot start SQL')
transfer_step=next(s for s in j['steps'] if s.get('run')=='php tests/sqlserver-favorite-transfer.php')
check(run.index('php tests/sqlserver-favorites.php')<run.index('php tests/sqlserver-favorite-transfer.php'),'Preference fixture precedes transfer acceptance')
check(transfer_step['env']['LUMEN_TEST_SQL_DSN']=='sqlsrv:Server=127.0.0.1,1433;Database=LumenTest_Fav_CI;Encrypt=yes;TrustServerCertificate=yes','Transfer acceptance reuses fixed synthetic DB only')
# Catch shell syntax errors in each Bash run block without executing commands.
import subprocess
for step in j['steps']:
 if 'run' in step: subprocess.run(['bash','-n'],input=step['run'],text=True,check=True)
import os
result=subprocess.run(['php',str(root/'tests/sqlserver-favorite-transfer.php')],env={'PATH':os.environ['PATH']},capture_output=True,text=True)
check(result.returncode==2 and result.stdout=='' and 'check 0;' in result.stderr,'Local invocation rejected before PDO/schema writes')
print(f'TAMAM: {n} workflow assertion + bash syntax (static; remote CI/SQL not executed).')
