<?php
function moneyDecimal(int $v):string{return number_format($v/100,2,'.','');}
function m(int $v){$neg=$v<0;$parts=explode('.',moneyDecimal(abs($v)));return ($neg?'-':'').preg_replace('/\B(?=(\d{3})+(?!\d))/','" .,". "',$parts[0]).'.'.$parts[1];}
var_dump(m(-125050), m(0), m(199), m(-1), m(999999999999));


