<?php
declare(strict_types=1);

require __DIR__ . '/support.php';

$project = temporaryProject();
try {
    $package = json_decode(file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR)['name'];
    file_put_contents($project . '/composer.json', json_encode([
        'name' => 'dbman/consumer-check',
        'repositories' => [[
            'type' => 'path',
            'url' => dirname(__DIR__),
            'options' => ['symlink' => false, 'versions' => [$package => 'dev-main']],
        ]],
        'require' => [$package => 'dev-main'],
        'config' => ['vendor-dir' => 'dependencies', 'bin-dir' => 'tools', 'allow-plugins' => false],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    [$code, $stdout, $stderr] = runProcess(
        ['composer', 'install', '--no-interaction', '--no-progress', '--prefer-dist', '--no-plugins', '--no-scripts'],
        $project,
        [
            'COMPOSER' => $project . '/composer.json',
            'COMPOSER_HOME' => $project . '/.composer',
            'COMPOSER_VENDOR_DIR' => $project . '/dependencies',
            'COMPOSER_BIN_DIR' => $project . '/tools',
        ],
    );
    check($code === 0, 'Consumer installation failed. Verify Composer and access to the declared dependencies.');
    check(is_file($project . '/tools/dbman'), 'Composer must install the package executable.');
    check(is_file($project . '/dependencies/autoload.php'), 'Custom vendor-dir must contain the autoloader.');
    check(!is_link($project . '/dependencies/' . $package), 'The consumer must exercise a copied package, not the source checkout.');
    [$code, $stdout, $stderr] = runProcess([PHP_BINARY, $project . '/tools/dbman', 'help'], sys_get_temp_dir());
    check($code === 0 && $stderr === '' && str_contains($stdout, 'Read-only'), 'Installed binary must find the consumer autoloader outside its project directory.');
    [$code, $stdout, $stderr] = runProcess([PHP_BINARY, $project . '/tools/dbman', 'rollback', '--steps=0'], $project);
    check($code === 1 && str_contains($stderr, 'Usage: rollback'), 'Installed binary must preserve validation and exit codes.');
    echo "Passed {$checks} Composer consumer checks.\n";
} finally {
    removeTemporaryProject($project);
}
