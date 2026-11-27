<?php
declare (strict_types=1);

namespace app\cool_pay2\controller;

use app\cool_pay2\model\RiskControlRules as RiskControlRulesModel;
use app\cool_pay2\service\ShardManager;
use app\cool_pay2\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;
use think\facade\Log;

/**
 * 风控规则配置管理
 */
class RiskControlRules extends Controller
{
    use SafeQueryTrait;
    /**
     * 风控规则列表
     * @auth true
     * @menu true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $this->title = '风控配置';

        // 记录日志
        $this->app->log->info('RiskControlRules index method called');

        // 获取请求参数
        $get = $this->request->get();
        $this->app->log->info('Request parameters: ' . json_encode($get));

        $query = Db::name('risk_control_rules');
        $this->app->log->info('Query object created');

        // 添加搜索条件
        if (isset($get['id']) && $get['id'] !== '') {
            $query->where('id', $get['id']);
            $this->app->log->info('Added id condition: ' . $get['id']);
        }
        if (isset($get['rule_type']) && $get['rule_type'] !== '') {
            $query->where('rule_type', $get['rule_type']);
            $this->app->log->info('Added rule_type condition: ' . $get['rule_type']);
        }
        if (isset($get['rule_name']) && $get['rule_name'] !== '') {
            $name = $this->sanitizeLike($get['rule_name']);
            $query->where('rule_name', 'like', "%{$name}%");
            $this->app->log->info('Added rule_name condition: ' . $name);
        }
        if (isset($get['enabled']) && $get['enabled'] !== '') {
            $query->where('enabled', $get['enabled']);
            $this->app->log->info('Added enabled condition: ' . $get['enabled']);
        }
        if (isset($get['date_range']) && $get['date_range'] !== '') {
            if ($dates = $this->sanitizeDateRange($get['date_range'])) {
                [$start_date, $end_date] = $dates;
                $query->whereBetween('created_at', ["{$start_date} 00:00:00", "{$end_date} 23:59:59"]);
                $this->app->log->info('Added date_range condition: ' . $start_date . ' to ' . $end_date);
            }
        }

        // 执行分页查询
        $this->app->log->info('Executing pagination query');
        $result = $query->order('id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page' => isset($get['page']) ? intval($get['page']) : 1,
            'query' => $get, // 传递所有查询参数，确保分页链接包含搜索条件
            'path' => '/admin.html#/cool_pay2/risk_control_rules/index.html', // 使用正确的路径
        ], false);
        $this->app->log->info('Pagination query executed, total: ' . $result->total());

        // 格式化数据
        $list = $result->items();

        foreach ($list as &$item) {
            // 处理NULL值，避免htmlentities错误
            $item['rule_des'] = $item['rule_des'] ?? '';
            $item['alert_message_template'] = $item['alert_message_template'] ?? '';
            $item['threshold_value'] = $item['threshold_value'] ?? 0;
            $item['time_window'] = $item['time_window'] ?? 0;
            
            // 处理时间
            if (!empty($item['created_at'])) {
                $item['created_at'] = date('Y-m-d H:i:s', strtotime($item['created_at']));
            }
            if (!empty($item['updated_at'])) {
                $item['updated_at'] = date('Y-m-d H:i:s', strtotime($item['updated_at']));
            }
        }

        // 分配变量到视图
        $this->assign([
            'list' => $list,
            'pagehtml' => $result->render(),
            'get' => $get,
            'rule_types' => $this->getRuleTypes(),
            'operators' => $this->getOperators()
        ]);
        $this->app->log->info('Variables assigned to view');

        // 渲染视图
        $this->app->log->info('Rendering view');
        return $this->fetch();
    }

    /**
     * 添加风控规则
     * @auth true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function add()
    {
        // 记录日志
        Log::info('RiskControlRules add method called');
        
        // 如果是POST请求，处理表单提交
        if ($this->request->isPost()) {
            // 记录日志
            Log::info('RiskControlRules add POST request received');
            
            // 获取表单数据
            $data = $this->request->post();
            Log::info('Form data: ' . json_encode($data));
            
            // 数据验证
            $validate = \think\facade\Validate::rule([
                'rule_type' => 'require|max:50',
                'rule_name' => 'require|max:100',
                'rule_des' => 'require|max:255',
                'operator' => 'require|in:>,<,=,!=,>=,<=',
                'threshold_value' => 'float|egt:0',
                'time_window' => 'integer|egt:0',
                'alert_message_template' => 'max:500',
                'enabled' => 'in:0,1'
            ])->message([
                'rule_type.require' => '规则类型不能为空',
                'rule_type.max' => '规则类型最多50个字符',
                'rule_name.require' => '规则名称不能为空',
                'rule_name.max' => '规则名称最多100个字符',
                'rule_des.require' => '指标描述不能为空',
                'rule_des.max' => '指标描述最多255个字符',
                'operator.require' => '操作符不能为空',
                'operator.in' => '操作符必须是：>、<、=、!=、>=、<=',
                'threshold_value.float' => '阈值必须是数字',
                'threshold_value.egt' => '阈值必须大于等于0',
                'time_window.integer' => '时间窗口必须是整数',
                'time_window.egt' => '时间窗口必须大于等于0',
                'alert_message_template.max' => '报警消息模板最多500个字符',
                'enabled.in' => '启用状态值错误'
            ]);
            
            // 验证数据
            if (!$validate->check($data)) {
                $error = $validate->getError();
                Log::error('Validation error: ' . $error);
                return $this->error($error);
            }
            
            try {
                // 跨片检查业务键 (rule_type, rule_name)（事实唯一，无 DB UNIQUE 兜底）
                $hits = ShardManager::existsAcrossShards('risk_control_rules', [
                    'rule_type' => $data['rule_type'],
                    'rule_name' => $data['rule_name'],
                ]);
                if (!empty($hits)) {
                    return $this->error('该规则类型 + 规则名称已存在于分片：' . json_encode($hits, JSON_UNESCAPED_UNICODE));
                }

                // 处理数据类型转换
                $data['threshold_value'] = isset($data['threshold_value']) ? floatval($data['threshold_value']) : 0;
                $data['time_window'] = isset($data['time_window']) ? intval($data['time_window']) : 0;
                $data['enabled'] = isset($data['enabled']) ? intval($data['enabled']) : 1;
                $data['alert_message_template'] = isset($data['alert_message_template']) ? $data['alert_message_template'] : '';
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['updated_at'] = date('Y-m-d H:i:s');

                $result = ShardManager::fanOutInsert('risk_control_rules', $data);
                Log::info('Insert result: ' . json_encode($result));
                if ($result['ok']) {
                    return json(['code' => 1, 'info' => '添加成功（已同步 ' . count($result['per_shard']) . ' 分片）', 'url' => '']);
                }
                return $this->error('部分分片同步失败：' . json_encode($result['per_shard'], JSON_UNESCAPED_UNICODE));
            } catch (\Exception $e) {
                Log::error('Exception in add method: ' . $e->getMessage());
                Log::error('Stack trace: ' . $e->getTraceAsString());
                return $this->error('添加失败: ' . $e->getMessage());
            }
        }
        
        // 分配变量到视图
        $this->assign([
            'rule_types' => $this->getRuleTypes(),
            'operators' => $this->getOperators()
        ]);
        
        // 渲染添加表单
        return $this->fetch('form');
    }

    /**
     * 编辑风控规则
     * @auth true
     */
    public function edit()
    {
        Log::info('RiskControlRules edit method called');
        
        if ($this->request->isPost()) {
            Log::info('RiskControlRules edit POST request received');
            
            // 获取表单数据
            $data = $this->request->post();
            Log::info('Form data: ' . json_encode($data));
            
            // 数据验证
            $validate = \think\facade\Validate::rule([
                'id' => 'require|integer|gt:0',
                'rule_type' => 'require|max:50',
                'rule_name' => 'require|max:100',
                'rule_des' => 'require|max:255',
                'operator' => 'require|in:>,<,=,!=,>=,<=',
                'threshold_value' => 'float|egt:0',
                'time_window' => 'integer|egt:0',
                'alert_message_template' => 'max:500',
                'enabled' => 'in:0,1'
            ])->message([
                'id.require' => 'ID不能为空',
                'id.integer' => 'ID必须是整数',
                'id.gt' => 'ID必须大于0',
                'rule_type.require' => '规则类型不能为空',
                'rule_type.max' => '规则类型最多50个字符',
                'rule_name.require' => '规则名称不能为空',
                'rule_name.max' => '规则名称最多100个字符',
                'rule_des.require' => '指标描述不能为空',
                'rule_des.max' => '指标描述最多255个字符',
                'operator.require' => '操作符不能为空',
                'operator.in' => '操作符必须是：>、<、=、!=、>=、<=',
                'threshold_value.float' => '阈值必须是数字',
                'threshold_value.egt' => '阈值必须大于等于0',
                'time_window.integer' => '时间窗口必须是整数',
                'time_window.egt' => '时间窗口必须大于等于0',
                'alert_message_template.max' => '报警消息模板最多500个字符',
                'enabled.in' => '启用状态值错误'
            ]);
            
            if (!$validate->check($data)) {
                $error = $validate->getError();
                Log::error('Validation error: ' . $error);
                return $this->error($error);
            }
            
            try {
                // 业务键 (rule_type, rule_name)；先在默认分片读原 row 拿原 key
                $originalRow = Db::name('risk_control_rules')->where('id', $data['id'])->find();
                if (empty($originalRow)) {
                    return $this->error('记录不存在或不在默认分片');
                }
                $origType = (string)$originalRow['rule_type'];
                $origName = (string)$originalRow['rule_name'];
                // 仅当业务键真改了才跨片查重（事实唯一，无 DB UNIQUE 兜底）
                $newType = (string)($data['rule_type'] ?? '');
                $newName = (string)($data['rule_name'] ?? '');
                if ($newType !== $origType || $newName !== $origName) {
                    $hits = ShardManager::existsAcrossShards('risk_control_rules', [
                        'rule_type' => $newType,
                        'rule_name' => $newName,
                    ]);
                    if (!empty($hits)) {
                        return $this->error('新规则类型+名称已存在于分片：' . json_encode($hits, JSON_UNESCAPED_UNICODE));
                    }
                }

                // 处理数据
                $updateData = [
                    'rule_type' => $data['rule_type'],
                    'rule_name' => $data['rule_name'],
                    'rule_des' => $data['rule_des'],
                    'operator' => $data['operator'],
                    'threshold_value' => isset($data['threshold_value']) ? floatval($data['threshold_value']) : 0,
                    'time_window' => isset($data['time_window']) ? intval($data['time_window']) : 0,
                    'alert_message_template' => isset($data['alert_message_template']) ? $data['alert_message_template'] : '',
                    'enabled' => isset($data['enabled']) ? intval($data['enabled']) : 1,
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                $perShard = ShardManager::fanOutWriteInTx(function ($conn) use ($origType, $origName, $updateData) {
                    return Db::connect($conn)->name('risk_control_rules')
                        ->where('rule_type', $origType)
                        ->where('rule_name', $origName)
                        ->update($updateData);
                });
                Log::info('Update result: ' . json_encode($perShard));
                $failed = array_filter($perShard, static fn($r) => !($r['ok'] ?? false));
                if (empty($failed)) {
                    return json(['code' => 1, 'info' => '更新成功（' . count($perShard) . ' 分片）', 'url' => '']);
                }
                return $this->error('部分分片更新失败：' . json_encode($failed, JSON_UNESCAPED_UNICODE));
            } catch (\Exception $e) {
                Log::error('Exception in edit method: ' . $e->getMessage());
                return $this->error('更新失败: ' . $e->getMessage());
            }
        }
        
        // 获取要编辑的数据
        $id = $this->request->get('id');
        if (empty($id)) {
            return $this->error('参数错误');
        }
        
        $vo = Db::name('risk_control_rules')->where('id', $id)->find();
        if (empty($vo)) {
            return $this->error('风控规则不存在');
        }
        
        $this->assign([
            'vo' => $vo,
            'rule_types' => $this->getRuleTypes(),
            'operators' => $this->getOperators()
        ]);
        return $this->fetch('form');
    }

    /**
     * 删除风控规则
     * @auth true
     */
    public function remove()
    {
        Log::info('RiskControlRules remove method called');
        
        $id = $this->request->post('id');
        if (empty($id)) {
            return $this->error('参数错误');
        }
        
        try {
            $original = Db::name('risk_control_rules')->where('id', $id)->find();
            if (empty($original)) {
                return $this->error('记录不存在或不在默认分片');
            }
            $origType = (string)$original['rule_type'];
            $origName = (string)$original['rule_name'];
            $perShard = ShardManager::fanOutWriteInTx(function ($conn) use ($origType, $origName) {
                return Db::connect($conn)->name('risk_control_rules')
                    ->where('rule_type', $origType)
                    ->where('rule_name', $origName)
                    ->delete();
            });
            Log::info('Delete result: ' . json_encode($perShard));
            $failed = array_filter($perShard, static fn($r) => !($r['ok'] ?? false));
            if (empty($failed)) {
                return json(['code' => 1, 'info' => '删除成功（' . count($perShard) . ' 分片）']);
            }
            return $this->error('部分分片删除失败：' . json_encode($failed, JSON_UNESCAPED_UNICODE));
        } catch (\Exception $e) {
            Log::error('Exception in remove method: ' . $e->getMessage());
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取规则类型选项
     * @return array
     */
    private function getRuleTypes()
    {
        return [
            '成交' => '成交规则',
            '坐市平台' => '坐市平台规则',
            '对冲平台' => '对冲平台规则'
        ];
    }

    /**
     * 获取操作符选项
     * @return array
     */
    private function getOperators()
    {
        return [
            '>' => '大于 (>)',
            '<' => '小于 (<)',
            '=' => '等于 (=)',
            '!=' => '不等于 (!=)',
            '>=' => '大于等于 (>=)',
            '<=' => '小于等于 (<=)'
        ];
    }
}
