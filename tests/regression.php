<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $checks++;
};

$appRules = file_get_contents($root.'/ledger/.htaccess');
$assetRules = file_get_contents($root.'/ledger/assets/.htaccess');
$dataRules = file_get_contents($root.'/ledger/data/.htaccess');
$supportJs = file_get_contents($root.'/ledger/assets/app.js');
$assert(is_string($appRules) && preg_match('/<FilesMatch[^>]*index\\\\\\.php/s', $appRules) === 1, 'Apache allows index.php as the entry point');
$outsideBlocks = preg_replace('/<FilesMatch\\b.*?<\\/FilesMatch>/si', '', (string)$appRules);
$assert(!preg_match('/^\\s*Require all denied\\s*$/mi', (string)$outsideBlocks), 'Apache does not deny the whole application root');
$assert(is_string($assetRules) && !preg_match('/Require all denied|Deny from all/i', $assetRules), 'Static assets are not denied by their .htaccess');
$assert(is_string($dataRules) && preg_match('/^\\s*Require all denied\\s*$/mi', $dataRules) === 1, 'Private data remains denied');
$assert(str_contains((string)$supportJs, 'new URL(whatsapp.href)') && str_contains((string)$supportJs, 'whatsappUrl.searchParams.set(\'text\',message)'), 'Support messages preserve the configured WhatsApp recipient');
$assert(!str_contains((string)$supportJs, '923476942822'), 'The stale WhatsApp recipient is absent');

$index = file_get_contents($root.'/ledger/index.php');
$hardening = file_get_contents($root.'/ledger/hardening.php');
$ui = file_get_contents($root.'/ledger/ui.php');
$assert(is_string($index) && !str_contains($index, '$dre=') && !str_contains($index, '$all=false') && !str_contains($index, '&all=1&print=1'), 'Proven-dead ledger flags stay removed');
$assert(is_string($hardening) && !str_contains($hardening, 'function monthWindow('), 'The unused date helper stays removed');
$assert(is_string($index) && !str_contains($index, 'function sheetMoney('), 'The unused money helper stays removed');
$unusedChartVars = '$ring=\'\';$legend=\'\';';
$assert(is_string($ui) && !str_contains($ui, $unusedChartVars), 'The unused chart variable stays removed');

require_once $root.'/ledger/accounting.php';
$assert(ledgerServiceMargin(['sales'=>100000,'service_cost'=>65000,'refunds'=>5000,'commissions'=>3000,'expenses'=>8000])===25000, 'Tagged service margin estimate applies sales, supplier costs, refunds, commissions and expenses with correct signs');
$assert(ledgerServiceMargin([])===0, 'Tagged service margin estimate handles an empty period');
$assert(ledgerFxRate('280.500001')===280500001 && ledgerForeignToPkr(10000,280500000)===2805000, 'Foreign currency rates and conversions use fixed-point arithmetic in PKR minor units');
$assert(ledgerForeignToPkr(1,500000)===1, 'Foreign currency conversion rounds half-up to the nearest paisa');
$invalidRate=false;try{ledgerFxRate('0');}catch(InvalidArgumentException){$invalidRate=true;}$assert($invalidRate, 'Zero exchange rates are rejected');
$assert(is_string($index) && str_contains($index, "'expense_paid'=>'Expense Paid'") && str_contains($index, "'commission_earned'=>'Commission Earned'"), 'Expense and commission categories are available to ledger entries');
$assert(is_string($index) && str_contains($index, "if(\$p==='audit')") && str_contains($ui, "['audit','Activity Log','shield']"), 'Agency activity log page is tenant-scoped and navigable');
$assert(is_string($index) && str_contains($index, "'cash'=>'Cash Account'") && str_contains($index, "'bank'=>'Bank Account'"), 'Cash and bank ledger account types are available');

fwrite(STDOUT, "PASS {$checks} regression checks\n");
