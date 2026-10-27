<?php

declare(strict_types=1);

$root = dirname(__DIR__).'/vendor/illuminate/database/';
$paths = ['Console', 'Migrations', 'Eloquent/Factories', 'Seeder.php', 'DatabaseServiceProvider.php'];
$remove = function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path) as $entry) { $remove($entry->getPathname()); }
        rmdir($path);
    } elseif (is_file($path)) { unlink($path); }
};
foreach ($paths as $path) { $remove($root.$path); }
echo "Pruned database Console, Migrations, Factories, Seeder and service provider.\n";
