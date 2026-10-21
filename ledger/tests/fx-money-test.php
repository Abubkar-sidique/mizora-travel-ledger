<?php
declare(strict_types=1);
require __DIR__.'/../hardening.php';
require __DIR__.'/../accounting.php';

$fail=0;
function ck(string $label,bool $ok):void{global $fail;if(!$ok){$fail++;echo "FAIL: $label\n";}else echo "ok: $label\n";}

function m(int $v){$parts=explode('.',moneyDecimal($v));$sign='';if(str_starts_with($parts[0],'-')){$sign='-';$parts[0]=substr($parts[0],1);}return $sign.preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$parts[0]).'.'.$parts[1];}
function cents(string $v):int{$v=trim($v);if(preg_match('/^-?\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/',$v))$v=str_replace(',','',$v);if(!preg_match('/^-?\d{1,13}(?:\.\d{1,2})?$/',$v))throw new InvalidArgumentException('Invalid amount');$neg=str_starts_with($v,'-');$v=ltrim($v,'-');$a=explode('.',$v,2);$c=((int)$a[0])*100+(int)str_pad($a[1]??'0',2,'0');return $neg?-$c:$c;}

// moneyDecimal / m formatting — all values are PAISA (minor units): 1500 paisa = Rs 15.00
ck('m(0) == "0.00"',m(0)==='0.00');
ck('m(1500 paisa) == "15.00"',m(1500)==='15.00');
ck('m(150000 paisa) == "1,500.00"',m(150000)==='1,500.00');
ck('m(15000 paisa) == "150.00"',m(15000)==='150.00');
ck('m(-250000 paisa) == "-2,500.00"',m(-250000)==='-2,500.00');
ck('m(125050 paisa) == "1,250.50"',m(125050)==='1,250.50');
ck('m(-125050 paisa) == "-1,250.50"',m(-125050)==='-1,250.50');
ck('m(1 paisa) == "0.01"',m(1)==='0.01');
ck('m(-1 paisa) == "-0.01"',m(-1)==='-0.01');
ck('m(999999 paisa) == "9,999.99"',m(999999)==='9,999.99');
ck('m(123456789 paisa) == "1,234,567.89"',m(123456789)==='1,234,567.89');
ck('m(5 paisa) == "0.05"',m(5)==='0.05');

// fx conversion
ck('fx 10000 @280 == 2,800,000',ledgerForeignToPkr(10000,280*1000000)===2800000);
ck('fx 1050 @280 == 294,000',ledgerForeignToPkr(1050,280*1000000)===294000);
ck('fx 1050 paisa @281.25 paisa-per-paisa == 295,313 (half-up)',ledgerForeignToPkr(1050,28125*10000)===295313);
ck('fx 10500 paisa @281.25 == 2,953,125 (exact)',ledgerForeignToPkr(10500,28125*10000)===2953125);
ck('fx 10000 paisa @281.25 == 2,812,500',ledgerForeignToPkr(10000,28125*10000)===2812500);
ck('fx 1000 paisa @280.00 == 280,000 paisa',ledgerForeignToPkr(1000,280*1000000)===280000);
ck('fx rate text 280 == "280"',ledgerFxRateText(280*1000000)==='280');

// cents parsing
ck('cents("1,500.00")==150000',cents('1,500.00')===150000);
ck('cents("-25.5")==-2550',cents('-25.5')===-2550);
ck('cents("0.05")==5',cents('0.05')===5);
ck('cents("-0.01")==-1',cents('-0.01')===-1);
ck('cents("12,345,678.89")==1234567889',cents('12,345,678.89')===1234567889);
try{cents('1.234');ck('cents rejects 3 decimals',false);}catch(InvalidArgumentException){ck('cents rejects 3 decimals',true);}
try{cents('abc');ck('cents rejects text',false);}catch(InvalidArgumentException){ck('cents rejects text',true);}

echo $fail===0?"ALL PASS\n":"$fail FAILURES\n";
exit($fail===0?0:1);
