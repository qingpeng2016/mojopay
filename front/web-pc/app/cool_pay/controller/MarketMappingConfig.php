<?php
declare (strict_types=1);

namespace app\cool_pay\controller;

use app\cool_pay\model\MarketMappingConfig as MarketMappingConfigModel;
use app\cool_pay\const\Enum;
use app\cool_pay\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;
use think\facade\Log;

/**
 * 市场关联配置管理
 */
class MarketMappingConfig extends Controller
{
    use SafeQueryTrait;
    /**
     * 市场关联列表
     * @auth true
     * @menu true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $this->title = '市场关联';

        // 记录日志
        $this->app->log->info('MarketMappingConfig index method called');

        // 获取请求参数
        $get = $this->request->get();
        $this->app->log->info('Request parameters: ' . json_encode($get));

        $query = Db::name('market_mapping_config');
        $this->app->log->info('Query object created');

        // 添加搜索条件
        if (isset($get['id']) && $get['id'] !== '') {
            $query->where('id', $get['id']);
            $this->app->log->info('Added id condition: ' . $get['id']);
        }
        if (isset($get['market_name']) && $get['market_name'] !== '') {
            $name = $this->sanitizeLike($get['market_name']);
            $query->where('market_name', 'like', "%{$name}%");
            $this->app->log->info('Added market_name condition: ' . $name);
        }
        if (isset($get['source_vendor']) && $get['source_vendor'] !== '') {
            $query->where('source_vendor', $get['source_vendor']);
            $this->app->log->info('Added source_vendor condition: ' . $get['source_vendor']);
        }
        if (isset($get['target_vendor']) && $get['target_vendor'] !== '') {
            $query->where('target_vendor', $get['target_vendor']);
            $this->app->log->info('Added target_vendor condition: ' . $get['target_vendor']);
        }
        if (isset($get['source_status']) && $get['source_status'] !== '') {
            $query->where('source_status', $get['source_status']);
            $this->app->log->info('Added source_status condition: ' . $get['source_status']);
        }
        if (isset($get['target_status']) && $get['target_status'] !== '') {
            $query->where('target_status', $get['target_status']);
            $this->app->log->info('Added target_status condition: ' . $get['target_status']);
        }
        if (isset($get['trade_account_ids']) && $get['trade_account_ids'] !== '') {
            $tradeAccounts = $this->sanitizeLike($get['trade_account_ids']);
            $query->where('trade_account_ids', 'like', "%{$tradeAccounts}%");
            $this->app->log->info('Added trade_account_ids condition: ' . $tradeAccounts);
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
            'path' => '/admin.html#/cool_pay/market_mapping_config/index.html', // 使用正确的路径
        ], false);
        $this->app->log->info('Pagination query executed, total: ' . $result->total());

        // 格式化数据
        $list = $result->items();

        foreach ($list as &$item) {
            // 处理NULL值，避免htmlentities错误
            $item['market_desc'] = $item['market_desc'] ?? '';
            $item['source_group_id'] = $item['source_group_id'] ?? '';
            $item['source_market_id'] = $item['source_market_id'] ?? '';
            $item['source_market_address'] = $item['source_market_address'] ?? '';
            $item['target_group_id'] = $item['target_group_id'] ?? '';
            $item['target_market_id'] = $item['target_market_id'] ?? '';
            $item['token_id_mapping'] = $item['token_id_mapping'] ?? '';
            $item['trade_account_ids'] = $item['trade_account_ids'] ?? '';
            $item['source_status'] = $item['source_status'] ?? '';
            $item['target_status'] = $item['target_status'] ?? '';
            $item['market_category'] = $item['market_category'] ?? '';
            $item['market_sub_category'] = $item['market_sub_category'] ?? '';
            
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
            'statuses' => $this->getStatuses()
        ]);
        $this->app->log->info('Variables assigned to view');

        // 渲染视图
        $this->app->log->info('Rendering view');
        return $this->fetch();
    }

    /**
     * 添加市场关联
     * @auth true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function add()
    {
        // 记录日志
        Log::info('MarketMappingConfig add method called');
        
        // 如果是POST请求，处理表单提交
        if ($this->request->isPost()) {
            // 记录日志
            Log::info('MarketMappingConfig add POST request received');
            
            // 获取表单数据
            $data = $this->request->post();
            Log::info('Form data: ' . json_encode($data));
            
            // 数据验证
            $validate = \think\facade\Validate::rule([
                'market_name' => 'require|max:50',
                'market_desc' => 'require|max:50',
                'source_vendor' => 'require|max:20',
                'source_account_id' => 'require|integer|gt:0',
                'target_vendor' => 'require|max:20',
                'target_account_id' => 'require|integer|gt:0',
                'trade_account_ids' => 'require|max:255',
                'source_status' => 'require|in:PENDING,ACTIVE,CLOSED,ARCHIVED',
                'target_status' => 'require|in:PENDING,ACTIVE,CLOSED,ARCHIVED',
                'token_id_mapping' => 'require'
            ])->message([
                'market_name.require' => '市场名称不能为空',
                'market_name.max' => '市场名称最多50个字符',
                'market_desc.require' => '市场描述不能为空',
                'market_desc.max' => '市场描述最多50个字符',
                'source_vendor.require' => '源渠道不能为空',
                'source_vendor.max' => '源渠道最多20个字符',
                'source_account_id.require' => '源账户ID不能为空',
                'source_account_id.integer' => '源账户ID必须是整数',
                'source_account_id.gt' => '源账户ID必须大于0',
                'target_vendor.require' => '目标渠道不能为空',
                'target_vendor.max' => '目标渠道最多20个字符',
                'target_account_id.require' => '目标账户ID不能为空',
                'target_account_id.integer' => '目标账户ID必须是整数',
                'target_account_id.gt' => '目标账户ID必须大于0',
                'trade_account_ids.require' => '交易账号ID不能为空，多个账号请使用 | 分割',
                'trade_account_ids.max' => '交易账号ID最多255个字符，多个账号请使用 | 分割',
                'source_status.require' => '源状态不能为空',
                'source_status.in' => '源状态必须是：PENDING、ACTIVE、CLOSED、ARCHIVED',
                'target_status.require' => '目标状态不能为空',
                'target_status.in' => '目标状态必须是：PENDING、ACTIVE、CLOSED、ARCHIVED',
                'token_id_mapping.require' => 'Token映射配置不能为空'
            ]);
            
            // 验证token_id_mapping格式
            if (isset($data['token_id_mapping']) && !empty($data['token_id_mapping'])) {
                $tokenMapping = json_decode($data['token_id_mapping'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->error('Token映射配置格式错误，必须是有效的JSON格式');
                }
                
                // 验证JSON结构
                if (!is_array($tokenMapping)) {
                    return $this->error('Token映射配置必须是数组格式');
                }
                
                foreach ($tokenMapping as $item) {
                    if (!isset($item['sourceTokenId']) || !isset($item['targetTokenId']) || !isset($item['type'])) {
                        return $this->error('Token映射配置格式错误，每个项目必须包含sourceTokenId、targetTokenId和type字段');
                    }
                }
            }
            
            // 验证数据
            if (!$validate->check($data)) {
                $error = $validate->getError();
                Log::error('Validation error: ' . $error);
                return $this->error($error);
            }
            
            try {
                // 处理数据类型转换
                $data['source_account_id'] = intval($data['source_account_id']);
                $data['target_account_id'] = intval($data['target_account_id']);
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['updated_at'] = date('Y-m-d H:i:s');
                $data['trade_account_ids'] = trim($data['trade_account_ids'] ?? '');

                try {
                    $validatedTradeAccounts = $this->validateTradeAccountIds($data['trade_account_ids']);
                    $data['trade_account_ids'] = implode('|', $validatedTradeAccounts);
                } catch (\Exception $e) {
                    Log::warning('Trade account validation failed on add: ' . $e->getMessage());
                    return json([
                        'code' => 0,
                        'info' => '交易账号校验失败：' . $e->getMessage(),
                        'data' => []
                    ]);
                }
                
                // 插入数据
                $result = Db::name('market_mapping_config')->insert($data);
                Log::info('Insert result: ' . ($result ? 'success' : 'failed'));
                
                if ($result) {
                    // 返回JSON响应
                    return json(['code' => 1, 'info' => '添加成功', 'url' => '']);
                } else {
                    return $this->error('添加失败');
                }
            } catch (\Exception $e) {
                Log::error('Exception in add method: ' . $e->getMessage());
                Log::error('Stack trace: ' . $e->getTraceAsString());
                return $this->error('添加失败: ' . $e->getMessage());
            }
        }
        
        // 分配变量到视图
        $this->assign([
            'vendors' => $this->getVendors(),
            'statuses' => $this->getStatuses(),
            'source_accounts' => [],
            'target_accounts' => []
        ]);
        
        // 渲染添加表单
        return $this->fetch('form');
    }

    /**
     * 编辑市场关联
     * @auth true
     */
    public function edit()
    {
        Log::info('MarketMappingConfig edit method called');
        
        if ($this->request->isPost()) {
            Log::info('MarketMappingConfig edit POST request received');
            
            // 获取表单数据
            $data = $this->request->post();
            Log::info('Form data: ' . json_encode($data));
            
            // 数据验证
            $validate = \think\facade\Validate::rule([
                'id' => 'require|integer|gt:0',
                'market_name' => 'require|max:50',
                'market_desc' => 'require|max:50',
                'source_vendor' => 'require|max:20',
                'source_account_id' => 'require|integer|gt:0',
                'target_vendor' => 'require|max:20',
                'target_account_id' => 'require|integer|gt:0',
                'trade_account_ids' => 'require|max:255',
                'source_status' => 'require|in:PENDING,ACTIVE,CLOSED,ARCHIVED',
                'target_status' => 'require|in:PENDING,ACTIVE,CLOSED,ARCHIVED',
                'token_id_mapping' => 'require'
            ])->message([
                'id.require' => 'ID不能为空',
                'id.integer' => 'ID必须是整数',
                'id.gt' => 'ID必须大于0',
                'market_name.require' => '市场名称不能为空',
                'market_name.max' => '市场名称最多50个字符',
                'market_desc.require' => '市场描述不能为空',
                'market_desc.max' => '市场描述最多50个字符',
                'source_vendor.require' => '源渠道不能为空',
                'source_vendor.max' => '源渠道最多20个字符',
                'source_account_id.require' => '源账户ID不能为空',
                'source_account_id.integer' => '源账户ID必须是整数',
                'source_account_id.gt' => '源账户ID必须大于0',
                'target_vendor.require' => '目标渠道不能为空',
                'target_vendor.max' => '目标渠道最多20个字符',
                'target_account_id.require' => '目标账户ID不能为空',
                'target_account_id.integer' => '目标账户ID必须是整数',
                'target_account_id.gt' => '目标账户ID必须大于0',
                'trade_account_ids.require' => '交易账号ID不能为空，多个账号请使用 | 分割',
                'trade_account_ids.max' => '交易账号ID最多255个字符，多个账号请使用 | 分割',
                'source_status.require' => '源状态不能为空',
                'source_status.in' => '源状态必须是：PENDING、ACTIVE、CLOSED、ARCHIVED',
                'target_status.require' => '目标状态不能为空',
                'target_status.in' => '目标状态必须是：PENDING、ACTIVE、CLOSED、ARCHIVED',
                'token_id_mapping.require' => 'Token映射配置不能为空'
            ]);
            
            // 验证token_id_mapping格式
            if (isset($data['token_id_mapping']) && !empty($data['token_id_mapping'])) {
                $tokenMapping = json_decode($data['token_id_mapping'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->error('Token映射配置格式错误，必须是有效的JSON格式');
                }
                
                if (!is_array($tokenMapping)) {
                    return $this->error('Token映射配置必须是数组格式');
                }
                
                foreach ($tokenMapping as $item) {
                    if (!isset($item['sourceTokenId']) || !isset($item['targetTokenId']) || !isset($item['type'])) {
                        return $this->error('Token映射配置格式错误，每个项目必须包含sourceTokenId、targetTokenId和type字段');
                    }
                }
            }
            
            if (!$validate->check($data)) {
                $error = $validate->getError();
                Log::error('Validation error: ' . $error);
                return $this->error($error);
            }
            
            try {
                // 处理数据
                $updateData = [
                    'market_name' => $data['market_name'],
                    'market_desc' => $data['market_desc'],
                    'source_vendor' => $data['source_vendor'],
                    'source_account_id' => intval($data['source_account_id']),
                    'source_group_id' => $data['source_group_id'] ?? '',
                    'source_market_id' => $data['source_market_id'] ?? '',
                    'source_market_address' => $data['source_market_address'] ?? '',
                    'target_vendor' => $data['target_vendor'],
                    'target_account_id' => intval($data['target_account_id']),
                    'target_group_id' => $data['target_group_id'] ?? '',
                    'target_market_id' => $data['target_market_id'] ?? '',
                    'token_id_mapping' => $data['token_id_mapping'],
                    'source_status' => $data['source_status'],
                    'target_status' => $data['target_status'],
                    'trade_account_ids' => trim($data['trade_account_ids'] ?? ''),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                try {
                    $validatedTradeAccounts = $this->validateTradeAccountIds($updateData['trade_account_ids']);
                    $updateData['trade_account_ids'] = implode('|', $validatedTradeAccounts);
                } catch (\Exception $e) {
                    Log::warning('Trade account validation failed on edit: ' . $e->getMessage());
                    return json([
                        'code' => 0,
                        'info' => '交易账号校验失败：' . $e->getMessage(),
                        'data' => []
                    ]);
                }
                
                // 更新数据
                $result = Db::name('market_mapping_config')
                    ->where('id', $data['id'])
                    ->update($updateData);
                Log::info('Update result: ' . ($result !== false ? 'success' : 'failed'));
                
                if ($result !== false) {
                    return json(['code' => 1, 'info' => '更新成功', 'url' => '']);
                } else {
                    return $this->error('更新失败或市场关联不存在');
                }
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
        
        $vo = Db::name('market_mapping_config')->where('id', $id)->find();
        if (empty($vo)) {
            return $this->error('市场关联不存在');
        }
        
        // 获取源渠道和目标渠道的账户列表
        $source_accounts = [];
        $target_accounts = [];
        
        if (!empty($vo['source_vendor'])) {
            $source_accounts = Db::name('accounts')
                ->where('vendor', $vo['source_vendor'])
                ->where('status', 'Normal')
                ->field('id, market_account_id, desc, vendor, account_type')
                ->order('id desc')
                ->select()
                ->toArray();
        }
        
        if (!empty($vo['target_vendor'])) {
            $target_accounts = Db::name('accounts')
                ->where('vendor', $vo['target_vendor'])
                ->where('status', 'Normal')
                ->field('id, market_account_id, desc, vendor, account_type')
                ->order('id desc')
                ->select()
                ->toArray();
        }
        
        $this->assign([
            'vo' => $vo,
            'vendors' => $this->getVendors(),
            'statuses' => $this->getStatuses(),
            'source_accounts' => $source_accounts,
            'target_accounts' => $target_accounts
        ]);
        return $this->fetch('form');
    }

    /**
     * 删除市场关联
     * @auth true
     */
    public function remove()
    {
        Log::info('MarketMappingConfig remove method called');
        
        $id = $this->request->post('id');
        if (empty($id)) {
            return $this->error('参数错误');
        }
        
        try {
            $result = Db::name('market_mapping_config')->where('id', $id)->delete();
            Log::info('Delete result: ' . ($result ? 'success' : 'failed'));
            
            if ($result) {
                return json(['code' => 1, 'info' => '删除成功']);
            } else {
                return $this->error('删除失败或市场关联不存在');
            }
        } catch (\Exception $e) {
            Log::error('Exception in remove method: ' . $e->getMessage());
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取指定渠道的账户列表（AJAX接口）
     * @auth false
     */
    public function getAccountsByVendor()
    {
        $vendor = $this->request->get('vendor', '');
        
        if (empty($vendor)) {
            return json(['code' => 0, 'msg' => '渠道参数不能为空', 'data' => []]);
        }
        
        try {
            $accounts = Db::name('accounts')
                ->where('vendor', $vendor)
                ->where('account_type', Enum::ACCOUNT_TYPE_TRADE_FOREGATE)
                ->where('status', 'Normal')
                ->field('id, market_account_id, desc, vendor, account_type')
                ->order('id desc')
                ->select()
                ->toArray();
            
            return json(['code' => 1, 'msg' => '获取成功', 'data' => $accounts]);
        } catch (\Exception $e) {
            Log::error('Exception in getAccountsByVendor: ' . $e->getMessage());
            return json(['code' => 0, 'msg' => '获取失败: ' . $e->getMessage(), 'data' => []]);
        }
    }

    /**
     * 获取渠道选项
     * @return array
     */
    private function getVendors()
    {
        $vendors = Enum::GetAllVendor();
        $result = [];
        foreach ($vendors as $vendor) {
            $result[$vendor] = $vendor;
        }
        return $result;
    }

    /**
     * 获取状态选项
     * @return array
     */
    private function getStatuses()
    {
        return [
            'PENDING' => 'PENDING',
            'ACTIVE' => 'ACTIVE',
            'CLOSED' => 'CLOSED',
            'ARCHIVED' => 'ARCHIVED'
        ];
    }

    /**
     * 校验交易账号ID是否存在
     * @param string $tradeAccountIds
     * @return array
     * @throws \Exception
     */
    private function validateTradeAccountIds(string $tradeAccountIds): array
    {
        $ids = array_filter(array_map(static function ($value) {
            return trim($value);
        }, explode('|', $tradeAccountIds)), static function ($value) {
            return $value !== '';
        });

        if (empty($ids)) {
            throw new \Exception('交易账号ID不能为空，多个账号请使用 | 分割');
        }

        $uniqueIds = array_values(array_unique($ids));
        foreach ($uniqueIds as $id) {
            if (!ctype_digit($id)) {
                throw new \Exception('交易账号ID必须是正整数，多个账号请使用 | 分割');
            }
        }

        $accountIds = Db::name('accounts')
            ->whereIn('id', $uniqueIds)
            ->where('account_type', Enum::ACCOUNT_TYPE_TRADE_FOREGATE)
            ->column('id');

        $missingIds = array_diff($uniqueIds, array_map('strval', $accountIds));
        if (!empty($missingIds)) {
            throw new \Exception('以下交易账号ID不存在：' . implode(', ', $missingIds));
        }

        return $uniqueIds;
    }
}
