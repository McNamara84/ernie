<?php
require 'vendor/autoload.php';

use Nitotm\Eld\LanguageDetector;

$eld = new LanguageDetector();

$ergebnis = $eld->detect('Das ist ein Test.');
echo $ergebnis->language;