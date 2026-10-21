<?php
declare(strict_types=1);

function statementCategory(string $key):array{
 $label=str_replace('Air Ticket','Ticket',CATS[$key]??$key);
 $tone=str_contains($key,'received')?'green':(str_contains($key,'paid')?'red':(str_contains($key,'bought')?'orange':(str_contains($key,'sold')?'blue':'violet')));
 return ['label'=>$label,'tone'=>$tone];
}
function statementBalance(int $value):string{return m(abs($value)).($value>0?' Dr':($value<0?' Cr':''));}
function statementData(array $account,int $agency,string $from,string $to,int $opening,array $user):array{
 $where='account_id=? AND agency_id=? AND deleted=0';$params=[(int)$account['id'],$agency];
 if($from!==''){$where.=' AND edate>=?';$params[]=$from;}if($to!==''){$where.=' AND edate<=?';$params[]=$to;}
 $rows=[];$months=[];$categories=[];$running=$opening;$debit=0;$credit=0;
 foreach(q("SELECT id,edate,cat,ref,note,debit,credit,currency,fx_rate,foreign_amount FROM entries WHERE $where ORDER BY edate,id",$params) as $entry){
  $d=(int)$entry['debit'];$c=(int)$entry['credit'];$running+=$d-$c;$debit+=$d;$credit+=$c;$category=statementCategory((string)$entry['cat']);
  $description=(string)$entry['note'];if((string)$entry['currency']!=='PKR')$description.=($description!==''?' · ':'').(string)$entry['currency'].' '.m((int)$entry['foreign_amount']).' @ '.ledgerFxRateText((int)$entry['fx_rate']).' PKR = PKR '.m($d+$c);
  $rows[]=['id'=>(int)$entry['id'],'date'=>$entry['edate'],'category'=>$category['label'],'tone'=>$category['tone'],'reference'=>(string)$entry['ref'],'description'=>$description,'debit'=>$d,'credit'=>$c,'balance'=>$running];
  $month=substr($entry['edate'],0,7);if(!isset($months[$month]))$months[$month]=['label'=>date('M y',strtotime($month.'-01')),'debit'=>0,'credit'=>0,'balance'=>$running];
  $months[$month]['debit']+=$d;$months[$month]['credit']+=$c;$months[$month]['balance']=$running;
  $categories[$category['label']]=($categories[$category['label']]??0)+1;
 }
 db()->commit();arsort($categories);$start=$from?:($rows[0]['date']??date('Y-m-d'));$end=$to?:date('Y-m-d');
 return ['account'=>$account,'code'=>'LGR-'.str_pad((string)$account['id'],4,'0',STR_PAD_LEFT),'opening'=>$opening,'debit'=>$debit,'credit'=>$credit,'closing'=>$running,'rows'=>array_reverse($rows),'months'=>array_values(array_slice($months,-12,null,true)),'categories'=>$categories,'count'=>count($rows),'from'=>$start,'to'=>$end,'period'=>date('d M Y',strtotime($start)).' – '.date('d M Y',strtotime($end)),'generated'=>date('d M Y h:i A'),'prepared'=>trim((string)($user['name']??''))?:'Administrator','agency'=>(string)($user['an']??''),'reference'=>'STMT-'.date('Ymd').'-'.str_pad((string)$account['id'],4,'0',STR_PAD_LEFT)];
}
function statementPrint(array $report):void{
 $a=$report['account'];$css='assets/statement.css?v='.filemtime(__DIR__.'/assets/statement.css');
 $back='?'.http_build_query(['p'=>'acc','id'=>(int)$a['id'],'from'=>(string)($_GET['from']??''),'to'=>(string)($_GET['to']??''),'per'=>perPage()]);
 $details=['Agency Name'=>$a['name'],'Agency Code'=>$report['code'],'Contact Person'=>$a['contact_person']??'—','Phone'=>$a['phone']?:'—','Email'=>$a['email']??'—','Address'=>$a['address']??'—'];
 header('Content-Type: text/html; charset=UTF-8');
 echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($a['name']).' · Ledger Statement</title><link rel="icon" href="assets/brand-plane.svg"><link rel="stylesheet" href="'.e($css).'"></head><body><nav class="preview-tools"><a href="'.e($back).'">← Back to Ledger</a><span>Statement preview · A4</span><button type="button" data-print>Print / Save PDF</button></nav><article class="statement-paper"><header class="statement-banner"><img class="sky-photo" src="assets/statement-sky.png" alt=""><div class="sky-shade"></div><div class="brand-panel"><img src="assets/brand-plane.svg" alt=""><div><b>MIZORA</b><strong>Travel Ledger</strong><small>Your Travel Business Partner</small></div></div><div class="document-title"><h1>LEDGER STATEMENT</h1><p>'.e($report['period']).'</p></div></header><main class="statement-content"><div class="statement-overview"><section class="agency-panel"><h2>Agency Details</h2><dl>';
 foreach($details as $key=>$value)echo '<div><dt>'.e($key).'</dt><dd>'.e((string)$value).'</dd></div>';
 echo '</dl></section><section class="summary-panel"><h2>Statement Summary</h2><div class="summary-grid">';
 foreach([['opening','Opening Balance',$report['opening'],'As on '.date('d M Y',strtotime($report['from'])),'↗'],['debit','Total Debit',$report['debit'],'Tickets Sold + Payments Paid','↗'],['credit','Total Credit',$report['credit'],'Tickets Purchased + Payments Received','↓'],['closing','Closing Balance',$report['closing'],'As on '.date('d M Y',strtotime($report['to'])),'⚖']] as [$tone,$label,$value,$hint,$icon]){
  echo '<div class="summary-box '.$tone.'"><span class="summary-icon" aria-hidden="true">'.$icon.'</span><div><small>'.e($label).'</small><strong>PKR '.($tone==='opening'||$tone==='closing'?statementBalance($value):m($value)).'</strong><em>'.e($hint).'</em></div></div>';
 }
 echo '</div></section></div><h2 class="history-title">Transaction History</h2><table class="statement-table"><colgroup><col style="width:4%"><col style="width:10%"><col style="width:14%"><col style="width:13%"><col style="width:25%"><col style="width:11%"><col style="width:11%"><col style="width:12%"></colgroup><thead><tr><th>#</th><th>Date</th><th>Category</th><th>Reference / PNR</th><th>Description</th><th class="money">Debit (PKR)</th><th class="money">Credit (PKR)</th><th class="money">Balance (PKR)</th></tr></thead><tbody>';
 foreach($report['rows'] as $i=>$row){echo '<tr><td class="number">'.($i+1).'</td><td class="date">'.date('d/m/Y',strtotime($row['date'])).'</td><td><span class="category '.$row['tone'].'">'.e($row['category']).'</span></td><td class="reference">'.e($row['reference']?:'—').'</td><td>'.e($row['description']?:'—').'</td><td class="money">'.($row['debit']?m($row['debit']):'–').'</td><td class="money">'.($row['credit']?m($row['credit']):'–').'</td><td class="money balance '.($row['balance']<0?'negative':'').'">'.statementBalance($row['balance']).'</td></tr>';}
 if(!$report['rows'])echo '<tr><td colspan="8" class="empty">No transactions in the selected period.</td></tr>';
 echo '</tbody><tfoot><tr><td colspan="5" class="total-label">TOTALS</td><td class="money">'.m($report['debit']).'</td><td class="money">'.m($report['credit']).'</td><td class="money balance '.($report['closing']<0?'negative':'').'">'.statementBalance($report['closing']).'</td></tr></tfoot></table><section class="statement-notes"><div><h3>Notes:</h3><ol><li>This statement shows all transactions for the selected period.</li><li>Opening balance is brought forward from the previous period.</li><li>All amounts are in PKR (Pakistani Rupees).</li><li>For any queries, please contact your account manager.</li></ol></div><dl><div><dt>Generated By</dt><dd>'.e($report['prepared']).'</dd></div><div><dt>Generated On</dt><dd>'.e($report['generated']).'</dd></div><div><dt>Reference</dt><dd>'.e($report['reference']).'</dd></div></dl></section></main><footer class="statement-footer"><div class="footer-brand"><img src="assets/brand-plane.svg" alt=""><div><b>MIZORA</b><strong>Travel Ledger</strong></div></div><p>Secure. Simple. Professional. Travel Agency Ledger System</p><div class="footer-agency">'.e($report['agency']).'<small>'.count($report['rows']).' transactions · PKR</small></div></footer></article><script src="assets/statement-preview.js?v='.filemtime(__DIR__.'/assets/statement-preview.js').'"></script></body></html>';exit;
}
