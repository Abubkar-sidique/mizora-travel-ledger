<?php
function moneyDecimal(int $v):string{return number_format($v/100,2,'.','');}
function m2(int $v){$neg=$v<0;$parts=explode('.',moneyDecimal(abs($v)));return ($neg?'-':'').preg_replace('/\B(?=(\d{3})+(?!\d))/','","',$parts[0]).'.'.$parts[1];}
var_dump(m2(-125050), m2(0), m2(199), m2(-1), m2(999999999999));
