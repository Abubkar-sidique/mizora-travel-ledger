<?php
declare(strict_types=1);

/**
 * A tagged-entry management estimate. This intentionally does not claim to
 * replace a balanced double-entry profit and loss statement.
 */
function ledgerServiceMargin(array $totals): int
{
    return (int)($totals['sales'] ?? 0)
        - (int)($totals['service_cost'] ?? 0)
        - (int)($totals['refunds'] ?? 0)
        + (int)($totals['commissions'] ?? 0)
        - (int)($totals['expenses'] ?? 0);
}

const LEDGER_CURRENCY_CODES = ['PKR','USD','EUR','GBP','AED','SAR','QAR','KWD','BHD','OMR','TRY','CNY','CAD','AUD','SGD','MYR','THB','JPY','INR','BDT','NPR'];
const LEDGER_FX_SCALE = 1000000;

/** Parse a manually confirmed PKR-per-unit rate into six-decimal fixed point. */
function ledgerFxRate(string $value): int
{
    $value=trim($value);
    if(!preg_match('/^(\d{1,6})(?:\.(\d{1,6}))?$/',$value,$m))throw new InvalidArgumentException('Exchange rate must be a positive number with up to six decimals.');
    $whole=(int)$m[1];$fraction=(int)str_pad($m[2]??'',6,'0');
    $scaled=$whole*LEDGER_FX_SCALE+$fraction;
    if($scaled<1||$scaled>100000*LEDGER_FX_SCALE)throw new InvalidArgumentException('Exchange rate is outside the supported range.');
    return $scaled;
}

/** Convert foreign minor units to PKR paisa with integer, half-up rounding. */
function ledgerForeignToPkr(int $foreignMinor,int $rateScaled):int
{
    if($foreignMinor<1||$foreignMinor>9999999999999||$rateScaled<1||$rateScaled>100000*LEDGER_FX_SCALE)throw new InvalidArgumentException('Invalid foreign amount or exchange rate.');
    $whole=intdiv($foreignMinor,LEDGER_FX_SCALE)*$rateScaled;
    $fraction=intdiv(($foreignMinor%LEDGER_FX_SCALE)*$rateScaled+intdiv(LEDGER_FX_SCALE,2),LEDGER_FX_SCALE);
    $converted=$whole+$fraction;
    if($converted<1||$converted>9999999999999)throw new InvalidArgumentException('Converted PKR amount is outside the supported range.');
    return $converted;
}

function ledgerFxRateText(int $rateScaled):string
{
    return rtrim(rtrim(number_format($rateScaled/LEDGER_FX_SCALE,6,'.',''),'0'),'.');
}
