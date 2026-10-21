<?php
declare(strict_types=1);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
var_dump($pdo->query("SELECT CAST(10000/1000000 AS INTEGER)*280500000 + CAST(((10000%1000000)*280500000+500000)/1000000 AS INTEGER) AS v")->fetchColumn());
var_dump($pdo->query("SELECT (10000%1000000)*280500000 AS v")->fetchColumn());
var_dump($pdo->query("SELECT 2805000500000/1000000 AS v")->fetchColumn());
var_dump($pdo->query("SELECT CAST(2805000500000/1000000 AS INTEGER) AS v")->fetchColumn());
