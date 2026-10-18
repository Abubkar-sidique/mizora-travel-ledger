<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$destination=$argv[1]??'';$parent=realpath(dirname($destination));
if($destination===''||!$parent||file_exists($destination)||is_link($destination)){fwrite(STDERR,"Usage: php ops/build-package.php NEW_ABSOLUTE_ZIP_PATH (parent must exist)\n");exit(1);}
$root=dirname(__DIR__);
require_once $root.'/ledger/runtime.php';
if(pathWithin($parent,$root.'/ledger'))throw new RuntimeException('Package must be outside the public root');
$files=['ledger/index.php','ledger/ui.php','ledger/xlsx.php','ledger/statement.php','ledger/hardening.php','ledger/runtime.php','ledger/database.php','ledger/schema-mariadb.php','ledger/mariadb-backup.php','ledger/router.php','ledger/README.md','ledger/.htaccess','ledger/assets/.htaccess','ledger/assets/app.css','ledger/assets/app.js','ledger/assets/validate.js','ledger/assets/print.js','ledger/assets/logo.svg','ledger/assets/brand-plane.svg','ledger/assets/statement.css','ledger/assets/statement-preview.js','ledger/assets/statement-sky.png'];
foreach(['.htaccess','README.md','hostinger-shared.md'] as $file)$files[]='ops/'.$file;
$archive=new ZipArchive();if($archive->open($destination,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Cannot create package');$manifest='';
foreach($files as $file){if(!is_file($root.'/'.$file))throw new RuntimeException('Missing allowlisted source '.$file);if(!$archive->addFile($root.'/'.$file,$file))throw new RuntimeException('Packaging failure');$manifest.=hash_file('sha256',$root.'/'.$file).'  '.$file."\n";}
$archive->addFromString('SHA256SUMS',$manifest);if(!$archive->close())throw new RuntimeException('Cannot finalize package');
echo hash_file('sha256',$destination).'  '.basename($destination).PHP_EOL;
