<?php

declare(strict_types=1);

// Run after the image build: docker run --rm <image> php scripts/verify_composer_build.php
// This check must work without database configuration or a running database.
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$services = require $root . '/app/service.php';
if (is_file($root . '/vendor/services.php')) {
    $services = array_merge($services, require $root . '/vendor/services.php');
}

$errors = [];
foreach ([\think\migration\Service::class, \think\admin\Library::class] as $service) {
    if (!in_array($service, $services, true) || !class_exists($service)) {
        $errors[] = "Missing framework service: {$service}";
    }
}

// Every service declared by an installed package via extra.think.services must be registered.
// Docker builds run "composer install --no-plugins --no-scripts", so the think-install plugin
// never generates vendor/services.php and every service has to be listed in app/service.php.
// A missing service fails silently at runtime; notably, omitting think\app\Service disables
// multi-app mode, so every request falls into the default index module and the first URL
// segment is treated as a controller name ("controller not exists:app\index\controller\Login").
$installed = json_decode(file_get_contents($root . '/vendor/composer/installed.json'), true);
$declared  = [];
foreach ($installed['packages'] ?? $installed as $package) {
    foreach ((array)($package['extra']['think']['services'] ?? []) as $service) {
        $declared[$package['name']][] = $service;
    }
}
foreach ($declared as $packageName => $packageServices) {
    foreach ($packageServices as $service) {
        if (!in_array($service, $services, true)) {
            $errors[] = "{$packageName} declares service {$service}, but it is not registered in app/service.php";
        }
    }
}

// Plugin packages also declare app namespaces. Their upstream classes must not
// take precedence over this application's customized controllers and services.
foreach ([
    \app\admin\controller\Login::class => '/app/admin/controller/Login.php',
    \app\admin\controller\Index::class => '/app/admin/controller/Index.php',
] as $class => $path) {
    $actual = (new ReflectionClass($class))->getFileName();
    if ($actual !== $root . $path) {
        $errors[] = "{$class} resolved to {$actual}, expected {$root}{$path}";
    }
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "Composer build OK: framework services registered; application classes use repository sources.\n";
