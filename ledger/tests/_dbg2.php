<?php
require __DIR__.'/../hardening.php';
require __DIR__.'/../accounting.php';
var_dump(moneyDecimal(150000));
var_dump(moneyDecimal(-250000));
var_dump(ledgerForeignToPkr(1050,281250000));
var_dump(ledgerForeignToPkr(1050000000,281250000));
