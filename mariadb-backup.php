<?php
declare(strict_types=1);

/** Consistent logical dump for InnoDB tables, written incrementally to a private temp file. */
function writeMariaBackup(PDO $pdo,string $path):array {
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    $fh=@fopen($path,'x+b');if(!$fh){$pdo->rollBack();throw new RuntimeException('Cannot create private backup file');}
    @chmod($path,0600);$rows=0;
    try{
        $write=static function(string $data)use($fh):void{$length=strlen($data);$offset=0;while($offset<$length){$n=fwrite($fh,substr($data,$offset));if($n===false||$n===0)throw new RuntimeException('Could not write a complete backup file');$offset+=$n;}};
        $write("-- MIZORA MariaDB logical backup; generated ".gmdate('c')." UTC\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n");
        $tables=$pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
        foreach($tables as $table){
            if(!preg_match('/^[A-Za-z0-9_]+$/',$table))throw new RuntimeException('Unexpected database table name');
            $quoted='`'.$table.'`';$create=$pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_NUM);
            if(!$create||!isset($create[1]))throw new RuntimeException('Could not read table definition for backup');
            $write("\n".$create[1].";\n");
            $read=$pdo->query('SELECT * FROM '.$quoted);$columns=[];
            for($i=0;$i<$read->columnCount();$i++)$columns[]='`'.$read->getColumnMeta($i)['name'].'`';
            while($record=$read->fetch(PDO::FETCH_NUM)){
                $values=[];foreach($record as $value)$values[]=$value===null?'NULL':$pdo->quote((string)$value);
                $write('INSERT INTO '.$quoted.' ('.implode(',',$columns).') VALUES ('.implode(',',$values).");\n");$rows++;
            }
        }
        $write("COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n");if(!fflush($fh))throw new RuntimeException('Could not flush backup file');$size=ftell($fh);fclose($fh);$fh=null;
        $pdo->commit();
        return ['rows'=>$rows,'bytes'=>(int)$size];
    }catch(Throwable $ex){if(is_resource($fh))fclose($fh);if($pdo->inTransaction())$pdo->rollBack();@unlink($path);throw $ex;}
}
