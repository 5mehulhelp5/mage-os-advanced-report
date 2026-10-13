<?php

declare(strict_types=1);

namespace MageOS\AdvancedReport\Model\DataSource\Mysql;

/**
 * Database server flavour, which decides how statement timeouts are expressed.
 */
enum ServerFlavour: string
{
    case Mysql = 'mysql';
    case MariaDb = 'mariadb';
}
