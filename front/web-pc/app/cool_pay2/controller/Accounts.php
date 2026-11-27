<?php
declare (strict_types=1);

namespace app\cool_pay2\controller;

use app\cool_pay2\model\Accounts as AccountsModel;
use app\cool_pay2\const\Enum;
use app\cool_pay2\service\ShardManager;
use app\cool_pay2\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;
use think\facade\Log;

/**
 * 账号管理
 */
class Accounts extends Controller
{
    use SafeQueryTrait;
    /**
     * 账号列表
     * @auth true
     * @menu true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $this->title = '账号管理';

        // 记录日志
        $this->app->log->info('Accounts index method called');

        // 获取请求参数
        $get = $this->request->get();
        $this->app->log->info('Request parameters: ' . json_encode($get));

        $query = Db::name('accounts');
        $this->app->log->info('Query object created');

        // 添加搜索条件
        if (isset($get['id']) && $get['id'] !== '') {
            $query->where('id', $get['id']);
            $this->app->log->info('Added id condition: ' . $get['id']);
        }
        if (isset($get['vendor']) && $get['vendor'] !== '') {
            $query->where('vendor', $get['vendor']);
            $this->app->log->info('Added vendor condition: ' . $get['vendor']);
        }
        if (isset($get['account_type']) && $get['account_type'] !== '') {
            $query->where('account_type', $get['account_type']);
            $this->app->log->info('Added account_type condition: ' . $get['account_type']);
        }
        if (isset($get['market_account_id']) && $get['market_account_id'] !== '') {
            $searchTerm = $this->sanitizeJsonLike($get['market_account_id']);
            $query->where(function($q) use ($searchTerm) {
                $q->where('market_account_id', 'like', "%{$searchTerm}%")
                  ->whereOr('info', 'like', "%\"user_id\":\"{$searchTerm}%")
                  ->whereOr('info', 'like', "%\"account_id\":\"{$searchTerm}%");
            });
            $this->app->log->info('Added market_account_id condition: ' . $searchTerm);
        }
        if (isset($get['desc']) && $get['desc'] !== '') {
            $descTerm = $this->sanitizeLike($get['desc']);
            $query->where('desc', 'like', "%{$descTerm}%");
            $this->app->log->info('Added desc condition: ' . $descTerm);
        }
        if (isset($get['status']) && $get['status'] !== '') {
            $query->where('status', $get['status']);
            $this->app->log->info('Added status condition: ' . $get['status']);
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
            'path' => '/admin.html#/cool_pay2/accounts/index.html', // 使用正确的路径
        ], false);
        $this->app->log->info('Pagination query executed, total: ' . $result->total());

        // 格式化数据
        $list = $result->items();

        foreach ($list as &$item) {
            // 处理NULL值，避免htmlentities错误
            $item['desc'] = $item['desc'] ?? '';
            $item['info'] = $item['info'] ?? '';
            $item['status'] = $item['status'] ?? 'Normal';
            
            // 处理金额格式
            $item['balance'] = number_format((float)$item['balance'], 8, '.', '');
            $item['available_balance'] = number_format((float)$item['available_balance'], 8, '.', '');
            $item['frozen_balance'] = number_format((float)$item['frozen_balance'], 8, '.', '');
            
            // 如果市场账号ID为空，尝试从info字段解析user_id或account_id
            if (empty($item['market_account_id']) && !empty($item['info'])) {
                $infoData = json_decode($item['info'], true);
                if (is_array($infoData)) {
                    if (isset($infoData['user_id']) && !empty($infoData['user_id'])) {
                        $item['market_account_id'] = $infoData['user_id'];
                    } elseif (isset($infoData['account_id']) && !empty($infoData['account_id'])) {
                        $item['market_account_id'] = $infoData['account_id'];
                    }
                }
            }
            
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
            'vendors' => $this->getVendors(),
            'account_types' => $this->getAccountTypes(),
            'statuses' => $this->getStatuses()
        ]);
        $this->app->log->info('Variables assigned to view');

        // 渲染视图
        $this->app->log->info('Rendering view');
        return $this->fetch();
    }

    /**
     * 添加账号
     * @auth true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function add()
    {
        // 记录日志
        Log::info('Accounts add method called');
        
        // 如果是POST请求，处理表单提交
        if ($this->request->isPost()) {
            // 记录日志
            Log::info('Accounts add POST request received');
            
            // 获取表单数据
            $data = $this->request->post();
            Log::info('Form data: ' . json_encode($data));
            
            // 数据验证
            $validate = \think\facade\Validate::rule([
                'vendor' => 'max:50',
                'account_type' => 'require|max:50',
                'market_account_id' => 'require|max:20',
                'desc' => 'max:255',
                'balance' => 'float|egt:0',
                'available_balance' => 'float|egt:0',
                'frozen_balance' => 'float|egt:0',
                'status' => 'max:50'
            ])->message([
                'vendor.max' => '渠道最多50个字符',
                'account_type.require' => '账号类型不能为空',
                'account_type.max' => '账号类型最多50个字符',
                'market_account_id.require' => '市场账号ID不能为空',
                'market_account_id.max' => '市场账号ID最多20个字符',
                'desc.max' => '描述最多255个字符',
                'balance.float' => '总资金余额必须是数字',
                'balance.egt' => '总资金余额不能小于0',
                'available_balance.float' => '可用资金必须是数字',
                'available_balance.egt' => '可用资金不能小于0',
                'frozen_balance.float' => '冻结资金必须是数字',
                'frozen_balance.egt' => '冻结资金不能小于0',
                'status.max' => '状态最多50个字符'
            ]);
            
            // 验证数据
            if (!$validate->check($data)) {
                $error = $validate->getError();
                Log::error('Validation error: ' . $error);
                return $this->error($error);
            }
            
            try {
                // 跨片检查业务键 (vendor, market_account_id) 是否已存在
                $hits = ShardManager::existsAcrossShards('accounts', [
                    'vendor' => $data['vendor'],
                    'market_account_id' => $data['market_account_id'],
                ]);
                if (!empty($hits)) {
                    return $this->error('该渠道下的市场账号 ID 已存在于分片：' . json_encode($hits, JSON_UNESCAPED_UNICODE));
                }
                
                // 设置默认字段
                $data['vendor'] = isset($data['vendor']) ? $data['vendor'] : '';
                $data['account_type'] = isset($data['account_type']) ? $data['account_type'] : '';
                $data['desc'] = isset($data['desc']) ? $data['desc'] : '';
                $data['balance'] = isset($data['balance']) ? floatval($data['balance']) : 0;
                $data['available_balance'] = isset($data['available_balance']) ? floatval($data['available_balance']) : 0;
                $data['frozen_balance'] = isset($data['frozen_balance']) ? floatval($data['frozen_balance']) : 0;
                $data['status'] = isset($data['status']) ? $data['status'] : 'Normal';
                $data['info'] = isset($data['info']) ? $data['info'] : '';
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['updated_at'] = date('Y-m-d H:i:s');
                
                // 跨片同步 INSERT（id 不对齐，业务键 (vendor, market_account_id) 跨片唯一）
                $result = ShardManager::fanOutInsert('accounts', $data);
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
            'vendors' => $this->getVendors(),
            'account_types' => $this->getAccountTypes(),
            'statuses' => $this->getStatuses()
        ]);
        
        // 渲染添加表单
        return $this->fetch('form');
    }

    /**
     * 编辑账号
     * @auth true
     */
    public function edit()
    {
        Log::info('Accounts edit method called');
        
        if ($this->request->isPost()) {
            Log::info('Accounts edit POST request received');
            
            // 获取表单数据
            $data = $this->request->post();
            Log::info('Form data: ' . json_encode($data));
            
            // 数据验证
            $validate = \think\facade\Validate::rule([
                'id' => 'require|integer|gt:0',
                'vendor' => 'max:50',
                'account_type' => 'require|max:50',
                'market_account_id' => 'require|max:20',
                'desc' => 'max:255',
                'balance' => 'float|egt:0',
                'available_balance' => 'float|egt:0',
                'frozen_balance' => 'float|egt:0',
                'status' => 'max:50'
            ])->message([
                'id.require' => 'ID不能为空',
                'id.integer' => 'ID必须是整数',
                'id.gt' => 'ID必须大于0',
                'vendor.max' => '渠道最多50个字符',
                'account_type.require' => '账号类型不能为空',
                'account_type.max' => '账号类型最多50个字符',
                'market_account_id.require' => '市场账号ID不能为空',
                'market_account_id.max' => '市场账号ID最多20个字符',
                'desc.max' => '描述最多255个字符',
                'balance.float' => '总资金余额必须是数字',
                'balance.egt' => '总资金余额不能小于0',
                'available_balance.float' => '可用资金必须是数字',
                'available_balance.egt' => '可用资金不能小于0',
                'frozen_balance.float' => '冻结资金必须是数字',
                'frozen_balance.egt' => '冻结资金不能小于0',
                'status.max' => '状态最多50个字符'
            ]);
            
            if (!$validate->check($data)) {
                $error = $validate->getError();
                Log::error('Validation error: ' . $error);
                return $this->error($error);
            }
            
            try {
                // 业务键 (vendor, market_account_id)；先在默认分片读原 row 拿原 key
                $originalRow = Db::name('accounts')->where('id', $data['id'])->find();
                if (empty($originalRow)) {
                    return $this->error('账号不存在或不在默认分片');
                }
                $origVendor = (string)$originalRow['vendor'];
                $origAcctId = (string)$originalRow['market_account_id'];
                // 仅当业务键真改了才跨片查重
                $newVendor = (string)($data['vendor'] ?? '');
                $newAcctId = (string)($data['market_account_id'] ?? '');
                if ($newVendor !== $origVendor || $newAcctId !== $origAcctId) {
                    $hits = ShardManager::existsAcrossShards('accounts', [
                        'vendor' => $newVendor,
                        'market_account_id' => $newAcctId,
                    ]);
                    if (!empty($hits)) {
                        return $this->error('新业务键 (vendor, market_account_id) 已存在于分片：' . json_encode($hits, JSON_UNESCAPED_UNICODE));
                    }
                }

                // 处理数据
                $updateData = [
                    'vendor' => isset($data['vendor']) ? $data['vendor'] : '',
                    'account_type' => isset($data['account_type']) ? $data['account_type'] : '',
                    'market_account_id' => $data['market_account_id'],
                    'desc' => isset($data['desc']) ? $data['desc'] : '',
                    'balance' => floatval($data['balance']),
                    'available_balance' => floatval($data['available_balance']),
                    'frozen_balance' => floatval($data['frozen_balance']),
                    'info' => isset($data['info']) ? $data['info'] : '',
                    'status' => isset($data['status']) ? $data['status'] : 'Normal',
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                $perShard = ShardManager::fanOutWriteInTx(function ($conn) use ($origVendor, $origAcctId, $updateData) {
                    return Db::connect($conn)->name('accounts')
                        ->where('vendor', $origVendor)
                        ->where('market_account_id', $origAcctId)
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
        
        $vo = Db::name('accounts')->where('id', $id)->find();
        if (empty($vo)) {
            return $this->error('账号不存在');
        }
        
        // 如果市场账号ID为空，尝试从info字段解析user_id或account_id
        if (empty($vo['market_account_id']) && !empty($vo['info'])) {
            $infoData = json_decode($vo['info'], true);
            if (is_array($infoData)) {
                if (isset($infoData['user_id']) && !empty($infoData['user_id'])) {
                    $vo['market_account_id'] = $infoData['user_id'];
                } elseif (isset($infoData['account_id']) && !empty($infoData['account_id'])) {
                    $vo['market_account_id'] = $infoData['account_id'];
                }
            }
        }
        
        $this->assign([
            'vo' => $vo,
            'vendors' => $this->getVendors(),
            'account_types' => $this->getAccountTypes(),
            'statuses' => $this->getStatuses()
        ]);
        return $this->fetch('form');
    }

    /**
     * 删除账号
     * @auth true
     */
    public function remove()
    {
        Log::info('Accounts remove method called');
        
        $id = $this->request->post('id');
        if (empty($id)) {
            return $this->error('参数错误');
        }
        
        try {
            $original = Db::name('accounts')->where('id', $id)->find();
            if (empty($original)) {
                return $this->error('账号不存在或不在默认分片');
            }
            $origVendor = (string)$original['vendor'];
            $origAcctId = (string)$original['market_account_id'];
            $perShard = ShardManager::fanOutWriteInTx(function ($conn) use ($origVendor, $origAcctId) {
                return Db::connect($conn)->name('accounts')
                    ->where('vendor', $origVendor)
                    ->where('market_account_id', $origAcctId)
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
     * 查看账号详情
     * @auth true
     */
    public function view()
    {
        Log::info('Accounts view method called');
        
        // 获取账号ID
        $id = $this->request->get('id');
        if (empty($id)) {
            return $this->error('参数错误');
        }
        
        $vo = Db::name('accounts')->where('id', $id)->find();
        if (empty($vo)) {
            return $this->error('账号不存在');
        }
        
        // 如果市场账号ID为空，尝试从info字段解析user_id或account_id
        if (empty($vo['market_account_id']) && !empty($vo['info'])) {
            $infoData = json_decode($vo['info'], true);
            if (is_array($infoData)) {
                if (isset($infoData['user_id']) && !empty($infoData['user_id'])) {
                    $vo['market_account_id'] = $infoData['user_id'];
                } elseif (isset($infoData['account_id']) && !empty($infoData['account_id'])) {
                    $vo['market_account_id'] = $infoData['account_id'];
                }
            }
        }
        
        // 格式化金额
        $vo['balance'] = number_format((float)$vo['balance'], 8, '.', '');
        $vo['available_balance'] = number_format((float)$vo['available_balance'], 8, '.', '');
        $vo['frozen_balance'] = number_format((float)$vo['frozen_balance'], 8, '.', '');
        
        // 格式化JSON
        if (!empty($vo['info'])) {
            $infoData = json_decode($vo['info'], true);
            if (is_array($infoData)) {
                $vo['info_formatted'] = json_encode($infoData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            } else {
                $vo['info_formatted'] = $vo['info'];
            }
        } else {
            $vo['info_formatted'] = '';
        }
        
        $this->assign(['vo' => $vo]);
        return $this->fetch('view');
    }

    /**
     * 获取渠道选项
     * @return array
     */
    private function getVendors()
    {
        return Enum::GetAllVendor();
    }

    /**
     * 获取账号类型选项
     * @return array
     */
    private function getAccountTypes()
    {
        return Enum::GetAllAccountType();
    }

    /**
     * 获取状态选项
     * @return array
     */
    private function getStatuses()
    {
        return [
            'Normal' => 'Normal',
            'Disabled' => 'Disabled',
            'Maintenance' => 'Maintenance'
        ];
    }
}
