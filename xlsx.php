<?php
declare(strict_types=1);

function xlsxXml(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_XML1,'UTF-8');}
function xlsxInline(string $value):string{
 $value=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u','',str_replace(["\r\n","\r","\n","\t"],' ',$value))??'';
 return '<is><t xml:space="preserve">'.xlsxXml($value).'</t></is>';
}
function xlsxCol(int $number):string{$out='';while($number>0){$number--;$out=chr(65+$number%26).$out;$number=intdiv($number,26);}return $out;}
function xlsxCell(string $address,$value,int $style=0,bool $numeric=false):string{
 return '<c r="'.$address.'" s="'.$style.'"'.($numeric?'><v>'.$value.'</v>':' t="inlineStr">'.xlsxInline((string)$value)).'</c>';
}
function xlsxStyles():string{
 $fonts=[['Calibri',10,'10264E',false],['Calibri',20,'004B7B',true],['Calibri',9,'61758D',false],['Calibri',10,'FFFFFF',true],['Calibri',10,'08234B',true],['Calibri',9,'0074DB',false],['Calibri',9,'008744',false],['Calibri',9,'ED2735',false],['Calibri',9,'C05F00',false],['Calibri',9,'7543C7',false]];
 $fills=['','', '00527D','E7F4FF','FFFFFF','F7FAFE','D7F7E4','D6ECFF','FFE6C8','FFDCDF','EBDFFF','E4F3FF'];
 // font, fill, border, number format, alignment, wrapping
 $styles=[[0,0,0,0,'left',false],[1,4,0,0,'left',false],[4,4,0,0,'left',false],[2,4,0,0,'right',false],[3,2,1,0,'left',false],[4,4,1,0,'left',false],[0,4,1,0,'left',true],[4,5,1,0,'left',false],[4,4,1,4,'right',false],[5,3,1,164,'right',false],[4,3,1,164,'right',false],[0,4,1,0,'left',true],[0,5,1,0,'left',true],[0,4,1,1,'center',false],[0,4,1,0,'center',false],[0,4,1,165,'right',false],[0,5,1,165,'right',false],[4,4,1,164,'right',false],[4,5,1,164,'right',false],[4,11,2,0,'right',false],[4,11,2,4,'right',false],[4,11,2,164,'right',false],[2,4,0,0,'left',false],[4,4,0,0,'left',false],[6,6,1,0,'center',false],[5,7,1,0,'center',false],[8,8,1,0,'center',false],[7,9,1,0,'center',false],[9,10,1,0,'center',false],[0,4,1,166,'center',false]];
 $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="3">';
 foreach([164=>'[Blue]#,##0.00" Dr";[Red]#,##0.00" Cr";0.00',165=>'#,##0.00;[Red](#,##0.00);"-"',166=>'dd/mm/yyyy'] as $id=>$format)$xml.='<numFmt numFmtId="'.$id.'" formatCode="'.xlsxXml($format).'"/>';
 $xml.='</numFmts><fonts count="'.count($fonts).'">';
 foreach($fonts as [$name,$size,$color,$bold])$xml.='<font>'.($bold?'<b/>':'').'<sz val="'.$size.'"/><color rgb="FF'.$color.'"/><name val="'.$name.'"/></font>';
 $xml.='</fonts><fills count="'.count($fills).'"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
 foreach(array_slice($fills,2) as $color)$xml.='<fill><patternFill patternType="solid"><fgColor rgb="FF'.$color.'"/><bgColor indexed="64"/></patternFill></fill>';
 $xml.='</fills><borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border><border>';
 foreach(['left','right','top','bottom'] as $side)$xml.='<'.$side.' style="thin"><color rgb="FFE2EAF3"/></'.$side.'>';
 $xml.='<diagonal/></border><border><left/><right/><top style="thin"><color rgb="FFB9DAEF"/></top><bottom style="double"><color rgb="FFB9DAEF"/></bottom><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="'.count($styles).'">';
 foreach($styles as [$font,$fill,$border,$format,$align,$wrap])$xml.='<xf numFmtId="'.$format.'" fontId="'.$font.'" fillId="'.$fill.'" borderId="'.$border.'" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="'.$align.'" vertical="center"'.($wrap?' wrapText="1"':'').' indent="'.($align==='left'?1:0).'"/></xf>';
 return $xml.'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}
function xlsxChartCache(array $values,string $range,bool $numeric):string{
 $tag=$numeric?'num':'str';$xml='<c:'.$tag.'Ref><c:f>'.xlsxXml($range).'</c:f><c:'.$tag.'Cache>'.($numeric?'<c:formatCode>General</c:formatCode>':'').'<c:ptCount val="'.count($values).'"/>';
 foreach($values as $i=>$value)$xml.='<c:pt idx="'.$i.'"><c:v>'.xlsxXml((string)$value).'</c:v></c:pt>';
 return $xml.'</c:'.$tag.'Cache></c:'.$tag.'Ref>';
}
function xlsxChart(string $type,string $title,array $labels,array $series):string{
 $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><c:date1904 val="0"/><c:lang val="en-US"/><c:roundedCorners val="0"/><c:chart><c:title><c:tx><c:rich><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="en-US" sz="1100" b="1"><a:solidFill><a:srgbClr val="08234B"/></a:solidFill></a:rPr><a:t>'.xlsxXml($title).'</a:t></a:r></a:p></c:rich></c:tx><c:overlay val="0"/></c:title><c:autoTitleDeleted val="0"/><c:plotArea><c:layout/>';
 $tag=$type==='bar'?'barChart':($type==='line'?'lineChart':'doughnutChart');
 $xml.='<c:'.$tag.'>'.($type==='bar'?'<c:barDir val="col"/><c:grouping val="clustered"/>':($type==='line'?'<c:grouping val="standard"/>':'')).'<c:varyColors val="'.($type==='doughnut'?1:0).'"/>';
 foreach($series as $i=>$seriesData){
  $xml.='<c:ser><c:idx val="'.$i.'"/><c:order val="'.$i.'"/><c:tx><c:v>'.xlsxXml($seriesData['name']).'</c:v></c:tx>';
  if($type==='line')$xml.='<c:spPr><a:ln w="22000"><a:solidFill><a:srgbClr val="'.$seriesData['color'].'"/></a:solidFill></a:ln></c:spPr><c:marker><c:symbol val="circle"/><c:size val="5"/><c:spPr><a:solidFill><a:srgbClr val="'.$seriesData['color'].'"/></a:solidFill><a:ln><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:ln></c:spPr></c:marker>';
  elseif($type==='bar')$xml.='<c:spPr><a:solidFill><a:srgbClr val="'.$seriesData['color'].'"/></a:solidFill><a:ln><a:noFill/></a:ln></c:spPr>';
  else{foreach(['318CFF','FF6175','21BF89','FFAE33','A566ED','7C8BA1','00B8CF','4474AC'] as $point=>$color){if($point>=count($labels['values']))break;$xml.='<c:dPt><c:idx val="'.$point.'"/><c:spPr><a:solidFill><a:srgbClr val="'.$color.'"/></a:solidFill><a:ln w="9000"><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill></a:ln></c:spPr></c:dPt>';}}
  $xml.='<c:cat>'.xlsxChartCache($labels['values'],$labels['range'],false).'</c:cat><c:val>'.xlsxChartCache($seriesData['values'],$seriesData['range'],true).'</c:val>'.($type==='line'?'<c:smooth val="0"/>':'').'</c:ser>';
 }
 if($type==='bar')$xml.='<c:gapWidth val="100"/><c:overlap val="0"/>';
 if($type==='doughnut')$xml.='<c:firstSliceAng val="270"/><c:holeSize val="66"/>';else $xml.='<c:axId val="100201"/><c:axId val="100202"/>';
 $xml.='</c:'.$tag.'>';
 if($type!=='doughnut')$xml.='<c:catAx><c:axId val="100201"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="b"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:crossAx val="100202"/><c:crosses val="autoZero"/><c:auto val="1"/><c:lblAlgn val="ctr"/><c:lblOffset val="100"/></c:catAx><c:valAx><c:axId val="100202"/><c:scaling><c:orientation val="minMax"/></c:scaling><c:delete val="0"/><c:axPos val="l"/><c:majorGridlines><c:spPr><a:ln w="8000"><a:solidFill><a:srgbClr val="E4EDF6"/></a:solidFill></a:ln></c:spPr></c:majorGridlines><c:numFmt formatCode="#,##0" sourceLinked="0"/><c:majorTickMark val="none"/><c:minorTickMark val="none"/><c:tickLblPos val="nextTo"/><c:crossAx val="100201"/><c:crosses val="autoZero"/><c:crossBetween val="between"/></c:valAx>';
 $xml.='</c:plotArea>';
 if($type!=='line')$xml.='<c:legend><c:legendPos val="'.($type==='doughnut'?'r':'t').'"/><c:overlay val="0"/></c:legend>';
 return $xml.'<c:plotVisOnly val="0"/><c:dispBlanksAs val="gap"/><c:showDLblsOverMax val="0"/></c:chart><c:spPr><a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill><a:ln w="9000"><a:solidFill><a:srgbClr val="D7E5F1"/></a:solidFill></a:ln></c:spPr><c:txPr><a:bodyPr/><a:lstStyle/><a:p><a:pPr><a:defRPr sz="850"><a:solidFill><a:srgbClr val="445C78"/></a:solidFill></a:defRPr></a:pPr><a:endParaRPr lang="en-US"/></a:p></c:txPr></c:chartSpace>';
}
function xlsxDownload(string $filename,array $report):void{
 if(!class_exists('ZipArchive')){http_response_code(503);exit('Excel export requires the PHP zip extension.');}
 $grid=[];$heights=[];$merges=[];
 $set=static function(int $r,int $c,$value,int $style=0,bool $numeric=false)use(&$grid){$grid[$r][$c]=xlsxCell(xlsxCol($c).$r,$value,$style,$numeric);};
 $merge=static function(int $r,int $first,int $last,$value,int $style=0,bool $numeric=false)use($set,&$merges){$set($r,$first,$value,$style,$numeric);for($c=$first+1;$c<=$last;$c++)$set($r,$c,'',$style);if($last>$first)$merges[]=xlsxCol($first).$r.':'.xlsxCol($last).$r;};
 $merge(1,1,4,'✈ MIZORA Travel Ledger',1);$merge(1,5,8,'Generated On: '.$report['generated'],3);$heights[1]=34;
 $merge(2,1,4,'Agency Ledger Report',2);$merge(2,5,8,$report['agency'],3);$heights[2]=23;
 $merge(3,1,4,'Agency Details',4);$merge(3,5,8,'Summary ('.$report['period'].')',4);$heights[3]=23;
 $account=$report['account'];$details=['Agency Name'=>$account['name'],'Agency Code'=>$report['code'],'Contact Person'=>$account['contact_person']??'—','Phone'=>$account['phone']?:'—','Email'=>$account['email']??'—','Address'=>$account['address']??'—'];$r=4;
 foreach($details as $label=>$value){$merge($r,1,2,$label.':',5);$merge($r,3,4,$value,6);$heights[$r]=19;$r++;}
 foreach([['Opening Balance (PKR)',$report['opening'],9,true],['Total Debit (PKR)',$report['debit'],8,true],['Total Credit (PKR)',$report['credit'],8,true],['Closing Balance (PKR)',$report['closing'],10,true],['Prepared By',$report['prepared'],6,false],['Statement Reference',$report['reference'],6,false]] as $i=>[$label,$value,$style,$numeric]){$merge($i+4,5,6,$label,7);$merge($i+4,7,8,$numeric?moneyDecimal($value):$value,$style,$numeric);}
 $heights[10]=10;$head=11;$heights[$head]=26;
 foreach(['No','Date','Category','Reference / PNR','Description','Debit (PKR)','Credit (PKR)','Balance (PKR)'] as $i=>$label)$set($head,$i+1,$label,4);
 $r=$head+1;
 foreach($report['rows'] as $i=>$entry){
  $band=$i%2;$base=$band?12:11;$amount=$band?16:15;$balance=$band?18:17;
  $set($r,1,$i+1,13,true);$date=(new DateTimeImmutable($entry['date'],new DateTimeZone('UTC')))->getTimestamp()/86400+25569;$set($r,2,(int)$date,29,true);
  $set($r,3,$entry['category'],['green'=>24,'blue'=>25,'orange'=>26,'red'=>27,'violet'=>28][$entry['tone']]??25);
  $set($r,4,$entry['reference']?:'—',$base);$set($r,5,$entry['description']?:'—',$base);
  $set($r,6,moneyDecimal($entry['debit']),$amount,true);$set($r,7,moneyDecimal($entry['credit']),$amount,true);$set($r,8,moneyDecimal($entry['balance']),$balance,true);$heights[$r]=max(24,min(132,max((int)ceil(strlen($entry['description'])/50)*12,(int)ceil(strlen($entry['reference'])/22)*12)));$r++;
 }
 if(!$report['rows']){$merge($r,1,8,'No transactions in the selected period.',11);$heights[$r]=26;$r++;}
 $lastData=$r-1;$totalRow=$r;$merge($r,1,5,'TOTALS',19);$set($r,6,moneyDecimal($report['debit']),20,true);$set($r,7,moneyDecimal($report['credit']),20,true);$set($r,8,moneyDecimal($report['closing']),21,true);$heights[$r]=29;
 $chartTop=$r+2;$chartY=0;for($j=1;$j<$chartTop;$j++)$chartY+=($heights[$j]??15)*12700;
 $notes=$chartTop+12;$merge($notes,1,8,'Notes:',23);$heights[$notes]=23;
 foreach(['This is an exported report from MIZORA Travel Ledger.','All amounts are in PKR (Pakistani Rupees).','Opening balance is brought forward from the previous period.','This file can be used for analysis and record keeping.'] as $i=>$note){$merge($notes+1+$i,1,8,($i+1).'.  '.$note,22);$heights[$notes+1+$i]=18;}
 $lastRow=$notes+6;$merge($lastRow,1,4,'✈ MIZORA Travel Ledger',2);$merge($lastRow,5,8,'Secure. Simple. Professional.',3);$heights[$lastRow]=27;
 ksort($grid);$sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><tabColor rgb="FF00527D"/><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:H'.$lastRow.'"/><sheetViews><sheetView showGridLines="0" tabSelected="1" zoomScale="85" workbookViewId="0"><pane ySplit="11" topLeftCell="A12" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A12" sqref="A12"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/><cols>';
 foreach([5,13,21,19,42,18,18,20] as $i=>$width)$sheet.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>';
 $sheet.='</cols><sheetData>';
 for($j=1;$j<=$lastRow;$j++){$sheet.='<row r="'.$j.'" ht="'.($heights[$j]??15).'" customHeight="1">';if(isset($grid[$j])){ksort($grid[$j]);$sheet.=implode('',$grid[$j]);}$sheet.='</row>';}
 $sheet.='</sheetData><autoFilter ref="A11:H'.max(11,$lastData).'"/><mergeCells count="'.count($merges).'">';
 foreach($merges as $range)$sheet.='<mergeCell ref="'.$range.'"/>';
 $sheet.='</mergeCells><printOptions horizontalCentered="1"/><pageMargins left="0.25" right="0.25" top="0.35" bottom="0.4" header="0.15" footer="0.2"/><pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/><headerFooter><oddFooter>&amp;LMIZORA Travel Ledger&amp;RPage &amp;P of &amp;N</oddFooter></headerFooter><drawing r:id="rIdDrawing"/></worksheet>';
 $months=$report['months']?:[['label'=>'No entries','debit'=>0,'credit'=>0,'balance'=>$report['opening']]];
 $categories=$report['categories'];if(count($categories)>7){$rest=array_sum(array_slice($categories,7,null,true));$categories=array_slice($categories,0,7,true);$categories[isset($categories['Other'])?'Other categories':'Other']=$rest;}if(!$categories)$categories=['No entries'=>0];
 $monthLabels=array_column($months,'label');$debits=array_map(static fn($v)=>moneyDecimal($v['debit']),$months);$credits=array_map(static fn($v)=>moneyDecimal($v['credit']),$months);$balances=array_map(static fn($v)=>moneyDecimal($v['balance']),$months);$categoryLabels=[];$categoryValues=[];
 foreach($categories as $label=>$count){$categoryLabels[]=$label.' '.($report['count']?round($count/$report['count']*100):0).'%';$categoryValues[]=$count;}
 $dataSheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">';
 foreach(['Month','Debit','Credit','Closing Balance','','Category','Entries'] as $i=>$label)$dataSheet.=xlsxCell(xlsxCol($i+1).'1',$label);
 $dataSheet.='</row>';
 for($i=0;$i<max(count($months),count($categories));$i++){$row=$i+2;$dataSheet.='<row r="'.$row.'">';if(isset($months[$i])){$dataSheet.=xlsxCell('A'.$row,$monthLabels[$i]).xlsxCell('B'.$row,$debits[$i],0,true).xlsxCell('C'.$row,$credits[$i],0,true).xlsxCell('D'.$row,$balances[$i],0,true);}if(isset($categoryLabels[$i]))$dataSheet.=xlsxCell('F'.$row,$categoryLabels[$i]).xlsxCell('G'.$row,$categoryValues[$i],0,true);$dataSheet.='</row>';}
 $dataSheet.='</sheetData></worksheet>';
 $monthEnd=count($months)+1;$categoryEnd=count($categories)+1;$labels=['values'=>$monthLabels,'range'=>"'Chart Data'!\$A\$2:\$A\$".$monthEnd];
 $charts=[xlsxChart('bar','Monthly Transactions',$labels,[['name'=>'Debit','color'=>'318CFF','values'=>$debits,'range'=>"'Chart Data'!\$B\$2:\$B\$".$monthEnd],['name'=>'Credit','color'=>'21BF89','values'=>$credits,'range'=>"'Chart Data'!\$C\$2:\$C\$".$monthEnd]]),xlsxChart('doughnut','Category Wise Distribution',['values'=>$categoryLabels,'range'=>"'Chart Data'!\$F\$2:\$F\$".$categoryEnd],[['name'=>'Entries','color'=>'318CFF','values'=>$categoryValues,'range'=>"'Chart Data'!\$G\$2:\$G\$".$categoryEnd]]),xlsxChart('line','Account Balance Trend',$labels,[['name'=>'Closing Balance','color'=>'147BFA','values'=>$balances,'range'=>"'Chart Data'!\$D\$2:\$D\$".$monthEnd]])];
 $drawing='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">';
 for($i=0;$i<3;$i++){$drawing.='<xdr:absoluteAnchor><xdr:pos x="'.($i*3570000+20000).'" y="'.(int)$chartY.'"/><xdr:ext cx="3490000" cy="1905000"/><xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="'.($i+2).'" name="Chart '.($i+1).'"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr><xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId'.($i+1).'"/></a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:absoluteAnchor>';}
 $drawing.='</xdr:wsDr>';
 $workbook='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets><sheet name="Ledger Statement" sheetId="1" r:id="rId1"/><sheet name="Chart Data" sheetId="2" state="hidden" r:id="rId2"/></sheets><definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">&apos;Ledger Statement&apos;!$11:$11</definedName><definedName name="_xlnm.Print_Area" localSheetId="0">&apos;Ledger Statement&apos;!$A$1:$H$'.$lastRow.'</definedName></definedNames><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
 $rels=static function(array $items):string{$xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';foreach($items as [$id,$type,$target])$xml.='<Relationship Id="'.$id.'" Type="http://schemas.openxmlformats.org/'.$type.'" Target="'.$target.'"/>';return $xml.'</Relationships>';};
 $office='officeDocument/2006/relationships/';
 $parts=['xl/workbook.xml'=>$workbook,'xl/worksheets/sheet1.xml'=>$sheet,'xl/worksheets/sheet2.xml'=>$dataSheet,'xl/styles.xml'=>xlsxStyles(),'xl/drawings/drawing1.xml'=>$drawing,'_rels/.rels'=>$rels([['rId1',$office.'officeDocument','xl/workbook.xml']]),'xl/_rels/workbook.xml.rels'=>$rels([['rId1',$office.'worksheet','worksheets/sheet1.xml'],['rId2',$office.'worksheet','worksheets/sheet2.xml'],['rId3',$office.'styles','styles.xml']]),'xl/worksheets/_rels/sheet1.xml.rels'=>$rels([['rIdDrawing',$office.'drawing','../drawings/drawing1.xml']]),'xl/drawings/_rels/drawing1.xml.rels'=>$rels([['rId1',$office.'chart','../charts/chart1.xml'],['rId2',$office.'chart','../charts/chart2.xml'],['rId3',$office.'chart','../charts/chart3.xml']])];
 foreach($charts as $i=>$chart)$parts['xl/charts/chart'.($i+1).'.xml']=$chart;
 $types='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>';
 foreach(['xl/workbook.xml'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml','xl/worksheets/sheet1.xml'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml','xl/worksheets/sheet2.xml'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml','xl/styles.xml'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml','xl/drawings/drawing1.xml'=>'application/vnd.openxmlformats-officedocument.drawing+xml','xl/charts/chart1.xml'=>'application/vnd.openxmlformats-officedocument.drawingml.chart+xml','xl/charts/chart2.xml'=>'application/vnd.openxmlformats-officedocument.drawingml.chart+xml','xl/charts/chart3.xml'=>'application/vnd.openxmlformats-officedocument.drawingml.chart+xml'] as $name=>$type)$types.='<Override PartName="/'.$name.'" ContentType="'.$type.'"/>';
 $parts['[Content_Types].xml']=$types.'</Types>';
 $tmp=tempnam(sys_get_temp_dir(),'mizora-xlsx-');if($tmp===false)throw new RuntimeException('Excel export unavailable');
 try{$zip=new ZipArchive();if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Excel export unavailable');foreach($parts as $name=>$content){$zip->addFromString($name,$content);$zip->setCompressionName($name,ZipArchive::CM_DEFLATE,3);}$zip->close();header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');header('Content-Length: '.filesize($tmp));header('X-Content-Type-Options: nosniff');readfile($tmp);}finally{@unlink($tmp);}
}
