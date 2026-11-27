<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

class OutcomeLogin extends Command
{
    protected function configure()
    {
    // Configure the command with its name, argument, and description
        // Define the argument for the Polymarket URL
        $this->setName('outcome:login')
             ->addArgument('FgMarketId', Argument::REQUIRED, "The FgMarketId")
             ->setDescription('Triggers the Python login script');
    }

    protected function execute(Input $input, Output $output)
    {
       $payload = $input->getArgument('FgMarketId');
        $pythonPath = "/usr/bin/python3"; 
        $scriptPath = app()->getRootPath() . "scripts/outcome_login_2.py";

        // --- FIX: PRE-EXECUTION SETUP ---
        // Create the directory BEFORE calling the Python script
        $logDir = runtime_path() . 'log/python/';
        if (!is_dir($logDir)) mkdir($logDir, 0755, true);

        // 1. THE COMMAND (Standard mode, no redirection)
        $command = "{$pythonPath} {$scriptPath} " . escapeshellarg($payload) . " 2>&1";
        
        // 2. Execute and capture the result in a PHP variable
        $rawResponse = shell_exec($command);
        
        // 3. MANUAL LOGGING
        $logFile = $logDir . date('Ymd') . '_debug.log';
        $logEntry = "[" . date('H:i:s') . "] CMD: $command\n";
        $logEntry .= "[" . date('H:i:s') . "] RESULT: " . ($rawResponse ?: "EMPTY") . "\n";
        $logEntry .= str_repeat("-", 40) . "\n";
        
        file_put_contents($logFile, $logEntry, FILE_APPEND);

        // 4. Return the data to the Controller
        // Ensure $result is a string, even if it's empty
        $output->write((string)($rawResponse ?? ''));
    }
}
