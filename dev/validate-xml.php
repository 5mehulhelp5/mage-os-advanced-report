<?php

declare(strict_types=1);

use MageOS\AdvancedReport\Test\Tooling\XsdValidator;

require __DIR__ . '/../vendor/autoload.php';

$etc = dirname(__DIR__) . '/etc';
$errors = (new XsdValidator())->validateFile($etc . '/report_datasets.xml', $etc . '/report_datasets_file.xsd');

foreach ($errors as $error) {
    fwrite(STDERR, 'report_datasets.xml ' . $error . PHP_EOL);
}

exit($errors === [] ? 0 : 1);
