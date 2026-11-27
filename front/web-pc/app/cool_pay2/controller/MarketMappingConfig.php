<?php
declare (strict_types=1);

namespace app\cool_pay2\controller;

use app\cool_pay2\model\MarketMappingConfig as MarketMappingConfigModel;
use app\cool_pay2\const\Enum;
use app\cool_pay2\service\ShardManager;
use app\cool_pay2\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;
use think\facade\Log;
use think\facade\Console;
use think\Paginator;

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
        $get = $this->request->get();
        $page = max(1, intval($get['page'] ?? 1));
        $pageSize = max(1, intval($get['pageSize'] ?? 20));

        $perShard = ShardManager::fanOutRead(function (string $conn) use ($get, $page, $pageSize) {
            $q = Db::connect($conn)->name('market_mapping_config');
            $this->applyIndexFilters($q, $get);
            $count = (int)(clone $q)->count();
            $rows = (clone $q)->order('updated_at', 'desc')->order('id', 'desc')
                              ->limit($page * $pageSize)->select()->toArray();
            return ['count' => $count, 'rows' => $rows];
        });

        $totalCount = 0;
        $merged = [];
        foreach ($perShard as $sid => $s) {
            $totalCount += $s['count'];
            foreach ($s['rows'] as $row) {
                // 加 __shard_id 让 view/edit 链路能追溯到具体分片
                $row['__shard_id'] = $sid;
                $merged[] = $row;
            }
        }
        usort($merged, static function ($a, $b) {
            $cmp = strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
            if ($cmp !== 0) return $cmp;
            return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
        });
        $pageRows = array_slice($merged, ($page - 1) * $pageSize, $pageSize);

        $result = Paginator::make($pageRows, $pageSize, $page, $totalCount, false, [
            'query' => $get,
            'path'  => '/admin.html#/cool_pay2/market_mapping_config/index.html',
        ]);
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
    // Step 1. Log entry
    Log::info('MarketMappingConfig add method initiated');


    if ($this->request->isPost()) {
        // Step 2. Get form data (Only the 4 fields)
        $data = $this->request->post();
        Log::info('Step 1: Form data received: ' . json_encode($data));

        // Step 3. Form data validate (The 4 user inputs)
        $rules = [
            'polymarket_url' => 'require|url|max:255',
            'fg_market_id'   => 'require|integer|gt:0',
            'polymarket_market_email_account' => 'require|email|max:100',
            'relation' => 'string|max:1000',
            'is_hot_market'  => 'require|in:0,1',
            'market_category' => 'in:Sports,Politics,Crypto,Finance,Geopolitics,Earnings,Tech,Culture,World,Economy,Climate & Science,Elections,Mentions',
            'market_sub_category' => 'max:100',
        ];

        $messages = [
            'polymarket_url.url'      => '请输入有效的 Polymarket 网址',
            'fg_market_id.integer'    => 'Foregate 市场 ID 必须是数字',
            'market_category.require' => '请选择市场分类',
            'market_category.in'      => '无效的市场分类选择',
            'market_sub_category.require' => '请输入或选择子分类',
        ];

        $validate = \think\facade\Validate::rule($rules)->message($messages);

        if (!$validate->check($data)) {
            // code 0 usually triggers the error highlight in ThinkAdmin/Layui
            return json(['code' => 0, 'info' => '输入验证失败: ' . $validate->getError()]);
        }

        Log::info('Step 3: Form data validated successfully');

        try {
            // main execution block for data processing in sports routes
            if (!empty($data['relation']) && strpos($data['relation'], '==') !== false) { 
                //$rawRelation = $data['relation'];
                //$conditionId = strstr($rawRelation, '==', true);
                //Log::info('Extracted ConditionID: ' . $conditionId);
                Log::info(' proceeding with sports-specific processing'); 
                $this->processSportsMarket($data);

                // --- THE CRITICAL FIX START ---
                // Return success JSON so the frontend knows to stay still or close the layer
                return json([
                    'code' => 1, 
                    'info' => '数据处理成功！', 
                    'data' => []
                ]);
            // --- THE CRITICAL FIX END ---
            } else { 
                //for non-sports markets   //no ralation inputs
                Log::info(' skipping sports-specific processing');
                //
                $url=$data['polymarket_url'];
                $marketData=$this->fetchPolyUrl($url);
                if (empty($marketData) || !is_array($marketData) || count($marketData) == 0) {
                    Log::error('Failed to fetch market data from fetchPolyUrl: ' . json_encode($marketData));
                    return json(['code' => 0, 'info' => 'Polymarket 链接解析失败: ' . json_encode($marketData)]);
                }
                Log::info('Market info fetched from fetchPolyUrl: ' . json_encode($marketData));

                // call outcome login command
                $loginData = $this->outcomesFgLogin($data['fg_market_id']);
                if ($loginData['status'] !== 'success') {
                    $this->error('Foregate account sync failed: ' . json_encode($loginData));
                    return json(['code' => 0, 'info' => 'Foregate 账户同步失败: ' . json_encode($loginData)]);
                }

                // Accessing fields correctly
                if (isset($loginData['status']) && $loginData['status'] === 'success') {
                    $accountInfo = $loginData['market_mapping_preview'];
                    $outcomes = $loginData['outcomes'];
                    
                    Log::info('Outcome login info fetched successfully for TargetGroupId: ' . $accountInfo['TargetGroupId']);
                    Log::info('First Outcome Normal User: ' . $outcomes[0]['normal_user_id']);
                } else {
                    Log::error('Login failed: ' . json_encode($loginData));
                }

                // main execution block
                if (count($marketData) == 1 && count($outcomes) == 1) {      //one record match
                    Log::info('Single market match found, proceeding with processing');
                    $this->processMarket($data);

                } else {     //multi records match
                    Log::info('Multiple market matches found, proceeding with multi-match logic');
                    $this->processMultiMarkets($data);
                }
                // --- THE CRITICAL FIX ---
                return json([
                    'code' => 1, 
                    'info' => '数据处理成功！', 
                    'data' => []
                ]);
            }            

        } catch (\Exception $e) {
            Log::error('Processing failed: ' . $e->getMessage());
            return json(['code' => 0, 'info' => '处理失败: ' . $e->getMessage()]);
        }
    }  

    // This will now automatically look for add.html
     return $this->fetch(); 
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
                
                // 路由到该市场所在分片再 update。优先级：
                //   1. 表单带 __shard_id（list view 注入）→ 直接用，最准
                //   2. target_group_id 在 sys_market_sharding_index → 用索引路由
                //   3. fallback: 按 id 跨片搜（id 跨片不唯一，撞片风险，仅老链接兜底）
                $explicitShardId = isset($data['__shard_id']) && (int)$data['__shard_id'] > 0
                    ? (int)$data['__shard_id'] : null;
                if ($explicitShardId !== null) {
                    $route = ['conn' => ShardManager::conn($explicitShardId), 'shard_id' => $explicitShardId];
                } else {
                    $route = $this->routeToRowShard($data['target_group_id'] ?? null, (int)$data['id']);
                }
                if ($route === null) {
                    return $this->error('找不到该市场所在分片');
                }
                $result = Db::connect($route['conn'])->name('market_mapping_config')
                    ->where('id', $data['id'])
                    ->update($updateData);
                Log::info("Update on shard {$route['shard_id']} result: " . ($result !== false ? 'success' : 'failed'));

                if ($result !== false) {
                    return json(['code' => 1, 'info' => '更新成功（分片 ' . $route['shard_id'] . '）', 'url' => '']);
                }
                return $this->error('更新失败或市场关联不存在');
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
        
        // 显式带 __shard_id 时直接路由（最稳）；没带就 fan-out 兜底
        $explicitShardId = (int)$this->request->get('__shard_id', 0);
        if ($explicitShardId > 0) {
            $row = Db::connect(ShardManager::conn($explicitShardId))->name('market_mapping_config')
                ->where('id', $id)->find();
            if (empty($row)) {
                return $this->error('市场关联不存在（指定分片 ' . $explicitShardId . '）');
            }
            $row['__shard_id'] = $explicitShardId;
            $vo = $row;
        } else {
            $found = $this->findRowAcrossShards((int)$id);
            if ($found === null) {
                return $this->error('市场关联不存在');
            }
            $vo = $found['row'];
            $vo['__shard_id'] = $found['shard_id'];
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
        // This will now automatically look for edit.html
        return $this->fetch();
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
            // 优先级：__shard_id (显式) > target_group_id (索引路由) > id fan-out 兜底
            $explicitShardId = (int)$this->request->post('__shard_id', 0);
            if ($explicitShardId > 0) {
                $route = ['conn' => ShardManager::conn($explicitShardId), 'shard_id' => $explicitShardId];
            } else {
                $tgid = $this->request->post('target_group_id', '');
                $route = $this->routeToRowShard($tgid !== '' ? $tgid : null, (int)$id);
            }
            if ($route === null) {
                return $this->error('找不到该市场所在分片');
            }
            $result = Db::connect($route['conn'])->name('market_mapping_config')->where('id', $id)->delete();
            Log::info("Delete on shard {$route['shard_id']} result: " . ($result ? 'success' : 'failed'));

            if ($result) {
                return json(['code' => 1, 'info' => '删除成功（分片 ' . $route['shard_id'] . '）']);
            }
            return $this->error('删除失败或市场关联不存在');
        } catch (\Exception $e) {
            Log::error('Exception in remove method: ' . $e->getMessage());
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 跨片找一条 market_mapping_config 行所在分片（按 id）
     * 注意：id 在每个分片是本地自增，可能多片都有该 id；以 fan-out 命中的第一片为准
     *
     * @return array{shard_id:int, row:array}|null
     */
    private function findRowAcrossShards(int $id): ?array
    {
        foreach (ShardManager::getActiveShards() as $sid => $info) {
            $row = Db::connect(ShardManager::conn($sid))->name('market_mapping_config')
                ->where('id', $id)->find();
            if ($row) {
                return ['shard_id' => $sid, 'row' => $row];
            }
        }
        return null;
    }

    /**
     * 决定 INSERT 一条新的 market_mapping_config 到哪个分片：
     *   1. target_group_id 在 sys_market_sharding_index 里（runtime OMS 已注册）→ 用那一片
     *   2. 没有 → 兜底到第一个 status=1 分片（admin 手动新增市场场景，runtime 后续会通过
     *      condition_id 强制锁定，写错片会被 condition_id 主键约束兜住）
     */
    private function resolveInsertShardId(string $targetGroupId): int
    {
        if ($targetGroupId !== '') {
            $sid = ShardManager::getShardIdForMarket($targetGroupId);
            if ($sid !== null) {
                return $sid;
            }
        }
        $shards = ShardManager::getActiveShards();
        $fallback = (int)array_key_first($shards);
        Log::warning("MarketMappingConfig: target_group_id={$targetGroupId} 未在 sys_market_sharding_index 注册，兜底到分片 {$fallback}");
        return $fallback;
    }

    /**
     * 路由到指定行所在分片：优先用 target_group_id（全局唯一），否则按 id fan-out 兜底
     *
     * @return array{conn:string, shard_id:int}|null
     */
    private function routeToRowShard(?string $targetGroupId, ?int $id): ?array
    {
        if ($targetGroupId !== null && $targetGroupId !== '') {
            $sid = ShardManager::getShardIdForMarket($targetGroupId);
            if ($sid !== null) {
                return ['conn' => ShardManager::conn($sid), 'shard_id' => $sid];
            }
        }
        if ($id !== null && $id > 0) {
            $found = $this->findRowAcrossShards($id);
            if ($found !== null) {
                return ['conn' => ShardManager::conn($found['shard_id']), 'shard_id' => $found['shard_id']];
            }
        }
        return null;
    }

    /**
     * 把 index() 的 GET 筛选条件 apply 到 query 上（跨片闭包内用）
     */
    private function applyIndexFilters($query, array $get): void
    {
        if (!empty($get['target_group_id'])) {
            $query->where('target_group_id', $get['target_group_id']);
        }
        if (!empty($get['market_name'])) {
            $name = $this->sanitizeLike($get['market_name']);
            $query->where('market_name', 'like', "%{$name}%");
        }
        if (!empty($get['source_vendor'])) {
            $query->where('source_vendor', $get['source_vendor']);
        }
        if (!empty($get['target_vendor'])) {
            $query->where('target_vendor', $get['target_vendor']);
        }
        if (!empty($get['source_status'])) {
            $query->where('source_status', $get['source_status']);
        }
        if (!empty($get['target_status'])) {
            $query->where('target_status', $get['target_status']);
        }
        if (!empty($get['trade_account_ids'])) {
            $tradeAccounts = $this->sanitizeLike($get['trade_account_ids']);
            $query->where('trade_account_ids', 'like', "%{$tradeAccounts}%");
        }
        if (!empty($get['date_range']) && ($dates = $this->sanitizeDateRange((string)$get['date_range']))) {
            [$start_date, $end_date] = $dates;
            $query->whereBetween('created_at', ["{$start_date} 00:00:00", "{$end_date} 23:59:59"]);
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

    /**
     * Resolves Polymarket URL and maps data to internal fields
     * @param string $url
     * @return array
     */
    private function fetchPolyUrl(string $url)
    {
        if (empty($url)) {
            $url = "https://polymarket.com/sports/nba/games/week/3/nba-lal-lac-2026-01-22";
        }

        try {
            $payload = ['command' => 'resolve_url', 'url' => $url];
            $jsonData = base64_encode(json_encode($payload));

            $output = Console::call('resolve:market', [$jsonData]);
            $rawResponse = trim($output->fetch());

            if (empty($rawResponse)) {
                Log::error("Empty response from resolve:market for URL: " . $url);
                return []; // Return empty array on failure
            }

            // 1. IMPROVED BOUNDARY DETECTION
            $firstBracket = strpos($rawResponse, '[');
            $firstBrace = strpos($rawResponse, '{');
            
            if ($firstBracket === false && $firstBrace === false) {
                Log::error("No JSON found in response");
                return [];
            }

            $startPos = ($firstBracket !== false && ($firstBrace === false || $firstBracket < $firstBrace)) ? $firstBracket : $firstBrace;
            $endPos = (substr($rawResponse, $startPos, 1) === '[') ? strrpos($rawResponse, ']') : strrpos($rawResponse, '}');

            $jsonStr = substr($rawResponse, $startPos, $endPos - $startPos + 1);
            $decoded = json_decode($jsonStr, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("JSON Decode Error: " . json_last_error_msg());
                return [];
            }

            // 2. NORMALIZE TO ARRAY
            $items = (isset($decoded[0]) && is_array($decoded)) ? $decoded : [$decoded];
            $formattedList = [];

            foreach ($items as $result) {
                // Check for market_name (the identifier in your log)
                if (!isset($result['market_name']) || empty($result['market_name'])) continue;

                // Helper to parse strings that contain JSON arrays (Double Encoding)
                $parseJsonField = function($field) {
                    if (is_array($field)) return $field;
                    if (is_string($field)) {
                        $res = json_decode($field, true);
                        return is_array($res) ? $res : [];
                    }
                    return [];
                };

                $formattedList[] = [
                    'market_name'           => (string)$result['market_name'],
                    'source_market_id'      => (string)($result['source_market_id'] ?? ''),
                    'source_market_address' => (string)($result['source_market_address'] ?? ''),
                    'source_status'         => (string)($result['source_status'] ?? 'PENDING'),
                    'description'           => (string)($result['description'] ?? ''),
                    'clob_token_ids'        => $parseJsonField($result['clob_token_ids'] ?? []),
                    'outcomes'              => $parseJsonField($result['outcomes'] ?? []),
                    'source_start_time'     => $result['source_start_time'] ?? null,
                    'source_end_time'       => $result['source_end_time'] ?? null,
                    'negRisk'               => filter_var($result['negRisk'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'group_item_title'      => $result['groupItemTitle'] ?? '',
                ];
            }

            Log::info("Successfully formatted " . count($formattedList) . " items");
            return $formattedList; // Return the ARRAY directly

        } catch (\Exception $e) {
            Log::error("Exception in fetchPolyUrl: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Resolves Polymarket Sports URL and maps data to internal fields
     * @param string $conditionID
     * @return \think\response\Json
     */
    private function fetchPolySportsUrl(string $conditionID)
{
    if (empty($conditionID)) {
        $conditionID = "0x435dbb7bde05ec37c4b419bbeeeae3c3353faaacb03ecf0e9515a6c477f48fb7";
    }

    try {
        $payload = ['command' => 'resolve_sports_url', 'conditionID' => $conditionID];
        $jsonData = base64_encode(json_encode($payload));             
        
        // Call the ThinkPHP Console command
        $output = Console::call('resolve:sports', [$jsonData]);
        $rawResponse = trim($output->fetch());

        if (empty($rawResponse)) {
            Log::error("Empty response from resolve:sports for ConditionID: " . $conditionID);
            return json(['code' => 404, 'msg' => '无法解析：脚本未返回数据']);
        }

        // --- FIX 1: Support both List [] and Object {} boundaries ---
        // Since Python returns a list [ {...} ], we must look for the outer-most brackets
        $start = strpos($rawResponse, '[');
        $end = strrpos($rawResponse, ']');
        
        // Fallback: If it's an error object instead of a list
        if ($start === false) {
            $start = strpos($rawResponse, '{');
            $end = strrpos($rawResponse, '}');
        }

        if ($start === false || $end === false) {
            Log::error("Malformed response from script: " . $rawResponse);
            return json(['code' => 400, 'msg' => '解析失败：返回格式无效', 'raw' => $rawResponse]);
        }

        $cleanJson = substr($rawResponse, $start, $end - $start + 1);
        $result = json_decode($cleanJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("JSON Decode Error: " . json_last_error_msg() . " | Raw: " . $cleanJson);
            return json(['code' => 500, 'msg' => '数据格式错误：无法解析 JSON']);
        }

        // --- FIX 2: Handle Error objects returned by Python ---
        if (isset($result['status']) && $result['status'] === 'error') {
            return json(['code' => 404, 'msg' => 'Polymarket 错误: ' . ($result['message'] ?? '未知错误')]);
        }

        // --- FIX 3: Process the List and format it ---
        if (is_array($result) && !empty($result)) {
            // Note: Since you likely want to return one market for this specific conditionID query:
            $market = $result[0]; 

            // Clean up clob_token_ids (ensure it is a real array)
            $clobTokenIds = $market['clob_token_ids'] ?? [];
            if (is_string($clobTokenIds)) {
                $clobTokenIds = json_decode($clobTokenIds, true) ?: [];
            }

            // Clean up outcomes
            $outcomes = $market['outcomes'] ?? [];
            if (is_string($outcomes)) {
                $outcomes = json_decode($outcomes, true) ?: [];
            }

            $formattedData = [
                'market_name'           => $market['market_name'] ?? 'Unknown Market',
                'source_market_id'      => $market['source_market_id'] ?? '',
                'source_market_address' => $market['source_market_address'] ?? '',
                'source_status'         => $market['source_status'] ?? 'OPEN',
                'description'           => $market['description'] ?? '',
                'clob_token_ids'        => $clobTokenIds,
                'outcomes'              => $outcomes,
                'source_start_time'     => $market['source_start_time'] ?? null,
                'negRisk'               => $market['negRisk'] ?? false,
            ];
    
            return json([
                'code' => 200,
                'msg'  => 'Success',
                'data' => $formattedData
            ]);
        } else {
            return json(['code' => 404, 'msg' => '未找到市场信息，请核对 ConditionID']);
        }

    } catch (\Exception $e) {
        Log::error("Exception in fetchPolyUrl: " . $e->getMessage());
        return json(['code' => 500, 'msg' => '系统异常: ' . $e->getMessage()]);
    }
}
    /**
     * Login Foregate using the provided FgMarketId and retrieves account info
     * @param int|string $FgMarketId
     * @return \think\response\Json
     */
    private function outcomeFgLogin(string $FgMarketId)
{
    Log::info("Step 5: Starting outcomeFgLogin for ID: " . $FgMarketId);

    $payload = ['FgMarketId' => $FgMarketId];
    $jsonData = base64_encode(json_encode($payload));
 
    try {
        $output = Console::call('outcome:login', [$jsonData]);
        $rawResponse = trim($output->fetch()); 
        
        Log::info("Python Raw Output: " . $rawResponse);

        if (empty($rawResponse)) {
            Log::error("Step 5 Fail: Python returned no data");
            return json(['code' => 500, 'msg' => 'Python returned no data']);
        }

        // --- THE CRITICAL FIX: Extract only the JSON part ---
        $start = strpos($rawResponse, '{');
        $end = strrpos($rawResponse, '}');

        if ($start === false || $end === false) {
            Log::error("Step 5 JSON Error: No JSON boundaries found in output. Raw: " . $rawResponse);
            return json(['code' => 500, 'msg' => 'Python output contained no valid JSON']);
        }

        $cleanJson = substr($rawResponse, $start, $end - $start + 1);
        $result = json_decode($cleanJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("Step 5 JSON Error: " . json_last_error_msg() . " | Cleaned Content: " . $cleanJson);
            return json([
                'code' => 500, 
                'msg'  => 'JSON Syntax Error: ' . json_last_error_msg(),
                'raw_cleaned' => $cleanJson 
            ]);
        }

        // Check if Python returned an error status within the JSON
        if (isset($result['status']) && $result['status'] === 'error') {
            Log::warning("Step 5 Business Logic Fail: " . ($result['message'] ?? 'Unknown Error'));
            return json(['code' => 400, 'msg' => $result['message'] ?? 'Pipeline error']);
        }

        // SUCCESS CHECK
        if (isset($result['market_mapping_preview']) || (isset($result['status']) && $result['status'] === 'success')) {
            Log::info("Step 5 Success");
            return json(['code' => 200, 'data' => $result]);
        }

        return json(['code' => 404, 'msg' => 'Unexpected response format']);

    } catch (\Exception $e) {
        Log::error("Step 5 Critical Exception: " . $e->getMessage());
        return json(['code' => 500, 'msg' => 'Command execution crashed']);
    }
}

    /**
     * Login Foregate using the provided FgMarketId and retrieves outcomes and account info
     * @param int|string $FgMarketId
     * @return \think\response\Json
     */
    private function outcomesFgLogin(string $FgMarketId)
    {
        Log::info("Step 5: Starting outcomeFgLogin for ID: " . $FgMarketId);

        $payload = ['FgMarketId' => $FgMarketId];
        $jsonData = base64_encode(json_encode($payload));
    
        try {
            $output = Console::call('outcomes:login', [$jsonData]);
            $rawResponse = trim($output->fetch()); 
            
            if (empty($rawResponse)) {
                Log::error("Step 5 Fail: Python returned no data");
                return ['status' => 'error', 'msg' => 'Python returned no data'];
            }

            // Extract JSON portion (ignores Python logger timestamps/info)
            $start = strpos($rawResponse, '{');
            $end = strrpos($rawResponse, '}');

            if ($start === false || $end === false) {
                return ['status' => 'error', 'msg' => 'No valid JSON found'];
            }

            $cleanJson = substr($rawResponse, $start, $end - $start + 1);
            $result = json_decode($cleanJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['status' => 'error', 'msg' => 'JSON parse error'];
            }

            // Return the raw data array
            return $result;

        } catch (\Exception $e) {
            Log::error("Step 5 Critical Exception: " . $e->getMessage());
            return ['status' => 'error', 'msg' => $e->getMessage()];
        }
    }



    /**
     * Check email account in the accounts table
     * @param string $email
     * @return \think\response\Json
     */
    private function checkEmailAccount(string $email): int
    {
        if (empty($email)) {
            Log::info('Step 6: Email field is empty in the request.');
            return $this->error('Email account check failed: Field is empty.');
        }

        // Log the start of the lookup
        Log::info('Step 6: Starting account lookup', [
            'search_email' => $email,
            'vendor'       => 'polymarket'
        ]);

        try {
            // Logic: if accounts.market_account_id == user_input_email
            $sourceAccount = Db::name('accounts')
                ->where('market_account_id', $email)
                ->where('vendor', 'polymarket') // Matching the column name 'vendor' from your schema
                ->find();

            if (!$sourceAccount) {
                Log::info('Step 6 Fail: No record found in "accounts" table for email: ' . $email);
                return $this->error('系统中未找到该 Polymarket 账户 (Email: ' . $email . ')，请先在账户管理中添加。');
            }

            // Capture the ID
            $sourceAccountID = $sourceAccount['id'];
            
            // Log the success and the specific record details found
            Log::info('Step 6 Success: Source Account ID retrieved', [
                'internal_id'       => $sourceAccountID,
                'account_type'      => $sourceAccount['account_type'] ?? 'unknown',
                'status'            => $sourceAccount['status'] ?? 'unknown'
            ]);

        } catch (\Exception $e) {
            Log::info('Step 6 Exception: Database query error: ' . $e->getMessage());
            return $this->error('数据库查询异常: ' . $e->getMessage());
        }
        return $sourceAccountID;
    }

    /**
     * Process normal/volume account types
     * @param string $normal_user_id
     * @param array $volume_user_ids
     * @return array
     */
    private function processAccountType($normal_user_id, $volume_user_ids): array
    {    
        if (!empty($normal_user_id)) {
            $targetAccount = Db::name('accounts')
                ->where('vendor', 'FOREGATE')
                ->where('account_type', 'MARKET_MAKING_FOREGATE')
                ->where('market_account_id', $normal_user_id)
                ->find();
            if ($targetAccount) {
                $target_account_id = $targetAccount['id'];
            } else {
                Log::error('No matching Foregate account found for normal_user_id: ' . $normal_user_id);
                return $this->error('No matching Foregate account found for the provided normal user ID.');
            }
        } else {
            Log::error('normal_user_id is empty in accountInfo');
            return $this->error('normal_user_id is missing from Foregate login data.');
        }
        $tradeAccountIDs = [];
        if (!empty($volume_user_ids) && is_array($volume_user_ids)) {
            foreach ($volume_user_ids as $volumeUserId) {
                $tradeAccount = Db::name('accounts')
                    ->where('vendor', 'FOREGATE')
                    ->where('account_type', 'TRADE_FOREGATE')
                    ->where('market_account_id', $volumeUserId)
                    ->find();
                if ($tradeAccount) {
                    $tradeAccountIDs[]=$tradeAccount['id'];
                } else {
                    Log::warning('No matching Foregate trade account found for volume_user_id: ' . $volumeUserId);
                }
            }
        }
        return [$target_account_id, $tradeAccountIDs];
    }

    /**
     * Construct token_id_mapping JSON
     * @param array $sourceTokens
     * @param array $sourceOutcomes
     * @param array $targetOptions
     * @return string
     */
    private function constructTokenIdMapping($sourceTokens, $sourceOutcomes, $targetOptions)
    {
        $mappingResult = [];
        if(!empty($sourceTokens)){
            Log::info('Step 5c - sourceTokens count: ' . count($sourceTokens));
        } else {
            Log::error('Step 5c - sourceTokens is empty');
        }
        if(!empty($sourceOutcomes)){
            Log::info('Step 5c - sourceOutcomes count: ' . count($sourceOutcomes));
        } else {
            Log::error('Step 5c - sourceOutcomes is empty');
        }
        if(!empty($targetOptions)){
            Log::info('Step 5c - targetOptions count: ' . count($targetOptions));
        } else {
            Log::error('Step 5c - targetOptions is empty');
        }
        if(count($sourceTokens) !== count($sourceOutcomes) || count($sourceTokens) !== count($targetOptions)){
            Log::error('Step 5c - Mismatch in counts: sourceTokens: ' . count($sourceTokens) . ', sourceOutcomes: ' . count($sourceOutcomes) . ', targetOptions: ' . count($targetOptions));
            return $this->error('数据不一致：Polymarket 选项数量与 Foregate 选项数量不匹配');
        }
        /**
         * Logic:
         * 1. Loop through the Polymarket tokens.
         * 2. Use the $index to match the corresponding Foregate option and Outcome name.
         */
        if (is_array($sourceTokens)) {
            foreach ($sourceTokens as $index => $sourceTokenId) {
                // Safety check: ensure both the Foregate option and Outcome name exist for this index
                if (isset($targetOptions[$index]) && isset($sourceOutcomes[$index])) {
                    $mappingResult[] = [
                        "sourceTokenId" => (string)$sourceTokenId,
                        "targetTokenId" => (string)$targetOptions[$index]['optionId'],
                        // Using the poly outcome content (e.g., "Lakers", "Clippers")
                        "type"          => (string)$sourceOutcomes[$index]
                    ];
                }
            }
        }

        // Convert the final array to JSON
        $token_id_mapping = json_encode($mappingResult, JSON_UNESCAPED_UNICODE);

        return $token_id_mapping;
    }

    /**
     * Get the current market table index from configuration
     * @return string The index value (e.g., '03')
     * @throws \Exception If config is missing or DB fails
     */
    private function getMarketTableIndex(?string $conn = null)
    {
        try {
            // run_config.market_table_index 是【分片本地状态】（每片各维护自己的计数器，mm_0/mm_1 实测不同），
            // 必须从目标分片读，不能用默认连接
            $db = $conn !== null ? Db::connect($conn) : Db::connect();
            $indexValue = $db->name('run_config')
                ->where('config_name', 'market_table_index')
                ->value('value');

            if ($indexValue === null) {
                Log::error("Configuration 'market_table_index' missing in run_config on conn=" . ($conn ?? 'default'));
                throw new \Exception("系统配置错误：找不到 market_table_index 记录");
            }

            // Return the string value (will preserve leading zeros like '03')
            return (string)$indexValue;

        } catch (\think\db\exception\DbException $e) {
            // Step 3: Handle database-specific errors (connection, table missing, etc.)
            Log::error("Database Error in getMarketTableIndex: " . $e->getMessage());
            throw new \Exception("数据库查询异常，请检查 run_config 表是否存在");
            
        } catch (\Throwable $e) {
            // Step 4: Handle any other unexpected system crashes
            Log::error("General Error in getMarketTableIndex: " . $e->getMessage());
            throw $e; 
        }
    }

    /**
     * Main execution function to process sports market data
     * @param array $data The input data containing necessary parameters
     * @return \think\response\Json The response with processing results
     */
    private function processSportsMarket($data)
    {
        // main execution block for data processing in each routes sports/non-sports, pack into execution function
        // Step 4. get conditionID from relation fields.
        // Call command #1: Resolve Polymarket Sports URL by ConditionID
        $rawRelation = $data['relation'];
        $conditionIDs=$this->extractConditionIDs($rawRelation);
        if(!empty($conditionIDs)){
            Log::info('Step 4: Extracted ConditionID: ' . json_encode($conditionIDs));
        } else {
            Log::error('Step 4 Fail: No ConditionID found in relation field: ' . $rawRelation);
            return json(['code' => 0, 'info' => '未找到有效的 ConditionID，请检查关联字段格式']);
        }
        
        // Step 5. Call command #2: Outcomes Login & Share Init
        Log::info('Step 5: Triggering Command #3 (outcomesFgLogin)');
        $loginData = $this->outcomesFgLogin($data['fg_market_id']);
        if ($loginData['status'] !== 'success') {
            $this->error('Foregate account sync failed: ' . json_encode($loginData));
            return json(['code' => 0, 'info' => 'Foregate 账户同步失败: ' . json_encode($loginData)]);
        }

        // Accessing fields correctly
        if (isset($loginData['status']) && $loginData['status'] === 'success') {
            $accountInfo = $loginData['market_mapping_preview'];
            $outcomes = $loginData['outcomes'];
            
            Log::info('Outcome login info fetched successfully for TargetGroupId: ' . $accountInfo['TargetGroupId']);
            Log::info('First Outcome Normal User: ' . $outcomes[0]['normal_user_id']);
        } else {
            Log::error('Login failed: ' . json_encode($loginData));
            return json(['code' => 0, 'info' => 'Foregate 账户登录失败: ' . json_encode($loginData)]);
        }

        // 跨片 2PC：先收集每条市场要插入的目标分片 + 数据，loop 结束后用
        // ShardManager::fanOutWriteInTx 一次性跨片提交。原来用的是 Db::startTrans()
        // (默认连接)，但 INSERT 落在分片连接上，事务对它无效 → 多条跨片场景下，
        // 中途失败时已成功的行会留在某些分片上无法回滚。
        $rowsByShard = [];  // sid => [['name' => 报错用, 'data' => $finalInsertData], ...]
        try {
            foreach ($conditionIDs as $item) {
                $conditionID = trim($item['conditionId']);
                $targetName = trim($item['name']);

                // Fetch Poly Data
                $res1 = $this->fetchPolySportsUrl($conditionID);
                $resolveData = $res1->getData();
                if ($resolveData['code'] !== 200) {
                    throw new \Exception("Polymarket 链接解析失败 ({$targetName})");
                }
                $marketInfo = $resolveData['data'];

                // match outcomes with marketInfo to find the correct accountInfo (in case of multiple outcomes returned from fg login)
                if (count($outcomes) == 1 && !empty($outcomes[0])) {      //one record match
                    Log::info('Single market match found, proceeding with processing');
                    // normal processing logic for single match scenario
                    $outcomeInfo = $outcomes[0];
                } else {    //more than one outcome from fg login, need to match with polymarket outcomes to find the correct one
                    Log::info('Multiple market matches found, starting matching process');
                    // matching logic to find the correct outcome based on market name or other identifiers
                    $matched = false;
                    foreach ($outcomes as $outcome) {
                            // Matching logic: compare the outcome's name with the market name from Polymarket (case-insensitive)
                        if (strtoupper(trim($item['name'])) === strtoupper(trim($outcome['name']))) {
                            $outcomeInfo = $outcome;
                            $matched = true;
                            Log::info('Perfect Match found: ' . $item['name']);
                            break;
                        }
                    }
                    if (!$matched) {
                        Log::error('No matching outcome found for market: ' . $marketInfo['market_name']);
                        throw new \Exception("未找到与 Polymarket 市场匹配的 Foregate outcome 信息 ({$targetName})");
                    }
                }

                if(empty($outcomeInfo)){
                    Log::error('Outcome info is empty after matching for market: ' . $marketInfo['market_name']);
                    throw new \Exception("匹配的 Foregate outcome 信息为空 ({$targetName})");
                }else{
                    Log::info('Matched Outcome Info: ' . json_encode($outcomeInfo, JSON_UNESCAPED_UNICODE));
                }
                // Step 5b.
                //process accountInfo and match with database records to get TargetAccountID and TradeAccountIDs
                list($accountInfo['TargetAccountID'], $tradeAccountIDs) = $this->processAccountType(
                $outcomeInfo['normal_user_id'],
                $outcomeInfo['volume_user_ids']
                );
                $accountInfo['TradeAccountIDs'] = implode('|', $tradeAccountIDs);

                if (empty($accountInfo['TargetAccountID'])) {
                    Log::error('Failed to retrieve TargetAccountID for market: ' . $marketInfo['market_name']);
                    throw new \Exception("未找到匹配的 Foregate 账户信息 ({$targetName})");
                }else{
                    Log::info('Step 5b: Account info processed with TargetAccountID and TradeAccountIDs');
                }
        
                // Step 5c: Construct token_id_mapping
                // Log and check data types for debugging
                Log::info('Step 5c - clob_token_ids type: ' . gettype($marketInfo['clob_token_ids']));
                Log::info('Step 5c - clob_token_ids content: ' . json_encode($marketInfo['clob_token_ids'], JSON_UNESCAPED_UNICODE));
                //echo "clob_token_ids type: " . gettype($marketInfo['clob_token_ids']) . " | Content: " . json_encode($marketInfo['clob_token_ids']) . PHP_EOL;
                Log::info('Step 5c - poly outcomes type: ' . gettype($marketInfo['outcomes']));
                Log::info('Step 5c - poly outcomes content: ' . json_encode($marketInfo['outcomes'], JSON_UNESCAPED_UNICODE));
                //echo "outcomes type: " . gettype($marketInfo['outcomes']) . " | Content: " . json_encode($marketInfo['outcomes']) . PHP_EOL;
                // Log and Print for $outcomeInfo['options']
                Log::info('Step 5c - options type: ' . gettype($outcomeInfo['options']));
                Log::info('Step 5c - options content: ' . json_encode($outcomeInfo['options'], JSON_UNESCAPED_UNICODE));
                //echo "options type: " . gettype($outcomeInfo['options']) . " | Content: " . json_encode($outcomeInfo['options']) . PHP_EOL;
                
                // after array type safety checks, assign them directly
                $sourceTokens = $marketInfo['clob_token_ids'] ?? [];
                $sourceOutcomes = $marketInfo['outcomes'] ?? [];
                $targetOptions = $outcomeInfo['options'] ?? [];
                $token_id_mapping = $this->constructTokenIdMapping(
                    $sourceTokens,
                    $sourceOutcomes,
                    $targetOptions
                );
                if(empty($token_id_mapping)){
                    Log::info('Token ID mapping construction failed for market: ' . $token_id_mapping);
                    throw new \Exception("Token ID 映射构建失败 ({$targetName})");
                }else{
                    Log::info('Step 5c: token_id_mapping constructed: ' . json_encode($token_id_mapping, JSON_UNESCAPED_UNICODE));
                }

                // Step 6. Check email account & Get Source Account ID from "accounts" table
                $sourceAccountID=$this->checkEmailAccount($data['polymarket_market_email_account']);
                if (!$sourceAccountID) {
                    Log::info('未找到与该邮件对应的 Source Account ID，请检查邮箱配置' . $data['polymarket_market_email_account']);
                    throw new \Exception("Polymarket 账户检查失败 ({$data['polymarket_market_email_account']})");
                } else {
                    Log::info('Step 6: Source Account ID retrieved successfully: ' . $sourceAccountID);
                }

                // Ensure variables are arrays to prevent "Cannot use offset as array" errors
                $marketInfo = is_array($marketInfo) ? $marketInfo : [];
                $accountInfo = is_array($accountInfo) ? $accountInfo : [];
                $data = is_array($data) ? $data : [];

                // Step 7. Combined data mapping with new field requirements
                // table_index 字段稍后在 fan-out callback 内按目标分片现读现写（每片本地状态）
                $finalInsertData = [
                    // --- USER INPUTS & CORE ---
                    'market_name'           => $marketInfo['market_name'] ?? 'Unknown',
                    'market_desc'           => $marketInfo['description'] ?? $marketInfo['market_name'] ?? '',
                    'market_category'       => $data['market_category'],       //form user dropdown
                    'market_sub_category'   => $data['market_sub_category'],    //form user dropdown

                    // --- SOURCE CONFIG (Polymarket) ---
                    'source_vendor'         => 'POLYMARKET',
                    'source_account_id'     => $sourceAccountID, // mapped from database lookup
                    'source_group_id'       => $marketInfo['group_id'] ?? '',
                    'source_market_id'      => $marketInfo['source_market_id'],
                    'source_market_address' => $marketInfo['source_market_address'],
                    'source_web_link'       => $data['polymarket_url'],
                    'source_status'         => "PENDING",
                    'source_start_time'     => $marketInfo['source_start_time'] ?? null,   //calculated in Python script
                    'source_end_time'       => $marketInfo['source_end_time'] ?? null,

                    // --- TARGET CONFIG (Foregate) ---
                    'target_vendor'         => 'FOREGATE',
                    'target_account_id'     => $accountInfo['TargetAccountID'],    //fixed
                    'target_group_id'       => $accountInfo['TargetGroupId'],
                    'target_market_id'      => (string)$outcomeInfo['outcomeId'],            //fix ==outcomeID
                    'target_coin_id'        => intval($accountInfo['TargetCoinId'] ?? 0),
                    'target_status'         => "PENDING",
                    'target_start_time'     => isset($accountInfo['startTime']) ? date('Y-m-d H:i:s', strtotime($accountInfo['startTime'])) : null,
                    'target_end_time'       => isset($accountInfo['endTime']) ? date('Y-m-d H:i:s', strtotime($accountInfo['endTime'])) : null,

                    // --- DATA PAYLOADS ---
                    'token_id_mapping'      => $token_id_mapping,                        //fixed
                    'trade_account_ids'     => $accountInfo['TradeAccountIDs'] ?? '',    //fixed

                    // --- NEW SPECIAL FIELDS ---
                    'is_host_market'        => intval($data['is_hot_market']),
                    'run_machine_name'      => '',                                        //set empty.
                    // table_index 字段稍后在 callback 内写入

                    // config: {"negRisk": true/false}
                    'config'                => json_encode([
                        'negRisk' => (bool)($marketInfo['negRisk'] ?? false)
                    ]),

                    // --- SYSTEM FIELDS ---
                    'created_at'            => date('Y-m-d H:i:s'),
                    'updated_at'            => date('Y-m-d H:i:s'),
                ];

                // 路由到目标分片，分桶（不立即 INSERT —— 等 loop 全部预处理完再 2PC 写）
                $insertSid = $this->resolveInsertShardId((string)($finalInsertData['target_group_id'] ?? ''));
                $rowsByShard[$insertSid][] = ['name' => $targetName, 'data' => $finalInsertData];
            }
        } catch (\Throwable $e) {
            Log::error('processSportsMarket prepare phase failed: ' . $e->getMessage());
            throw $e; // 预处理阶段错误：还没开任何分片事务，直接抛回外层
        }

        if (empty($rowsByShard)) {
            return json(['code' => 0, 'info' => '没有有效的市场数据可保存']);
        }

        // 提前每片读 run_config.market_table_index（分片本地状态，跨片不一致）。
        // 必须在事务外读，避免事务持有 lock 影响读
        $tableIndexByShard = [];
        foreach (array_keys($rowsByShard) as $sid) {
            $tableIndexByShard[$sid] = $this->getMarketTableIndex(ShardManager::conn($sid));
        }

        // 跨片 2PC：fanOutWriteInTx 会对所有 status=1 分片各开 tx；
        // 没被分到行的分片就是空 tx（开 startTrans + 空操作 + commit，无副作用）。
        // 任一片失败 → 全部回滚（包含已成功的片）
        $perShard = ShardManager::fanOutWriteInTx(function (string $conn, array $info) use ($rowsByShard, $tableIndexByShard) {
            $sid = (int)$info['id'];
            $items = $rowsByShard[$sid] ?? [];
            if (empty($items)) {
                return ['inserted' => 0];
            }
            $tableIndex = $tableIndexByShard[$sid];
            $inserted = 0;
            foreach ($items as $entry) {
                $row = $entry['data'];
                $row['table_index'] = $tableIndex;
                $r = Db::connect($conn)->name('market_mapping_config')->insert($row);
                if (!$r) {
                    // 抛出会让 fanOutWriteInTx 把所有片回滚
                    throw new \Exception("数据库写入失败 ({$entry['name']}) on shard {$sid}");
                }
                $inserted++;
            }
            return ['inserted' => $inserted];
        });

        $failed = array_filter($perShard, static fn($r) => !($r['ok'] ?? false));
        if (!empty($failed)) {
            $detail = json_encode($failed, JSON_UNESCAPED_UNICODE);
            Log::error('processSportsMarket fan-out write failed (all rolled back): ' . $detail);
            throw new \Exception('部分分片写入失败（已全部回滚）: ' . $detail);
        }

        Log::info('All market mappings processed and committed successfully across shards.');
        return json(['code' => 1, 'info' => '市场关联添加成功！', 'url' => '']);
    }

    //case that 1 match 1 market for event processing
    /*
    * Main execution function to process non-sports market data
    * @param array $data The input data containing necessary parameters
    * @return \think\response\Json The response with processing results
    */
     private function processMarket($data)
    {
        //testing code to call ResolveMarket command directly
        $url=$data['polymarket_url'];
        $marketData=$this->fetchPolyUrl($url);
        if (empty($marketData) || !is_array($marketData) || count($marketData) == 0) {
            Log::error('Failed to fetch market data from fetchPolyUrl: ' . json_encode($marketData));
            return json(['code' => 0, 'info' => 'Polymarket 链接解析失败: ' . json_encode($marketData)]);
        }
        Log::info('Market info fetched from fetchPolyUrl: ' . json_encode($marketData));

        // call outcome login command
        $loginData = $this->outcomesFgLogin($data['fg_market_id']);
        if ($loginData['status'] !== 'success') {
            $this->error('Foregate account sync failed: ' . json_encode($loginData));
            return json(['code' => 0, 'info' => 'Foregate 账户同步失败: ' . json_encode($loginData)]);
        }

        // Accessing fields correctly
        if (isset($loginData['status']) && $loginData['status'] === 'success') {
            $accountInfo = $loginData['market_mapping_preview'];
            $outcomes = $loginData['outcomes'];
            
            Log::info('Outcome login info fetched successfully for TargetGroupId: ' . $accountInfo['TargetGroupId']);
            Log::info('First Outcome Normal User: ' . $outcomes[0]['normal_user_id']);
        } else {
            Log::error('Login failed: ' . json_encode($loginData));
        }

        // main execution block
        if (count($marketData) == 1 && count($outcomes) == 1) {      //one record match
            Log::info('Single market match found, proceeding with processing');
            // normal processing logic for single match scenario
            $marketInfo = $marketData[0];
            $outcomeInfo = $outcomes[0];


            //process accountInfo and match with database records to get TargetAccountID and TradeAccountIDs
            try {
                list($accountInfo['TargetAccountID'], $tradeAccountIDs) = $this->processAccountType(
                    $outcomeInfo['normal_user_id'],
                    $outcomeInfo['volume_user_ids']
                );
                $accountInfo['TradeAccountIDs'] = implode('|', $tradeAccountIDs);
            } catch (\Exception $e) {
                return json(['code' => 0, 'info' => '账户类型处理失败: ' . $e->getMessage()]);
            }
            Log::info('Step 5b: Account info processed with TargetAccountID and TradeAccountIDs');


            // Step 5c: Construct token_id_mapping
            // Log and check data types for debugging
            Log::info('Step 5c - clob_token_ids type: ' . gettype($marketInfo['clob_token_ids']));
            Log::info('Step 5c - clob_token_ids content: ' . json_encode($marketInfo['clob_token_ids'], JSON_UNESCAPED_UNICODE));
            //echo "clob_token_ids type: " . gettype($marketInfo['clob_token_ids']) . " | Content: " . json_encode($marketInfo['clob_token_ids']) . PHP_EOL;
            Log::info('Step 5c - poly outcomes type: ' . gettype($marketInfo['outcomes']));
            Log::info('Step 5c - poly outcomes content: ' . json_encode($marketInfo['outcomes'], JSON_UNESCAPED_UNICODE));
            //echo "outcomes type: " . gettype($marketInfo['outcomes']) . " | Content: " . json_encode($marketInfo['outcomes']) . PHP_EOL;
            // Log and Print for $loginInfo['data']['options']
            Log::info('Step 5c - options type: ' . gettype($outcomeInfo['options']));
            Log::info('Step 5c - options content: ' . json_encode($outcomeInfo['options'], JSON_UNESCAPED_UNICODE));
            //echo "options type: " . gettype($loginData['data']['options']) . " | Content: " . json_encode($loginData['data']['options']) . PHP_EOL;
            
            // after array type safety checks, assign them directly
            $sourceTokens = $marketInfo['clob_token_ids'] ?? [];
            $sourceOutcomes = $marketInfo['outcomes'] ?? [];
            $targetOptions = $outcomeInfo['options'] ?? [];
            $token_id_mapping = $this->constructTokenIdMapping(
                $sourceTokens,
                $sourceOutcomes,
                $targetOptions
            );
            Log::info('Step 5c: token_id_mapping constructed: ' . json_encode($token_id_mapping, JSON_UNESCAPED_UNICODE));

            // Step 6. Check email account & Get Source Account ID from "accounts" table
            $sourceAccountID=$this->checkEmailAccount($data['polymarket_market_email_account']);
            if (!$sourceAccountID) {
                return json(['code' => 0, 'info' => '未找到与该邮件对应的 Source Account ID，请检查邮箱配置']);
            }

            // Ensure variables are arrays to prevent "Cannot use offset as array" errors
            $marketInfo = is_array($marketInfo) ? $marketInfo : [];
            $accountInfo = is_array($accountInfo) ? $accountInfo : [];
            $data = is_array($data) ? $data : [];

            //get table_index
            $table_index = $this->getMarketTableIndex();
            Log::info('Step 6b: Retrieved table_index: ' . $table_index);

            // Step 7. Combined data mapping with new field requirements
            try {
                $finalInsertData = [
                    // --- USER INPUTS & CORE ---
                    'market_name'           => $marketInfo['market_name'] ?? 'Unknown',
                    'market_desc'           => $marketInfo['description'] ?? $marketInfo['market_name'] ?? '',
                    'market_category'       => $data['market_category'],       //form user dropdown
                    'market_sub_category'   => $data['market_sub_category'],    //form user dropdown

                    // --- SOURCE CONFIG (Polymarket) ---
                    'source_vendor'         => 'POLYMARKET',
                    'source_account_id'     => $sourceAccountID, // mapped from database lookup
                    'source_group_id'       => $marketInfo['group_id'] ?? '',
                    'source_market_id'      => $marketInfo['source_market_id'],
                    'source_market_address' => $marketInfo['source_market_address'],
                    'source_web_link'       => $data['polymarket_url'],
                    'source_status'         => "PENDING",
                    'source_start_time'     => $marketInfo['source_start_time'] ?? null,   //calculated in Python script
                    'source_end_time'       => $marketInfo['source_end_time'] ?? null,

                    // --- TARGET CONFIG (Foregate) ---
                    'target_vendor'         => 'FOREGATE',
                    'target_account_id'     => $accountInfo['TargetAccountID'],    //fixed 
                    'target_group_id'       => $accountInfo['TargetGroupId'],
                    'target_market_id'      => (string)$outcomeInfo['outcomeId'],            //to fix ==outcomeId
                    'target_coin_id'        => intval($accountInfo['TargetCoinId'] ?? 0),
                    'target_status'         => "PENDING",
                    'target_start_time'     => isset($accountInfo['startTime']) ? date('Y-m-d H:i:s', strtotime($accountInfo['startTime'])) : null,
                    'target_end_time'       => isset($accountInfo['endTime']) ? date('Y-m-d H:i:s', strtotime($accountInfo['endTime'])) : null,

                    // --- DATA PAYLOADS ---
                    'token_id_mapping'      => $token_id_mapping,                        //fixed 
                    'trade_account_ids'     => $accountInfo['TradeAccountIDs'] ?? '',    //fixed
                    
                    // --- NEW SPECIAL FIELDS ---
                    'is_host_market'        => intval($data['is_hot_market']),
                    'run_machine_name'      => '',                                        //set empty.
                    'table_index'           => $table_index,                              //fixed
                    
                    // config: {"negRisk": true/false} 
                    'config'                => json_encode([
                        'negRisk' => (bool)($marketInfo['negRisk'] ?? false)
                    ]),

                    // --- SYSTEM FIELDS ---
                    'created_at'            => date('Y-m-d H:i:s'),
                    'updated_at'            => date('Y-m-d H:i:s'),
                ];
            } catch (\Exception $e) {
                Log::error('Data Mapping Exception: ' . $e->getMessage());
                return $this->error('数据映射异常: ' . $e->getMessage());
            }
            Log::info('Debug step 7: Array successfully constructed');

            // Step 8. Validate combined data (Optional final check before DB)
            if (empty($finalInsertData['target_account_id']) || $finalInsertData['target_account_id'] == "N/A") {
                $this->error('Validation Failed at Step 8: target_account_id is empty or N/A. Payload: ' . json_encode($finalInsertData));
                return json(['code' => 0, 'info' => '保存失败: 无法从 Foregate 获取有效的 Maker Account ID']);
            }

            // Step 9. Insert into DB (按 target_group_id 路由到对应分片；table_index 从该分片重读)
            Log::info('Step 9: Inserting combined data into DB');
            $insertSid = $this->resolveInsertShardId((string)($finalInsertData['target_group_id'] ?? ''));
            $finalInsertData['table_index'] = $this->getMarketTableIndex(ShardManager::conn($insertSid));
            $result = Db::connect(ShardManager::conn($insertSid))->name('market_mapping_config')->insert($finalInsertData);

            if ($result) {
                return json(['code' => 1, 'info' => '市场关联添加成功！', 'url' => '']);
            } else {
                return json(['code' => 0, 'info' => '数据库写入失败，请检查数据库连接']);
            }

        } else {     //multi records match
            Log::warning('Multiple market matches found, manual review needed');
            //this blcok will not be executed

        }
    }

    
    /**
     * Main execution function to process non-sports market data with multiple matches
     * @param array $data The input data containing necessary parameters
     * @return \think\response\Json The response with processing results
     */
    private function processMultiMarkets($data)
    {
        //
        //testing code to call ResolveMarket command directly
        $url=$data['polymarket_url'];
        $marketData=$this->fetchPolyUrl($url);
        if (empty($marketData) || !is_array($marketData) || count($marketData) == 0) {
            Log::error('Failed to fetch market data from fetchPolyUrl: ' . json_encode($marketData));
            return json(['code' => 0, 'info' => 'Polymarket 链接解析失败: ' . json_encode($marketData)]);
        }
        Log::info('Market info fetched from fetchPolyUrl: ' . json_encode($marketData));

        // call outcome login command
        $loginData = $this->outcomesFgLogin($data['fg_market_id']);
        if ($loginData['status'] !== 'success') {
            $this->error('Foregate account sync failed: ' . json_encode($loginData));
            return json(['code' => 0, 'info' => 'Foregate 账户同步失败: ' . json_encode($loginData)]);
        }

        // Accessing fields correctly
        if (isset($loginData['status']) && $loginData['status'] === 'success') {
            $accountInfo = $loginData['market_mapping_preview'];
            $outcomes = $loginData['outcomes'];
            
            Log::info('Outcome login info fetched successfully for TargetGroupId: ' . $accountInfo['TargetGroupId']);
            Log::info('First Outcome Normal User: ' . $outcomes[0]['normal_user_id']);
        } else {
            Log::error('Login failed: ' . json_encode($loginData));
            return json(['code' => 0, 'info' => 'Foregate 账户登录失败: ' . json_encode($loginData)]);
        }

        // main execution block
        if (count($marketData) == 1 && count($outcomes) == 1) {      //one record match
            Log::info('Single market match found, proceeding with processing');
            //this block will not be executed, as the route for single market is different, but keep the code for coherence

        } else {     //multi records match
            Log::info('Multiple market matches found, proceeding with multi-match logic');
            
            foreach ($outcomes as $outcomeInfo){
                try {
                    //inner logic.
                    foreach ($marketData as $marketInfo){
                        //macthing
                        if(strtoupper(trim($outcomeInfo['name'])) == strtoupper(trim($marketInfo['group_item_title']))){
                            Log::info('Match found for outcome: ' . $outcomeInfo['name'] . ' with market: ' . $marketInfo['market_name']);

                            // Step 5b.
                            //process accountInfo and match with database records to get TargetAccountID and TradeAccountIDs
                            list($accountInfo['TargetAccountID'], $tradeAccountIDs) = $this->processAccountType(
                            $outcomeInfo['normal_user_id'],
                            $outcomeInfo['volume_user_ids']
                            );
                            $accountInfo['TradeAccountIDs'] = implode('|', $tradeAccountIDs);

                            if (empty($accountInfo['TargetAccountID'])) {
                                Log::error('Failed to retrieve TargetAccountID for market: ' . $marketInfo['market_name']);
                                throw new \Exception("未找到匹配的 Foregate 账户信息 ({$marketInfo['market_name']})");
                            }else{
                                Log::info('Step 5b: Account info processed with TargetAccountID and TradeAccountIDs');
                            }

                            // Step 5c: Construct token_id_mapping
                            // Log and check data types for debugging
                            // Log::info('Step 5c - clob_token_ids type: ' . gettype($marketInfo['clob_token_ids']));
                            // Log::info('Step 5c - clob_token_ids content: ' . json_encode($marketInfo['clob_token_ids'], JSON_UNESCAPED_UNICODE));
                            // //echo "clob_token_ids type: " . gettype($marketInfo['clob_token_ids']) . " | Content: " . json_encode($marketInfo['clob_token_ids']) . PHP_EOL;
                            // Log::info('Step 5c - poly outcomes type: ' . gettype($marketInfo['outcomes']));
                            // Log::info('Step 5c - poly outcomes content: ' . json_encode($marketInfo['outcomes'], JSON_UNESCAPED_UNICODE));
                            // //echo "outcomes type: " . gettype($marketInfo['outcomes']) . " | Content: " . json_encode($marketInfo['outcomes']) . PHP_EOL;
                            // // Log and Print for $outcomeInfo['options']
                            // Log::info('Step 5c - options type: ' . gettype($outcomeInfo['options']));
                            // Log::info('Step 5c - options content: ' . json_encode($outcomeInfo['options'], JSON_UNESCAPED_UNICODE));
                            //echo "options type: " . gettype($outcomeInfo['options']) . " | Content: " . json_encode($outcomeInfo['options']) . PHP_EOL;
                            
                            // after array type safety checks, assign them directly
                            $sourceTokens = $marketInfo['clob_token_ids'] ?? [];
                            $sourceOutcomes = $marketInfo['outcomes'] ?? [];
                            $targetOptions = $outcomeInfo['options'] ?? [];
                            $token_id_mapping = $this->constructTokenIdMapping(
                                $sourceTokens,
                                $sourceOutcomes,
                                $targetOptions
                            );
                            if(empty($token_id_mapping)){
                                Log::info('Token ID mapping construction failed for market: ' . $token_id_mapping);
                                throw new \Exception("Token ID 映射构建失败 ({$marketInfo['market_name']})");
                            }else{
                                Log::info('Step 5c: token_id_mapping constructed: ' . json_encode($token_id_mapping, JSON_UNESCAPED_UNICODE));
                            }

                            // Step 6. Check email account & Get Source Account ID from "accounts" table
                            $sourceAccountID=$this->checkEmailAccount($data['polymarket_market_email_account']);
                            if (!$sourceAccountID) {
                                Log::info('未找到与该邮件对应的 Source Account ID，请检查邮箱配置' . $data['polymarket_market_email_account']);
                                throw new \Exception("Polymarket 账户检查失败 ({$data['polymarket_market_email_account']})");
                            } else {
                                Log::info('Step 6: Source Account ID retrieved successfully: ' . $sourceAccountID);
                            }

                            // Ensure variables are arrays to prevent "Cannot use offset as array" errors
                            $marketInfo = is_array($marketInfo) ? $marketInfo : [];
                            $accountInfo = is_array($accountInfo) ? $accountInfo : [];
                            $data = is_array($data) ? $data : [];

                            //get table_index
                            $table_index = $this->getMarketTableIndex();
                            Log::info('Step 6b: Retrieved table_index: ' . $table_index);

                            // Step 7. Combined data mapping with new field requirements
                            $finalInsertData = [
                                // --- USER INPUTS & CORE ---
                                'market_name'           => $marketInfo['market_name'] ?? 'Unknown',
                                'market_desc'           => $marketInfo['description'] ?? $marketInfo['market_name'] ?? '',
                                'market_category'       => $data['market_category'],       //form user dropdown
                                'market_sub_category'   => $data['market_sub_category'],    //form user dropdown

                                // --- SOURCE CONFIG (Polymarket) ---
                                'source_vendor'         => 'POLYMARKET',
                                'source_account_id'     => $sourceAccountID, // mapped from database lookup
                                'source_group_id'       => $marketInfo['group_id'] ?? '',
                                'source_market_id'      => $marketInfo['source_market_id'],
                                'source_market_address' => $marketInfo['source_market_address'],
                                'source_web_link'       => $data['polymarket_url'],
                                'source_status'         => "PENDING",
                                'source_start_time'     => $marketInfo['source_start_time'] ?? null,   //calculated in Python script
                                'source_end_time'       => $marketInfo['source_end_time'] ?? null,

                                // --- TARGET CONFIG (Foregate) ---
                                'target_vendor'         => 'FOREGATE',
                                'target_account_id'     => $accountInfo['TargetAccountID'],    //fixed 
                                'target_group_id'       => $accountInfo['TargetGroupId'],
                                'target_market_id'      => (string)$outcomeInfo['outcomeId'],            //fix ==outcomeID
                                'target_coin_id'        => intval($accountInfo['TargetCoinId'] ?? 0),
                                'target_status'         => "PENDING",
                                'target_start_time'     => isset($accountInfo['startTime']) ? date('Y-m-d H:i:s', strtotime($accountInfo['startTime'])) : null,
                                'target_end_time'       => isset($accountInfo['endTime']) ? date('Y-m-d H:i:s', strtotime($accountInfo['endTime'])) : null,

                                // --- DATA PAYLOADS ---
                                'token_id_mapping'      => $token_id_mapping,                        //fixed 
                                'trade_account_ids'     => $accountInfo['TradeAccountIDs'] ?? '',    //fixed
                                
                                // --- NEW SPECIAL FIELDS ---
                                'is_host_market'        => intval($data['is_hot_market']),
                                'run_machine_name'      => '',                                        //set empty.
                                'table_index'           => $table_index,                              //fixed
                                
                                // config: {"negRisk": true/false} 
                                'config'                => json_encode([
                                    'negRisk' => (bool)($marketInfo['negRisk'] ?? false)
                                ]),

                                // --- SYSTEM FIELDS ---
                                'created_at'            => date('Y-m-d H:i:s'),
                                'updated_at'            => date('Y-m-d H:i:s'),
                            ];

                            // Step 8. Insert into DB (按 target_group_id 路由到对应分片；table_index 从该分片重读)
                            Log::info('Step 8: Inserting combined data into DB');
                            $insertSid = $this->resolveInsertShardId((string)($finalInsertData['target_group_id'] ?? ''));
                            $finalInsertData['table_index'] = $this->getMarketTableIndex(ShardManager::conn($insertSid));
                            $result = Db::connect(ShardManager::conn($insertSid))->name('market_mapping_config')->insert($finalInsertData);
                            if (!$result) {
                                Log::error('Database insertion failed on shard ' . $insertSid . ' for market: ' . $marketInfo['market_name']);
                                throw new \Exception("数据库写入失败 ({$targetName})");
                            }
                            Log::info('Market mapping inserted successfully for market: ' . $marketInfo['market_name']);

                            break; // once matched and processed, break the inner loop to avoid duplicate processing for the same outcome
                        }
                    
                    }
                } catch (\Exception $e) {
                    Log::error('Error processing market-outcome pair: ' . $e->getMessage());
                    Log::info('Continuing with the next pair after error: ' . $outcomeInfo['name']);
                    // Continue with the next pair instead of breaking the loop
                    continue;
                }
            }
        } 
        return json(['code' => 1, 'info' => '市场关联添加成功！', 'url' => '']);
    }
    
    /**
    * Handles the CSV file upload and processing for bulk market mapping configuration.
    * For POST requests, it processes the uploaded CSV file and returns a JSON response indicating success or failure.
    * For GET requests, it renders the dedicated upload page for users to upload their CSV files.
    *
    * @return \think\response\Json|\think\response\View The JSON response for POST requests or the upload page view for GET requests.
    */
    public function upload() 
    {
        if ($this->request->isPost()) {
            Log::info('--- CSV Upload Start ---');
            // DEBUG: Look at your runtime/log to see what's in the request
            Log::info('All Files: ' . json_encode($_FILES)); 
            Log::info('Request Method: ' . $this->request->method());
            
            $file = $this->request->file('csv_file');
            if (empty($file)) {
                Log::error('Upload failed: No file found in request');
                return json(['code' => 0, 'info' => 'No file found']);
            }

            Log::info('File received: ' . $file->getOriginalName() . ' | Size: ' . $file->getSize() . ' bytes');

            // 1. Validation Logic (Fixes the undefined method error)
            $validate = \think\facade\Validate::rule([
                'csv_file' => 'fileExt:csv|fileSize:10485760' // 10MB limit
            ]);

            if (!$validate->check(['csv_file' => $file])) {
                $error = $validate->getError();
                Log::error('File validation failed: ' . $error);
                return json(['code' => 0, 'info' => $error]);
            }

            // 2. Directory Creation
            $uploadDir = public_path() . 'uploads/csv/' . date('Ymd') . '/';
            if (!is_dir($uploadDir)) {
                if (mkdir($uploadDir, 0755, true)) {
                    Log::info('Created new directory: ' . $uploadDir);
                } else {
                    Log::error('Failed to create directory: ' . $uploadDir);
                    return json(['code' => 0, 'info' => 'Directory creation failed']);
                }
            }

            // 3. Move the file
            $uniqueName = uniqid() . '_' . $file->getOriginalName();
            try {
                $savoObj = $file->move($uploadDir, $uniqueName);
                $filePath = $uploadDir . $savoObj->getFilename();
                
                Log::info('File moved to: ' . $filePath);

                // 4. Process the CSV
                return $this->handleCsvImport($filePath);

            } catch (\Exception $e) {
                Log::error('File move or processing failed: ' . $e->getMessage());
                return json(['code' => 0, 'info' => 'Server error during upload']);
            }

        }
        
        return $this->fetch('upload');
    }

    // This is a placeholder for the CSV import handling function
    /**
    * Handles the CSV file upload and processing for bulk market mapping configuration.
    * This function is called when a POST request with 'form_type' set to 'csv_import' is received in the add() method.
    *
    * @return \think\response\Json The JSON response indicating success or failure of the CSV import process.
    */
    private function handleCsvImport($filePath) 
    {
        $handle = fopen($filePath, 'r');
        if (empty($handle)) {
            return json(['code' => 0, 'info' => 'Unable to open CSV file']);
        }
        
        // 1. Read the header first
        // PHP 8.4 fix: Explicitly pass the default escape parameter "\\"
        $header = fgetcsv($handle, 0, ",", "\"", "\\");   
        
        // 2. Remember the position right after the header
        $dataStartOffset = ftell($handle); 

        // 3. Count total rows
        $totalRows = 0;
        while (fgetcsv($handle, 0, ",", "\"", "\\") !== false) {
            $totalRows++;
        }

        // 4. Validate count
        if ($totalRows > 31) { // Changed to > 31 as per your requirement "exceeds 31"
            fclose($handle);
            Log::error('CSV import failed: Total rows exceed limit. Total rows: ' . $totalRows);
            return json(['code' => 0, 'info' => 'CSV row count exceeds limit.']);
        }

        // 5. CRITICAL FIX: Move the pointer back to where the data started
        fseek($handle, $dataStartOffset);

        
        $processedCount = 0;
        $errorRows = [];
        $successRows = []; // Initialize this at the top
        $currentRow = 1; // Track physical row number for logging  

        while (($row = fgetcsv($handle, 0, ",", "\"", "\\")) !== false) {
            $currentRow++;
            
            // Map CSV columns
            $data = [
                'polymarket_url'                  => $row[0] ?? '',
                'fg_market_id'                    => $row[1] ?? '',
                'relation'                        => $row[2] ?? '',
                'polymarket_market_email_account' => $row[3] ?? '',
                'is_hot_market'                   => isset($row[4]) ? (strtolower($row[4]) === 'true' || $row[4] === '1' ? 1 : 0) : 0,
                'market_category'                 => $row[6] ?? '',
                'market_sub_category'             => $row[7] ?? '',
            ];

            // Validate required fields
            $rules = [
                'polymarket_url' => 'require|url|max:255',
                'fg_market_id'   => 'require|integer|gt:0',
                'polymarket_market_email_account' => 'require|email|max:100',
            ];
            
            $validate = \think\facade\Validate::rule($rules);
            if (!$validate->check($data)) {
                Log::warning("Row {$currentRow} skipped. Errors: " . json_encode($validate->getError()));
                $errorRows[] = "Row {$currentRow}: Validation failed.";
                continue; 
            }

            $marketIDStr = "marketID:" . ($data['fg_market_id'] ?? 'Unknown');

            // repeat the existing flow to handle the logic for each row
            try {
                // main execution block for data processing in sports routes
                if (!empty($data['relation']) && strpos($data['relation'], '==') !== false) { 
                    $rawRelation = $data['relation'];
                    $conditionId = strstr($rawRelation, '==', true);
                    Log::info('Extracted ConditionID: ' . $conditionId);
                    Log::info(' proceeding with sports-specific processing'); 
                    $this->processSportsMarket($data);
        
                } else { 
                    //for non-sports markets   //no ralation inputs
                    Log::info(' skipping sports-specific processing');
                    //
                    $url=$data['polymarket_url'];
                    $marketData=$this->fetchPolyUrl($url);
                    if (empty($marketData) || !is_array($marketData) || count($marketData) == 0) {
                        Log::error('Failed to fetch market data from fetchPolyUrl: ' . json_encode($marketData));
                        Log::error("Row {$currentRow}: Polymarket 链接解析失败");
                        $errorRows[] = "Row {$currentRow}: 解析失败";
                        throw new \Exception("Polymarket link analysis returned no data");
                    }
                    Log::info('Market info fetched from fetchPolyUrl: ' . json_encode($marketData));

                    // call outcome login command
                    $loginData = $this->outcomesFgLogin($data['fg_market_id']);
                    if ($loginData['status'] !== 'success') {
                        $this->error('Foregate account sync failed: ' . json_encode($loginData));
                        Log::error("Row {$currentRow}: Foregate 账户同步失败");
                        $errorRows[] = "Row {$currentRow}: Foregate 账户同步失败";
                        throw new \Exception("Foregate account sync failed");
                    }

                    // Accessing fields correctly
                    if (isset($loginData['status']) && $loginData['status'] === 'success') {
                        $accountInfo = $loginData['market_mapping_preview'];
                        $outcomes = $loginData['outcomes'];
                        
                        Log::info('Outcome login info fetched successfully for TargetGroupId: ' . $accountInfo['TargetGroupId']);
                        Log::info('First Outcome Normal User: ' . $outcomes[0]['normal_user_id']);
                    } else {
                        Log::error('Login failed: ' . json_encode($loginData));
                        // Fallback: If 'info' or 'msg' is missing in $loginData, provide a generic one
                        $reason = $loginData['info'] ?? ($loginData['msg'] ?? 'Unknown Foregate API Error');
                        throw new \Exception("Foregate Sync Failed: " . $reason);
                    }

                    // main execution block
                    if (count($marketData) == 1 && count($outcomes) == 1) {      //one record match
                        Log::info('Single market match found, proceeding with processing');
                        $this->processMarket($data);

                    } else {     //multi records match
                        Log::info('Multiple market matches found, proceeding with multi-match logic');
                        $this->processMultiMarkets($data);
                    }
            
                }            

                $processedCount++;
                //record successful records
                $successRows[] = "Row {$currentRow}: {$marketIDStr}";

            } catch (\Exception $e) {
                $rawError = trim($e->getMessage());
                $errorMsg = !empty($rawError) ? $rawError : "API server Internet error (check system logs)";
                Log::error("Row {$currentRow} failed: " . $errorMsg);
                // This captures the exact message from the 'throw new Exception' or system errors
                $errorRows[] = "Row {$currentRow}: {$marketIDStr}, Error: " . $errorMsg;
                continue;   //when one record fails, continue with the next one.
            }

        }
        fclose($handle);
        
        // Cleanup the physical file
        if (file_exists($filePath)) @unlink($filePath);

        // Final Report
        //$summary = "Import Complete. Successfully Processed Records: {$processedCount}.";
        $code = 1;

        if (!empty($errorRows)) {
            //$summary .= " Failed Processed Records: " . count($errorRows) . ". Check details below.";
            //$summary .= " Errors: " . implode("; ", $errorRows);
            // If nothing was processed but there were errors, you might want code 0
            if ($processedCount === 0) $code = 0;
        }

        return json([
            'code'   => $code, 
            'info' => "处理完成：{$processedCount} 条关联成功, " . count($errorRows) . " 条关联失败",
            'success_list' => $successRows,
            'errors' => $errorRows
        ]);
    
    }


    /**
    *  function to extract ConditionID from the relation string for sports markets
     * @param string $relation The relation string in the format "ConditionID==SomeValue"
     * @return array The extracted ConditionID and name pairs
    */
    private function extractConditionIDs(string $relation)
{
    // 1. Split by any newline character sequence (\r\n, \n, or \r)
    // This handles Case 2 (multi-line) and Case 1 (single line) equally well.
    $records = preg_split('/\R+/', $relation);

    $extractedRelations = [];

    foreach ($records as $record) {
        $record = trim($record);
        
        // Skip empty lines or comment lines
        if (empty($record) || strpos($record, '//') === 0) continue;

        // 2. Parse the record: Left of == is ID, Right of == is Name
        if (strpos($record, '==') !== false) {
            // Using limit 2 ensures names like "Yes == No" don't break the split
            $parts = explode('==', $record, 2);
            
            $conditionId = trim($parts[0]);
            $name        = trim($parts[1]);

            if ($conditionId !== '' && $name !== '') {
                $extractedRelations[] = [
                    'conditionId' => $conditionId,
                    'name'        => $name
                ];
                
                Log::info("Extracted Condition - ID: {$conditionId}, Name: {$name}");
            }
        }
    }
    
    return $extractedRelations;
}
}
