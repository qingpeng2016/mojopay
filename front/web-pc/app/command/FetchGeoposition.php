<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

class FetchGeoposition extends Command
{
    protected function configure()
    {
        $this->setName('fetch:geoposition')
             ->addArgument('lat', Argument::REQUIRED, 'Latitude')
             ->addArgument('lng', Argument::REQUIRED, 'Longitude')
             ->setDescription('Fetch AccuWeather GeoPosition for given coordinates');
    }

    protected function execute(Input $input, Output $output)
    {
        $lat = $input->getArgument('lat');
        $lng = $input->getArgument('lng');
        $pythonPath = "/usr/bin/python3";
        $scriptPath = app()->getRootPath() . "scripts/accuweather_geoposition.py";

        $logDir = runtime_path() . 'log/python/';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);

        $command = "{$pythonPath} {$scriptPath} " . escapeshellarg($lat) . " " . escapeshellarg($lng) . " 2>&1";
        $rawResponse = shell_exec($command);

        $logFile = $logDir . date('Ymd') . '_debug.log';
        $logEntry = "[" . date('H:i:s') . "] CMD: $command\n";
        $logEntry .= "[" . date('H:i:s') . "] RESULT: " . ($rawResponse ?: "EMPTY") . "\n";
        $logEntry .= str_repeat("-", 40) . "\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);

        $output->write((string)($rawResponse ?? ''));
    }
}
