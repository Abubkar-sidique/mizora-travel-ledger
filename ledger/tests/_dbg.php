<?php
$subject = 'AB1500CD';
$out = preg_replace('/(?<=AB)(?=(\d{3})+(?!\d))/', '-', $subject);
var_dump($out);
$out2 = preg_replace('/(?<=AB)(?=(\d{3})+(?!\d))/', chr(92).'$1', $subject);
var_dump($out2);
