<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||empty($argv[1]))exit(2);
$db=new SQLite3($argv[1],SQLITE3_OPEN_READONLY);
$rows=$db->query("SELECT id FROM accounts WHERE agency_id=1 AND kind IN ('cash','bank') ORDER BY id");
$ids=[];while($row=$rows->fetchArray(SQLITE3_NUM))$ids[]=(string)$row[0];
echo implode('|',$ids);
$db->close();
