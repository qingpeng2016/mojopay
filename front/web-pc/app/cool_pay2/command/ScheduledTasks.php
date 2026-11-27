<?php
declare (strict_types = 1);

namespace app\cool_pay2\command;

use think\admin\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Log;
use app\cool_pay2\model\DailyHostingRecord;

/**
 * 定时任务命令
 * Class ScheduledTasks
 * @package app\cool_pay2\command
 */
class ScheduledTasks extends Command
{
    protected function configure()
    {
        $this->setName('coolpay:scheduled');
        $this->setDescription('CoolPay Scheduled Tasks for data maintenance');
        
        // 添加参数选项
        $this->addArgument('task', null, 'Task type: hosting|test', 'hosting');
    }

    /**
     * 执行定时任务
     * @param Input $input
     * @param Output $output
     */
    protected function execute(Input $input, Output $output)
    {
        $task = $input->getArgument('task');
        
        $output->writeln("=== CoolPay 定时任务执行 ===");
        $output->writeln("当前时间: " . date('Y-m-d H:i:s'));
        $output->writeln("执行任务: {$task}");
        
        try {
            switch ($task) {
                case 'hosting':
                    $this->executeHostingTask($output);
                    break;
                case 'test':
                    $this->executeTestTask($output);
                    break;
                default:
                    $output->writeln("未知任务类型: {$task}");
                    return;
            }
            
            Log::info("CoolPay定时任务执行成功 - 任务类型: {$task}, 时间: " . date('Y-m-d H:i:s'));
            $output->writeln("任务执行完成！");
            
        } catch (\Exception $e) {
            $output->writeln("任务执行失败: " . $e->getMessage());
            Log::error("CoolPay定时任务执行失败 - 任务类型: {$task}, 错误: " . $e->getMessage());
        }
    }

    /**
     * 执行主机服务数据获取任务
     * @param Output $output
     */
    private function executeHostingTask(Output $output)
    {
        $output->writeln("开始获取主1机服务数据...");
        
        // 请求API
        $apiData = $this->requestHostingAPI();
        
        if (!$apiData) {
            throw new \Exception("API请求失败");
        }
        
        $output->writeln("API请求成功，状态: " . $apiData['status']);
        
        // 保存数据
        $savedCount = $this->saveHostingData($apiData, $output);
        
        $output->writeln("成功保存 {$savedCount} 条主机服务记录");
        Log::info("主机服务数据获取完成，保存 {$savedCount} 条记录");
    }

    /**
     * 请求主机服务API
     * @return array|false
     */
    private function requestHostingAPI()
    {
        $url = 'https://lala.gg/check_email_registered.php';
        
        $postData = http_build_query([
            'action' => 'check_email_registered',
            'is_active_hosting' => 'true'
        ]);
        
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Cookie: WHMCSy551iLvnhYt7=2rflbbb8e249nd2s3833andks6'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            Log::error("API请求失败，HTTP状态码: {$httpCode}, 响应: " . $response);
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (!$data || $data['status'] !== 'success') {
            Log::error("API返回数据异常: " . $response);
            return false;
        }
        
        return $data;
    }

    /**
     * 保存主机服务数据
     * @param array $apiData
     * @param Output $output
     * @return int
     */
    private function saveHostingData(array $apiData, Output $output): int
    {
        $today = date('Y-m-d');
        $savedCount = 0;
        
        $output->writeln("开始保存主机服务数据到数据库...");
        $output->writeln("记录日期: {$today}");
        
        // 检查今天是否已经有记录，如果有则删除重新插入
        $existingCount = DailyHostingRecord::where('record_date', $today)->count();
        if ($existingCount > 0) {
            $output->writeln("发现今日已有 {$existingCount} 条记录，先删除旧记录...");
            DailyHostingRecord::where('record_date', $today)->delete();
            $output->writeln("已删除今日旧记录");
        }
        
        if (isset($apiData['active_hosting']['services']) && is_array($apiData['active_hosting']['services'])) {
            $totalServices = count($apiData['active_hosting']['services']);
            $output->writeln("准备保存 {$totalServices} 条服务记录:");
            
            foreach ($apiData['active_hosting']['services'] as $index => $service) {
                try {
                    $output->writeln("  [" . ($index + 1) . "/{$totalServices}] 保存服务ID: {$service['id']}, 金额: ￥{$service['amount']}, 到期日: {$service['nextduedate']}");
                    
                    DailyHostingRecord::create([
                        'record_date' => $today,
                        'service_id' => (int)$service['id'],
                        'amount' => (float)$service['amount'],
                        'next_due_date' => $service['nextduedate']
                    ]);
                    $savedCount++;
                    $output->writeln("    ✓ 保存成功");
                } catch (\Exception $e) {
                    $output->writeln("    ✗ 保存失败: " . $e->getMessage());
                    Log::error("保存服务记录失败，服务ID: {$service['id']}, 错误: " . $e->getMessage());
                }
            }
        } else {
            $output->writeln("警告: API返回数据中没有找到services数组");
        }
        
        $output->writeln("数据保存完成，成功保存 {$savedCount} 条记录");
        return $savedCount;
    }

    /**
     * 执行测试任务
     * @param Output $output
     */
    private function executeTestTask(Output $output)
    {
        $output->writeln("Hello World! 定时任务运行成功！");
    }
}
