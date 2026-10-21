<?php
declare(strict_types=1);
// Isolated harness that replicates the exact m() body from ledger/index.php
require __DIR__.'/../hardening.php';
function m(int $v){$parts=explode('.',moneyDecimal(abs($v)));return ($v<0?'-':'').preg_replace('/\B(?=(\d{3})+(?!\d))/','$1',$parts[0]).'.'.$parts[1];}
$cases=[[0,'0.00'],[1500,'1,500.00'],[150,'150.00'],[-2500,'-2,500.00'],[123456789,'1,234,567.89'],[5,'0.05'],[-5,'-0.05'],[125050,'1,250.50'],[-125050,'-1,250.50'],[99999999999,'99,999,999.99']];
$fail=0;
foreach($cases as [$v,$exp]){ $got=m($v); $ok=$got===$exp; if(!$ok)$fail++; echo ($ok?'ok ':'FAIL ')."m($v) = [$got] expected [$exp]\n"; }
// cents() replica from index.php
function cents($v){$v=trim((string)$v);if(str_contains($v,',')&&!preg_match('/^-?\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/',$v))throw new InvalidArgumentException('Invalid amount');$v=str_replace(',','',$v);if(!preg_match('/^-?\d{1,11}(?:\.\d{1,2})?$/',$v))throw new InvalidArgumentException('Invalid amount');$neg=str_starts_with($v,'-');$v=ltrim($v,'-');$a=explode('.',$v,2);$c=((int)$a[0])*100+(int)str_pad($a[1]??'',2,'0');return $neg?-$c:$c;}
foreach([['1,500.00',150000],['-25.5',-2550],['0.05',5],['-0.05',-5]] as [$v,$exp]){ $got=cents($v); $ok=$got===$exp; if(!$ok)$fail++; echo ($ok?'ok ':'FAIL ')."cents($v) = [$got] expected [$exp]\n"; }
foreach(['1.234','abc'] as $bad){ try{cents($bad); $fail++; echo "FAIL cents($bad) should reject\n";}catch(InvalidArgumentException){ echo "ok cents($bad) rejected\n"; } }
echo $fail===0?"ALL PASS\n":"$fail FAILURES\n";
exit($fail===0?0:1);
