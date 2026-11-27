<?php
declare (strict_types=1);

namespace app\cool_pay3\controller;

use app\cool_pay3\model\MarketMappingConfig as MarketMappingConfigModel;
use app\cool_pay3\const\Enum;
use app\cool_pay3\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;
use think\facade\Log;
use think\facade\Console;

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
        $this->title = '关联市场';

        // 记录日志
        $this->app->log->info('MarketMappingConfig index method called');

        // 获取请求参数
        $get = $this->request->get();
        $this->app->log->info('Request parameters: ' . json_encode($get));

        $query = Db::name('market_mapping_config');
        $this->app->log->info('Query object created');

        // 添加搜索条件
        if (isset($get['group_id']) && $get['group_id'] !== '') {
            $query->where('group_id', $get['group_id']);
            $this->app->log->info('Added group_id condition: ' . $get['group_id']);
        }
        if (isset($get['market_id']) && $get['market_id'] !== '') {
            $query->where('market_id', $get['market_id']);
            $this->app->log->info('Added market_id condition: ' . $get['market_id']);
        }
        if (isset($get['market_name']) && $get['market_name'] !== '') {
            $name = $this->sanitizeLike($get['market_name']);
            $query->where('market_name', 'like', "%{$name}%");
            $this->app->log->info('Added market_name condition: ' . $name);
        }
        if (isset($get['outcome_name']) && $get['outcome_name'] !== '') {
            $name = $this->sanitizeLike($get['outcome_name']);
            $query->where('outcome_name', 'like', "%{$name}%");
            $this->app->log->info('Added outcome_name condition: ' . $name);
        }
        if (isset($get['market_status']) && $get['market_status'] !== '') {
            $query->where('market_status', $get['market_status']);
            $this->app->log->info('Added market_status condition: ' . $get['market_status']);
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
            'path' => '/admin.html#/cool_pay3/market_mapping_config/index.html', // 使用正确的路径
        ], false);
        $this->app->log->info('Pagination query executed, total: ' . $result->total());

        // 格式化数据
        $list = $result->items();

        foreach ($list as &$item) {
            // 1. Core Identification & Names
            $item['market_name'] = $item['market_name'] ?? 'N/A';
            $item['outcome_name'] = $item['outcome_name'] ?? '-';
            $item['group_id'] = $item['group_id'] ?? '';
            $item['market_id'] = $item['market_id'] ?? '';
            $item['token_id_mapping'] = $item['token_id_mapping'] ?? '';
            $item['account_id'] = $item['account_id'] ?? '';
            $item['vendor'] = $item['vendor'] ?? '';

            // 2. Market Times
            $item['start_time'] = !empty($item['start_time']) ? date('Y-m-d H:i', strtotime($item['start_time'])) : '-';
            $item['end_time'] = !empty($item['end_time']) ? date('Y-m-d H:i', strtotime($item['end_time'])) : '-';
            $item['settlement_time'] = !empty($item['settlement_time']) ? date('Y-m-d H:i', strtotime($item['settlement_time'])) : '-';

            // 3. Market Parameters (Formatting numbers for readability)
            $item['base_spread'] = number_format((float)($item['base_spread'] ?? 0), 3);
            $item['layer_count'] = $item['layer_count'] ?? 0;
            $item['layer_price_increment'] = number_format((float)($item['layer_price_increment'] ?? 0), 3);
            $item['layer_multiplier'] = number_format((float)($item['layer_multiplier'] ?? 0), 1);
            $item['mid_price_yes'] = number_format((float)($item['mid_price_yes'] ?? 0), 3);
            $item['mid_price_no'] = number_format((float)($item['mid_price_no'] ?? 0), 3);
            $item['base_step'] = number_format((float)($item['base_step'] ?? 0), 3);
            $item['bias_trigger_threshold'] = ($item['bias_trigger_threshold']*100 ?? 0) . '%';

            // 4. Status & Configs
            $item['dynamic_config'] = $item['dynamic_config'] ?? 'DEFAULT';
            $item['current_data_records'] = $item['current_data_records'] ?? 0;
            $item['market_status'] = $item['market_status'] ?? 'PENDING';
            $item['outcome'] = $item['outcome'] ?? '';

            // 5. Risk & Spread Controls (Toggles)
            $item['is_position_limit_enabled'] = !empty($item['is_position_limit_enabled']) ? 'ON' : 'OFF';
            $item['inventory_deviation_threshold'] = ($item['inventory_deviation_threshold']*100 ?? 0) . '%';
            $item['share_deviation_threshold'] = $item['share_deviation_threshold'] ?? 0;
            $item['is_spread_dynamic_enabled'] = !empty($item['is_spread_dynamic_enabled']) ? 'YES' : 'NO';

            // 6. System Timestamps
            $item['created_at'] = !empty($item['created_at']) ? date('Y-m-d H:i:s', strtotime($item['created_at'])) : '-';
            $item['updated_at'] = !empty($item['updated_at']) ? date('Y-m-d H:i:s', strtotime($item['updated_at'])) : '-';
        }

        // 分配变量到视图
        $this->assign([
            'list' => $list,
            'pagehtml' => $result->render(),
            'get' => $get,
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



        //test code
        // Define the data as a standard PHP associative array
        // $test_data = [
        //     [
        //         "outcome_name" => "aa",
        //         "yes_mid_price" => "0.60",
        //         "base_spread" => "0.04",
        //         "layer_increment" => "0.02",
        //         "layer_multiplier" => "2.0",
        //         "layer_count" => 5,
        //         "init_share_yes" => 500,
        //         "init_share_no" => 500,
        //     ],
        //     [
        //         "outcome_name" => "bb",
        //         "yes_mid_price" => "0.60",
        //         "base_spread" => "0.04",
        //         "layer_increment" => "0.02",
        //         "layer_multiplier" => "2.0",
        //         "layer_count" => 5,
        //         "init_share_yes" => 1000,
        //         "init_share_no" => 800,
        //     ]
        // ];

        // // Now passing the array is fine because we updated the function hint
        // $fetchResult = $this->fetchOrderLayers($test_data);

        // // Consistency check: The function now returns 'code', so we check for 200
        // if (!isset($fetchResult['code']) || $fetchResult['code'] != 200) {
        //     $msg = $fetchResult['message'] ?? ($fetchResult['msg'] ?? 'Unknown error');
        //     return json(['code' => 0, 'msg' => 'Failed to fetch order layers: ' . $msg]);                
        // }  

        // Log::info('Step 5: Order layers fetched successfully');
        // Log::info('Fetched Order Layers: ' . json_encode($fetchResult));


        if ($this->request->isPost()) {
            $data = $this->request->post();
            Log::info('Step 1: Form data received: ' . json_encode($data));

            // Fix empty strings for numeric limits so they don't fail the 'integer' check
            if (isset($data['stock_limit']) && $data['stock_limit'] === '') $data['stock_limit'] = null;
            //test casting
            //$data['step_size'] = (float)$data['step_size'];

            $rules = [
                'market_id'         => 'require|integer|gt:0',
                'market_name'       => 'require|max:1000',
                'start_time'        => 'require|date',
                'end_time'          => 'require|date|after:start_time',
                'settle_time'       => 'date|after:end_time',
                
                // Use the * to validate the nested outcomes array
                'outcomes'          => 'require|array',
                'outcomes.*.title'  => 'require|max:255',
                'outcomes.*.yes_shares'    => 'require|number|egt:0',
                'outcomes.*.no_shares'     => 'require|number|egt:0',
                'outcomes.*.yes_mid_price' => 'require|float|between:0.001,0.999',
                'outcomes.*.no_mid_price'  => 'require|float|between:0.001,0.999',

                // LMSR 曲线参数下沉 outcome 级：top-level 可选(向后兼容)，同时接受 outcomes.*.*
                'base_spread'       => 'float|between:0.001,0.999',
                'depth_b'           => 'integer|between:50,10000000',
                'g0'                => 'float|between:0.001,0.5',
                'slope'             => 'float|between:0,1',
                'outcomes.*.base_spread' => 'float|between:0.001,0.999',
                'outcomes.*.depth_b'     => 'integer|between:50,10000000',
                'outcomes.*.g0'          => 'float|between:0.001,0.5',
                'outcomes.*.slope'       => 'float|between:0,1',

                // Allow null/empty if not forced, but check if 'on'
                'single_side_limit' => 'require|in:on,off',
                'stock_limit' => 'integer|between:0,100',
                'dynamic_spread'    => 'require|in:on,off',
                'reprice_stale_seconds' => 'integer|between:0,3600',
            ];

            $messages = [
                'market_id.require'         => '市场ID不能为空',
                'market_id.integer'         => '市场ID必须是整数',
                'market_id.gt'              => '市场ID必须大于0',
                'market_name.require'       => '市场名称不能为空',
                'market_name.max'           => '市场名称不能超过1000字符',
                'start_time.require'        => '开始时间不能为空',
                'start_time.date'           => '开始时间格式不正确',
                'end_time.require'          => '结束时间不能为空',
                'end_time.date'             => '结束时间格式不正确',
                'end_time.after'            => '结束时间必须在开始时间之后',
                'settle_time.date'          => '结算时间格式不正确',
                'settle_time.after'         => '结算时间必须在结束时间之后',
                
                // Outcomes nested array validation messages
                'outcomes.require'                 => '未检测到市场选项数据，请先同步',
                'outcomes.*.title.require'         => '选项标题不能为空',
                'outcomes.*.title.max'             => '选项标题不能超过255字符',
                'outcomes.*.yes_shares.require'    => '"Yes"选项的股份数量不能为空',
                'outcomes.*.yes_shares.number'     => '"Yes"选项的股份数量必须是数字',
                'outcomes.*.yes_shares.egt'        => '"Yes"选项的股份数量必须大于或等于0',
                'outcomes.*.yes_mid_price.require' => '"Yes"选项的概率 P* 不能为空',
                'outcomes.*.yes_mid_price.float'   => '"Yes"选项的概率 P* 必须是数字',
                'outcomes.*.yes_mid_price.between' => '"Yes"选项的概率 P* 必须在0和1之间',
                'outcomes.*.no_shares.require'     => '"No"选项的股份数量不能为空',
                'outcomes.*.no_shares.number'      => '"No"选项的股份数量必须是数字',
                'outcomes.*.no_shares.egt'         => '"No"选项的股份数量必须大于或等于0',
                'outcomes.*.no_mid_price.require'  => '"No"选项的概率 P* 不能为空',
                'outcomes.*.no_mid_price.float'    => '"No"选项的概率 P* 必须是数字',
                'outcomes.*.no_mid_price.between'  => '"No"选项的概率 P* 必须在0和1之间',
                
                'base_spread.require'       => '基础价差不能为空',
                'base_spread.float'         => '基础价差必须是数字',
                'base_spread.gt'            => '基础价差必须大于0',
                'base_spread.between'        => '基础价差必须在0.001到0.999之间',
                'depth_b.integer'           => '深度参数 b 必须是整数',
                'depth_b.between'           => '深度参数 b 必须在 50 到 10000000 之间',
                'g0.float'                  => 'g0 必须是数字',
                'g0.between'                => 'g0 必须在 0.001 到 0.5 之间',
                'slope.float'               => 'slope 必须是数字',
                'slope.between'             => 'slope 必须在 0 到 1 之间',

                'stock_limit.integer'  => '库存偏差限额必须是整数',
                'stock_limit.between' => '库存偏差限额必须在0到100之间',
                'reprice_stale_seconds.integer' => '调价触发兜底时间必须是整数',
                'reprice_stale_seconds.between' => '调价触发兜底时间必须在0到3600秒之间',
                'single_side_limit.require' => '单边持仓限额设置不能为空',
                'single_side_limit.in'      => '单边持仓限额设置必须是"on"或"off"',
                'dynamic_spread.require'    => '动态价差设置不能为空',
                'dynamic_spread.in'         => '动态价差设置必须是"on"或"off"',
            ];

            $validate = \think\facade\Validate::rule($rules)->message($messages);

            // Dynamic requirement: If limit is "on", 库存偏差限额 must be provided
            if (isset($data['single_side_limit']) && $data['single_side_limit'] === 'on') {
                if (empty($data['stock_limit'])) {
                    return json(['code' => 0, 'info' => '开启单边持仓限额时，库存偏差限额必填']);
                }
            }

            if (!$validate->check($data)) {
                return json(['code' => 0, 'info' => '输入验证失败: ' . $validate->getError()]);
            }

            Log::info('Step 3: Form data validated successfully');
            

            // Add data to db
            try {
                $outcomes = $data['outcomes'] ?? [];
                $sync_outcomes = $this->getSyncData($data['market_id']);
                //Log::info('Fetched sync outcomes: ' . json_encode($sync_outcomes));

                // getSyncData 同步失败时返回的是 think\response\Json（非数组）→ 干净报错，避免后续当数组用崩溃
                if (!is_array($sync_outcomes) || empty($sync_outcomes['outcomes'])) {
                    return json(['code' => 0, 'info' => '同步市场数据失败，无法保存（请检查目标市场 ID 与 Foregate 同步）']);
                }
                
                // Use a transaction to ensure atomic operations
                $result = \think\facade\Db::transaction(function() use ($data, $outcomes, $sync_outcomes) {
                    $insertedCount = 0;
                    
                    foreach ($outcomes as $index => $outcome) {
                        Log::info("Processing outcome #{$index}: " . json_encode($outcome));

                        $marketMappingConfig = new MarketMappingConfigModel();
                        
                        // 1. Basic Identification
                        $marketMappingConfig->market_id       = $sync_outcomes['outcomes'][$index]['outcome_id'];    #use outcome_id
                        $marketMappingConfig->group_id        = $data['market_id']; 
                        $marketMappingConfig->market_name     = $data['market_name'];
                        $marketMappingConfig->outcome_name    = $outcome['title'];
                        
                        // 2. Generate token_id_mapping JSON
                        $mapping = $this->generateTokenMapping($sync_outcomes['outcomes'][$index], $index);
                        //Log::info("Generated token_id_mapping for outcome #{$index}: " . $mapping);

                        $marketMappingConfig->token_id_mapping = $mapping;
                        
                        // 3. Times
                        $marketMappingConfig->start_time      = $data['start_time'];
                        $marketMappingConfig->end_time        = $data['end_time'];
                        $marketMappingConfig->settlement_time = $data['settle_time'] ?? null;
                        
                        // 4. Pricing & Market Parameters (LMSR sigmoid 曲线)
                        // Casting here ensures the DB gets proper numeric values
                        // LMSR 参数下沉到 outcome 级：优先取 $outcome[...]，回退 top-level（向后兼容）
                        // 旧多层列(layer_count/layer_price_increment/layer_multiplier/base_step/bias_trigger_threshold)
                        // 不再写入，DB 保留其 NOT NULL 默认值，runtime LMSR 后不再读取。
                        $marketMappingConfig->base_spread            = (float)($outcome['base_spread'] ?? $data['base_spread']);
                        $marketMappingConfig->depth_b                = (int)($outcome['depth_b'] ?? $data['depth_b']);
                        $marketMappingConfig->g0                     = (float)($outcome['g0'] ?? $data['g0']);
                        $marketMappingConfig->slope                  = (float)($outcome['slope'] ?? $data['slope']);
                        $marketMappingConfig->mid_price_yes          = (float)($outcome['yes_mid_price'] ?? 0.5);
                        $marketMappingConfig->mid_price_no           = (float)($outcome['no_mid_price'] ?? 0.5);
                        // 文档 3.4④ 订单簿兜底重铺间隔(秒)，市场级；0=关闭兜底
                        $marketMappingConfig->reprice_stale_seconds  = (isset($data['reprice_stale_seconds']) && $data['reprice_stale_seconds'] !== '') ? (int)$data['reprice_stale_seconds'] : 60;

                        // 5. Risk Control & Toggles（份额偏差限额 share_deviation_threshold 已废弃，不再写入）
                        // 新建页「单边持仓限额」隐藏且默认关闭 → 不提交 stock_limit，用 ?? 兜底避免 undefined key。
                        $stockLimit = $data['stock_limit'] ?? null;
                        $marketMappingConfig->is_position_limit_enabled     = (($data['single_side_limit'] ?? 'off') === 'on') ? 1 : 0;
                        $marketMappingConfig->inventory_deviation_threshold = ($stockLimit !== null && $stockLimit !== '') ? floatval($stockLimit) / 100 : null;
                        $marketMappingConfig->is_spread_dynamic_enabled     = (($data['dynamic_spread'] ?? 'off') === 'on') ? 1 : 0;

                        // 数据源(天气/外盘) + F1外盘直连 + F2时间线：按 outcome 级写入（回退 top-level）
                        $mergedSrc = array_merge($data, $outcome);
                        $this->validateOutcomeConfig($mergedSrc, $data['settle_time'] ?? '', $data['end_time'] ?? '', ($outcome['title'] ?? ('Outcome ' . ($index + 1))));
                        $this->applyOutcomeDataSourceAndBatch($marketMappingConfig, $mergedSrc);

                        // 6. Required Schema Fields
                        $marketMappingConfig->vendor           = 'foregate';
                        $marketMappingConfig->market_status    = 'PENDING';
                        $marketMappingConfig->run_machine_name = ''; 
                        $marketMappingConfig->table_index      = $this->getMarketTableIndex();
                        $marketMappingConfig->account_id        = $this->getAccountID();

                        if (!$marketMappingConfig->save()) {
                            // Throwing an exception inside the transaction block triggers an automatic rollback
                            throw new \Exception("Failed to save outcome: " . $outcome['title']);
                        }
                        $insertedCount++;
                    }
                    return $insertedCount;
                });

                Log::info("Step 4: Success. Transaction committed. Inserted {$result} outcome configurations.");
                // 同 edit：父页 pjax 轻量刷新列表内容区（拿不到回退整页）
                return json(['code' => 1, 'msg' => "成功添加 {$result} 条市场选项配置", 'url' => 'javascript:(parent.$&&parent.$.form)?parent.$.form.reload():parent.location.reload()']);

            } catch (\Exception $e) {
                // Any error inside the closure above or a database failure hits here
                Log::error('Database Transaction Error: ' . $e->getMessage());
                return json(['code' => 0, 'msg' => '服务器错误或提交失败: ' . $e->getMessage()]);
            }
        }
        // This will now automatically look for add.html
        return $this->fetch();
    }

    /**
     * 按 PRD 校验单个 outcome 的中间价/时间线/批量铺单配置（add 每 outcome 调一次，edit 调一次）。
     * 违反则抛 \Exception，由 add()/edit() 的 try/catch 返回错误。
     * @param array  $src        该 outcome 的表单数据(add: array_merge(top,outcome)；edit: $data)
     * @param string $settleTime 市场结算时间
     * @param string $endTime    市场结束时间（无结算时间时回退）
     * @param string $label      错误前缀（如 "Outcome 1"）
     */
    private function validateOutcomeConfig(array $src, $settleTime, $endTime, $label = '')
    {
        $truthy = function ($v) { return $v === 'on' || $v === '1' || $v === 1 || $v === true; };
        $pfx = $label !== '' ? $label . '：' : '';

        // 中间价 Σ=1（同 outcome 所有 option 中间价合=1）
        $yesMid = $src['yes_mid_price'] ?? ($src['mid_price_yes'] ?? null);
        $noMid  = $src['no_mid_price']  ?? ($src['mid_price_no']  ?? null);
        if ($yesMid !== null && $yesMid !== '' && $noMid !== null && $noMid !== '') {
            if (abs(((float)$yesMid + (float)$noMid) - 1.0) > 0.0001) {
                throw new \Exception($pfx . 'Yes/No 中间价之和必须等于 1（当前 ' . (float)$yesMid . ' + ' . (float)$noMid . '）');
            }
        }

        // F2 时间线校验：截止时间不得晚于市场结算时间；同 outcome 内不可重复
        $timeline = $src['midprice_timeline'] ?? [];
        if (is_array($timeline) && count($timeline) > 0) {
            $settleTs = !empty($settleTime) ? strtotime($settleTime) : (!empty($endTime) ? strtotime($endTime) : 0);
            $seen = [];
            foreach ($timeline as $i => $r) {
                if (!is_array($r)) {
                    continue;
                }
                $dl = trim($r['deadline'] ?? '');
                if ($dl === '') {
                    continue;
                }
                $dlTs = strtotime($dl);
                if ($dlTs === false) {
                    throw new \Exception($pfx . '时间线第 ' . ($i + 1) . ' 条截止时间格式不正确');
                }
                if ($settleTs > 0 && $dlTs > $settleTs) {
                    throw new \Exception($pfx . '时间线第 ' . ($i + 1) . ' 条截止时间不得晚于市场结算时间');
                }
                if (isset($seen[$dl])) {
                    throw new \Exception($pfx . '时间线截止时间不可重复：' . $dl);
                }
                $seen[$dl] = true;
                // 按数据源类型校验必填项（缺省 external 向后兼容）
                $dsType = trim($r['data_source_type'] ?? '');
                if ($dsType === '') {
                    $dsType = 'external';
                }
                if ($dsType === 'weather') {
                    if (trim($r['location'] ?? '') === '' || trim($r['location_key'] ?? '') === '') {
                        throw new \Exception($pfx . '时间线第 ' . ($i + 1) . ' 条（天气）需填写经纬度并完成解析');
                    }
                } else {
                    $yesMap = trim($r['option_yes'] ?? ($r['option_mapping']['yes'] ?? ''));
                    $noMap  = trim($r['option_no'] ?? ($r['option_mapping']['no'] ?? ''));
                    if (trim($r['external_market_id'] ?? '') === '' || $yesMap === '' || $noMap === '') {
                        throw new \Exception($pfx . '时间线第 ' . ($i + 1) . ' 条（外盘）需填写外部 Market ID 并完成 Option 映射');
                    }
                }
            }
        }

        // 注：F3 批量铺单控制已废弃（相关列已从 market_mapping_config DROP），不再校验。
    }

    /**
     * F1/F2：把 outcome 级的数据源(天气/外盘)/信息源时间线配置写入 model。
     * 与 runtime 契约一致（external_source_config / midprice_timeline）。
     * 注：F3 批量铺单控制已废弃（相关列已 DROP），不再写入。
     * @param \app\cool_pay3\model\MarketMappingConfig $model
     * @param array $src 该 outcome 的表单数据（add: array_merge(top, outcome)；edit: array_merge(top, outcome)）
     */
    private function applyOutcomeDataSourceAndBatch($model, array $src)
    {
        $truthy = function ($v) {
            return $v === 'on' || $v === '1' || $v === 1 || $v === true;
        };

        // 数据源定价（天气 / 外盘）
        $dsEnabled = $truthy($src['is_data_source_pricing_enabled'] ?? null) ? 1 : 0;
        $model->is_data_source_pricing_enabled = $dsEnabled;
        $dsType = $dsEnabled ? trim($src['data_source_type'] ?? '') : '';
        $model->data_source_type     = $dsType;
        $model->data_source_sub_type = $dsEnabled ? trim($src['data_source_sub_type'] ?? '') : '';
        // 天气专用经纬度/locationKey（仅 weather 时保留）
        $isWeather = ($dsType === 'weather');
        $model->data_source_location     = ($dsEnabled && $isWeather) ? trim($src['data_source_location'] ?? '') : '';
        $model->data_source_location_key = ($dsEnabled && $isWeather) ? trim($src['data_source_location_key'] ?? '') : '';

        // F1 外盘直连数据源：{market_id, option_mapping:{yes,no}}
        $isExternal = ($dsType === 'external');
        if ($dsEnabled && $isExternal) {
            $extMarketId = trim($src['external_market_id'] ?? '');
            if ($extMarketId !== '') {
                $model->external_source_config = json_encode([
                    'market_id'      => $extMarketId,
                    'option_mapping' => [
                        'yes' => trim($src['external_option_yes'] ?? ''),
                        'no'  => trim($src['external_option_no'] ?? ''),
                    ],
                ], JSON_UNESCAPED_UNICODE);
            } else {
                $model->external_source_config = null;
            }
        } else {
            $model->external_source_config = null;
        }

        // F2 中间价时间线：前端传 midprice_timeline 数组(<=20)。每条按 data_source_type 分两类：
        //   external → {deadline, data_source_type, data_source_sub_type, platform, external_market_id, option_mapping{yes,no}}
        //   weather  → {deadline, data_source_type, data_source_sub_type, location, location_key}
        // data_source_type 缺省视为 external（向后兼容老前端/老数据）。runtime ResolveMidSource 按此契约解析。
        $timeline = $src['midprice_timeline'] ?? [];
        if (is_array($timeline) && count($timeline) > 0) {
            $rules = [];
            foreach ($timeline as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $dsType = trim($r['data_source_type'] ?? '');
                if ($dsType === '') {
                    $dsType = 'external';
                }
                if ($dsType === 'weather') {
                    $rules[] = [
                        'deadline'             => trim($r['deadline'] ?? ''),
                        'data_source_type'     => 'weather',
                        // 子类型按类型强制：前端时间线把天气的 data_source_sub_type(accuweather) 字段一直挂在 DOM，
                        // 外盘规则会误读到它，故这里按类型定死（外盘/天气当前各只有一个 provider）。
                        'data_source_sub_type' => 'accuweather',
                        'location'             => trim($r['location'] ?? ''),
                        'location_key'         => trim($r['location_key'] ?? ''),
                    ];
                } else {
                    $rules[] = [
                        'deadline'             => trim($r['deadline'] ?? ''),
                        'data_source_type'     => 'external',
                        'data_source_sub_type' => 'polymarket',
                        'platform'             => trim($r['platform'] ?? 'polymarket'),
                        'external_market_id'   => trim($r['external_market_id'] ?? ''),
                        'option_mapping'       => [
                            'yes' => trim($r['option_yes'] ?? ($r['option_mapping']['yes'] ?? '')),
                            'no'  => trim($r['option_no'] ?? ($r['option_mapping']['no'] ?? '')),
                        ],
                    ];
                }
            }
            // 按截止时间升序（runtime 也按此取 deadline>now 最早一条）
            usort($rules, function ($a, $b) {
                return strcmp($a['deadline'], $b['deadline']);
            });
            $model->midprice_timeline = count($rules) > 0 ? json_encode($rules, JSON_UNESCAPED_UNICODE) : null;
        } else {
            $model->midprice_timeline = null;
        }

        // 注：F3 批量铺单控制已在 LMSR 改造中废弃，相关列
        // (is_batch_placement_enabled / batch_limit_mode / batch_share_per_round /
        //  batch_ratio_per_round / batch_replenish_threshold / batch_round_state) 已从
        // market_mapping_config DROP，此处不再写入，否则会触发 "Unknown column" 保存失败。
    }

    /**
     * 查询 AccuWeather GeoPosition（AJAX）
     * 根据经纬度返回 location_key 和地点名称，纯查询不入库
     */
    public function geoposition()
    {
        $lat = input('get.lat', '');
        $lng = input('get.lng', '');
        if (!$lat || !$lng) {
            return json(['code' => 0, 'msg' => '经纬度不能为空']);
        }

        // 本地桩：sentinel /tmp/mis_local_mock 存在时返回 mock location_key，生产不触发。
        if (@file_exists('/tmp/mis_local_mock')) {
            return json(['code' => 1, 'data' => [
                'location_key'  => 'mock-lk-' . substr(md5($lat . ',' . $lng), 0, 8),
                'location_name' => "Mock Location ({$lat},{$lng})",
            ]]);
        }

        $apiKey  = getenv('ACCUWEATHER_API_KEY') ?: '';
        $baseUrl = getenv('ACCUWEATHER_BASE_URL') ?: 'https://dataservice.accuweather.com';
        if (!$apiKey) {
            return json(['code' => 0, 'msg' => 'AccuWeather API Key 未配置']);
        }

        $url = $baseUrl . '/locations/v1/cities/geoposition/search?' . http_build_query([
            'apikey'   => $apiKey,
            'q'        => $lat . ',' . $lng,
            'language' => 'en-us',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err) {
            return json(['code' => 0, 'msg' => '请求 AccuWeather 失败: ' . $err]);
        }
        if ($code !== 200) {
            return json(['code' => 0, 'msg' => "AccuWeather 返回 HTTP {$code}"]);
        }

        $data = json_decode($raw, true);
        if (!$data || empty($data['Key'])) {
            return json(['code' => 0, 'msg' => '无法解析 AccuWeather 响应']);
        }

        $nameParts = array_filter([
            $data['EnglishName']                           ?? '',
            $data['ParentCity']['EnglishName']             ?? '',
            $data['AdministrativeArea']['EnglishName']     ?? '',
            $data['Country']['EnglishName']                ?? '',
        ]);

        return json(['code' => 1, 'data' => [
            'location_key'  => $data['Key'],
            'location_name' => implode(', ', $nameParts),
        ]]);
    }

    /**
     * F1 外盘同步（AJAX）：按外部 Market ID 从 Polymarket 拉两个 option(token) 供前端映射。
     * gamma-api：/markets?id=<id>，返回 clobTokenIds(JSON字符串) + outcomes(JSON字符串)。
     */
    public function syncExternal()
    {
        $id = trim((string)$this->request->param('id', ''));
        if ($id === '') {
            return json(['code' => 0, 'msg' => '外部 Market ID 不能为空']);
        }
        // 本地桩：sentinel /tmp/mis_local_mock 存在时返回 mock 外盘 option，生产不触发。
        if (@file_exists('/tmp/mis_local_mock')) {
            return json(['code' => 1, 'data' => [
                'market_name' => "Mock Poly Market (id={$id})",
                'options'     => [
                    ['token_id' => 'mock-poly-yes-' . $id, 'title' => 'Yes'],
                    ['token_id' => 'mock-poly-no-' . $id,  'title' => 'No'],
                ],
            ]]);
        }
        $base = getenv('POLY_GAMMA_BASE_URL') ?: 'https://gamma-api.polymarket.com';
        $url  = $base . '/markets?id=' . urlencode($id);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err) {
            return json(['code' => 0, 'msg' => '请求 Polymarket 失败: ' . $err]);
        }
        if ($code !== 200) {
            return json(['code' => 0, 'msg' => "Polymarket 返回 HTTP {$code}"]);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || count($data) === 0) {
            return json(['code' => 0, 'msg' => '未找到该 Market，请检查 ID']);
        }
        $market   = $data[0];
        $tokenIds = $market['clobTokenIds'] ?? '[]';
        $outcomes = $market['outcomes'] ?? '[]';
        if (is_string($tokenIds)) {
            $tokenIds = json_decode($tokenIds, true) ?: [];
        }
        if (is_string($outcomes)) {
            $outcomes = json_decode($outcomes, true) ?: [];
        }
        $options = [];
        foreach ($tokenIds as $i => $tid) {
            $options[] = [
                'token_id' => (string)$tid,
                'title'    => $outcomes[$i] ?? ('Option ' . ($i + 1)),
            ];
        }
        if (count($options) === 0) {
            return json(['code' => 0, 'msg' => '该 Market 无可用 option（clobTokenIds 为空）']);
        }
        return json(['code' => 1, 'data' => [
            'market_name' => $market['question'] ?? ($market['slug'] ?? ''),
            'options'     => $options,
        ]]);
    }

    /**
     * F1/F2 预览实时外盘中间价：按 §3.2 优先级(时间线 deadline>now 最早一条 > 主数据源)解析活动源。
     *   - 活动源=外盘 → 实时取 Polymarket 中点(方案A，与 runtime external_polymarket_pricing 一致)。
     *   - 活动源=天气 → 返回 null（PHP 预览不实时取 AccuWeather，调用方回退手填中间价；真实 runtime 会取天气价）。
     * 仅在数据源定价开启(is_data_source_pricing_enabled=on)时生效；未配置/取价失败返回 null。
     * 与 runtime ResolveMidSource 同口径：先看时间线命中规则的类型，再回退主配置类型。
     * @param array $src 该 outcome 的表单数据(array_merge(top, outcome))
     * @return float|null YES 中间价
     */
    private function fetchExternalYesMid(array $src)
    {
        $truthy = function ($v) {
            return $v === 'on' || $v === '1' || $v === 1 || $v === true;
        };
        if (!$truthy($src['is_data_source_pricing_enabled'] ?? null)) {
            return null;
        }

        // 优先级：时间线 deadline(UTC)>now 的最早一条规则（不分类型先选出来，再按其类型决定取价）
        $timeline = $src['midprice_timeline'] ?? [];
        if (is_array($timeline) && count($timeline) > 0) {
            try {
                $nowUtc = new \DateTime('now', new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                $nowUtc = null;
            }
            $bestT = null;
            $bestRule = null;
            foreach ($timeline as $r) {
                if (!is_array($r) || $nowUtc === null) {
                    continue;
                }
                $dl = trim($r['deadline'] ?? '');
                if ($dl === '') {
                    continue;
                }
                try {
                    $t = new \DateTime($dl);
                } catch (\Exception $e) {
                    continue;
                }
                if ($t <= $nowUtc) {
                    continue; // 已过期
                }
                if ($bestT === null || $t < $bestT) {
                    $bestT = $t;
                    $bestRule = $r;
                }
            }
            if ($bestRule !== null) {
                // 命中时间线规则：外盘→取该规则 YES token；天气→null（回退手填中间价）
                $dsType = trim($bestRule['data_source_type'] ?? '');
                if ($dsType === '') {
                    $dsType = 'external';
                }
                if ($dsType !== 'external') {
                    return null;
                }
                $yesToken = trim($bestRule['option_yes'] ?? ($bestRule['option_mapping']['yes'] ?? ''));
                return $yesToken === '' ? null : $this->fetchPolyMidpoint($yesToken);
            }
            // 时间线全过期 → 落到主数据源
        }

        // 主数据源：仅外盘可在预览实时取价；天气回退手填中间价
        if (trim($src['data_source_type'] ?? '') !== 'external') {
            return null;
        }
        if (trim($src['external_market_id'] ?? '') === '') {
            return null;
        }
        $yesToken = trim($src['external_option_yes'] ?? '');
        return $yesToken === '' ? null : $this->fetchPolyMidpoint($yesToken);
    }

    /**
     * 取 Polymarket CLOB token 的盘口中点(best bid/ask 中点)，clamp 到 [0.001,0.999]。
     * @param string $tokenId clobTokenId
     * @return float|null
     */
    private function fetchPolyMidpoint($tokenId)
    {
        $base = getenv('POLY_CLOB_BASE_URL') ?: 'https://clob.polymarket.com';
        $url  = $base . '/midpoint?token_id=' . urlencode($tokenId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $err || $code !== 200) {
            Log::warning("fetchPolyMidpoint failed: token={$tokenId} http={$code} err={$err}");
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['mid'])) {
            return null;
        }
        $mid = (float)$data['mid'];
        if ($mid <= 0) {
            return null;
        }
        if ($mid < 0.001) {
            $mid = 0.001;
        }
        if ($mid > 0.999) {
            $mid = 0.999;
        }
        return $mid;
    }

    /**
     * Generates the token_id_mapping JSON string
     * @param array $outcome The specific outcome array from POST data
     * @param int $outcomeIndex Useful if you want to generate unique IDs based on index
     * @return string JSON string
     */
    private function generateTokenMapping($outcome, $outcomeIndex = 0)
    {
        // If your source data doesn't have IDs yet, we generate placeholders
        // In a real scenario, these might be outcome[0] and outcome[1] or specific hashes
        $yesTokenId = $outcome['yes_token_id'] ?? null;
        $noTokenId  = $outcome['no_token_id']  ?? null;

        $mapping = [
            "yes" => [
                "token_id"   => (string)$yesTokenId,
                "token_name" => "Yes",
                "init_share" => (int)($outcome['yes_shares'] ?? 0)
            ],
            "no" => [
                "token_id"   => (string)$noTokenId,
                "token_name" => "No",
                "init_share" => (int)($outcome['no_shares'] ?? 0)
            ]
        ];

        return json_encode($mapping, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Get the current market table index from configuration
     * @return string The index value (e.g., '03')
     * @throws \Exception If config is missing or DB fails
     */
    private function getMarketTableIndex()
    {
        try {
            // Step 1: Query the specific field 'value' where config_name matches
            $indexValue = Db::name('run_config')
                ->where('config_name', 'market_table_index')
                ->value('value');

            // Step 2: Error handling for missing record
            if ($indexValue === null) {
                Log::error("Configuration 'market_table_index' missing in run_config table.");
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
     * Sync Market Data
     * This method is called by the JavaScript 'fetch' request
     */
    public function sync()
    {
        // 1. Get the Market ID from the request (GET or POST)
        $marketId = $this->request->param('id');

        if (empty($marketId)) {
            return json(['code' => 0, 'msg' => 'Market ID cannot be empty']);
        }

        // 本地无 Foregate 时的桩：仅当 sentinel 文件 /tmp/mis_local_mock 存在时生效。
        // 该文件只在本地容器手动 touch，生产环境永不存在 → 此分支生产绝不触发，留仓库安全。
        if (@file_exists('/tmp/mis_local_mock')) {
            return json([
                'code' => 1,
                'msg'  => 'Sync successful (LOCAL MOCK)',
                'data' => $this->getMockSyncData($marketId),
            ]);
        }

        try {
            // 2. Simulate fetching data (Replace this with your actual logic)
            // In a real scenario, you might use $this->fetchFromApi($marketId)
            $data = $this->getSyncData($marketId);

            if (!$data) {
                return json(['code' => 0, 'msg' => 'Market data not found']);
            }

            // 3. Log the success (Helpful for your audit trail)
            Log::info("Market {$marketId} synced successfully.");

            // 4. Return the standard JSON format expected by your JS
            return json([
                'code' => 1,
                'msg'  => 'Sync successful',
                'data' => $data
            ]);

        } catch (\Exception $e) {
            Log::error("Sync Error: " . $e->getMessage());
            return json(['code' => 0, 'msg' => 'Server error during sync']);
        }
    }

    /**
     * 本地 mock 同步数据（结构与 getSyncData 输出一致），供无 Foregate 环境查看 UI 效果。
     * 仅被 sentinel 守卫的 sync() 分支调用，生产环境不会触发。
     */
    private function getMockSyncData($id)
    {
        // 结构与 getSyncData() 输出逐字段对齐，renderSyncData() 直接消费
        return [
            'market_name' => "2026 NBA Finals Champion (mock id={$id})",
            'start_time'  => '2026-06-01 10:00:00',
            'end_time'    => '2026-06-30 18:00:00',
            'settle_time' => '2026-07-01 08:00:00',
            'outcomes'    => [
                [
                    'title'        => 'Boston Celtics win the title',
                    'yes_shares'   => 18000,
                    'yes_price'    => 0.520,
                    'yes_title'    => 'Yes',
                    'yes_token_id' => 'mock-outcome-1-yes',
                    'no_shares'    => 18000,
                    'no_price'     => 0.480,
                    'no_title'     => 'No',
                    'no_token_id'  => 'mock-outcome-1-no',
                    'outcome_id'   => 'mock-outcome-1',
                ],
                [
                    'title'        => 'Oklahoma City Thunder win the title',
                    'yes_shares'   => 14000,
                    'yes_price'    => 0.350,
                    'yes_title'    => 'Yes',
                    'yes_token_id' => 'mock-outcome-2-yes',
                    'no_shares'    => 14000,
                    'no_price'     => 0.650,
                    'no_title'     => 'No',
                    'no_token_id'  => 'mock-outcome-2-no',
                    'outcome_id'   => 'mock-outcome-2',
                ],
                [
                    'title'        => 'Field (any other team)',
                    'yes_shares'   => 9000,
                    'yes_price'    => 0.130,
                    'yes_title'    => 'Yes',
                    'yes_token_id' => 'mock-outcome-3-yes',
                    'no_shares'    => 9000,
                    'no_price'     => 0.870,
                    'no_title'     => 'No',
                    'no_token_id'  => 'mock-outcome-3-no',
                    'outcome_id'   => 'mock-outcome-3',
                ],
            ],
        ];
    }

    
    /**
     * Fetch market data from Foregate and format it for the frontend
     * @param int|string $marketId
     * @return array
     */
    private function getSyncData($marketId)
    {
       // 本地桩：sentinel 存在时返回 mock 同步数据（含 outcome_id/token），无 Foregate 也能跑通保存。生产不触发。
       if (@file_exists('/tmp/mis_local_mock')) {
           return $this->getMockSyncData($marketId);
       }
       $fetchResult = $this->fetchFgOutcomes($marketId);
            if ($fetchResult['status'] === 'error') {
                return json(['code' => 0, 'msg' => 'Failed to fetch market data: ' . $fetchResult['msg']]);
            }
        
        $preview = $fetchResult['market_mapping_preview'] ?? [];
        $rawOutcomes = $fetchResult['outcomes'] ?? [];
        $formattedOutcomes = [];

        // Loop through each outcome in the fetch result
        foreach ($rawOutcomes as $outcome) {
            // Options: 0 is usually Yes, 1 is usually No in binary markets
            $yesOption = $outcome['options'][0] ?? [];
            $noOption  = $outcome['options'][1] ?? [];

            $formattedOutcomes[] = [
                'title'      => $outcome['name'] ?? ($outcome['title'] ?? 'Unknown Outcome'),
                'yes_shares' => (float)($yesOption['share'] ?? 0),
                'yes_price'  => 0.500, // Default price if not provided
                'yes_title'  => $yesOption['title'] ?? 'Yes',
                'yes_token_id' => $yesOption['optionId'] ?? null,
                'no_shares'  => (float)($noOption['share'] ?? 0),
                'no_price'   => 0.500,  // Default price if not provided
                'no_title'   => $noOption['title'] ?? 'No',
                'no_token_id' => $noOption['optionId'] ?? null,
                'outcome_id' => $outcome['outcomeId'] ?? null,
            ];
            //Log::info("Formatted outcome: " . json_encode(end($formattedOutcomes)));
        }

        // Return the final data structure
        return [
            'market_name' => ($preview['title'] ?? 'Unknown Market'),
            'start_time'  => $preview['startTime'] ?? date('Y-m-d H:i:s'),
            'end_time'    => $preview['endTime'] ?? date('Y-m-d H:i:s'),
            // Setting settle_time same as end_time if not provided in JSON
            'settle_time' => '', 
            'outcomes'    => $formattedOutcomes
        ];
        

    }


    /**
     * Login Foregate using the provided FgMarketId and retrieves outcomes 
     * @param int|string $marketId
     * @return \think\response\Json
     */
    private function fetchFgOutcomes(string $marketId)
    {
        Log::info("Step : Starting fetchFgOutcomes for ID: " . $marketId);

        $payload = ['market_id' => $marketId];
        $jsonData = base64_encode(json_encode($payload));
    
        try {
            $output = Console::call('fetch:outcomes', [$jsonData]);
            $rawResponse = trim($output->fetch()); 
            
            if (empty($rawResponse)) {
                Log::error("Step Fail: Python returned no data");
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
            Log::error("Step Critical Exception: " . $e->getMessage());
            return ['status' => 'error', 'msg' => $e->getMessage()];
        }
    }


    // Change type hint from string to array
    /**
     * Fetch order layers from Python script
     * @param array $data
     * @return array
     */
    private function fetchOrderLayers(array $data)
    {
        // 1. Convert the array to a JSON string first
        $jsonString = json_encode($data);
        
        Log::info("Step : Starting fetchOrderLayers with data: " . $jsonString);

        // 2. Base64 encode the JSON string for the Console call
        $base64Data = base64_encode($jsonString);

        try {
            // Pass the base64 string to your custom ThinkPHP command
            $output = Console::call('fetch:mmapi', [$base64Data]);
            $rawResponse = trim($output->fetch()); 
            
            if (empty($rawResponse)) {
                Log::error("Step Fail: Python returned no data");
                return ['code' => 500, 'message' => 'Python returned no data'];
            }

            // Extract JSON portion
            $start = strpos($rawResponse, '{');
            $end = strrpos($rawResponse, '}');

            if ($start === false || $end === false) {
                return ['code' => 500, 'message' => 'No valid JSON found in response'];
            }

            $cleanJson = substr($rawResponse, $start, $end - $start + 1);
            $result = json_decode($cleanJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['code' => 500, 'message' => 'JSON parse error: ' . json_last_error_msg()];
            }

            // Ensure the return structure matches your check (using 'code')
            return $result;

        } catch (\Exception $e) {
            Log::error("Step Critical Exception: " . $e->getMessage());
            return ['code' => 500, 'message' => $e->getMessage()];
        }
    }

    private function getMockOutcome()
    {
        return [
            "status" => "success",
            "market_mapping_preview" => [
                "startTime" => "2026-02-24 10:32:28",
                "endTime" => "2026-12-19 12:45:59",
                "title" => "orderbook_test001",
                "TargetGroupId" => 2588,
                "TargetCoinId" => 1,
                "TargetStatus" => "ACTIVE"
            ],
            "outcomes" => [
                [
                    "outcomeId" => 4383,
                    "title" => "Yes / No",
                    "name" => "停火预测2025",
                    "chance" => 58.0,
                    "outcomeLogo" => "https://polymarket-upload.s3.us-east-2.amazonaws.com/russia-x-ukraine-ceasefire-before-july-GSNGh26whPic.jpg",
                    "description" => "[创建测试] 停火预测2025 二元结果，验证 outcomes & options 完整保存",
                    "options" => [
                        [
                            "optionId" => 10081,
                            "title" => "Yes（是）",
                            "chance" => 58.0,
                            "price" => 0.58,
                            "priceBuy" => 0.21,
                            "priceSell" => 0.95,
                            "isWinFirst" => null,
                            "isWin" => null,
                            "optionIndex" => 0,
                            "share" => "2000000.00000000"
                        ],
                        [
                            "optionId" => 10082,
                            "title" => "No（否）",
                            "chance" => 58.0,
                            "price" => 0.58,
                            "priceBuy" => 0.21,
                            "priceSell" => 0.95,
                            "isWinFirst" => null,
                            "isWin" => null,
                            "optionIndex" => 1,
                            "share" => "3000000.00000000"
                        ]
                    ]
                ],
                [
                    "outcomeId" => 4384,
                    "title" => "Yes / No",
                    "name" => "停火预测2026",
                    "chance" => 58.0,
                    "outcomeLogo" => "https://polymarket-upload.s3.us-east-2.amazonaws.com/russia-x-ukraine-ceasefire-before-july-GSNGh26whPic.jpg",
                    "description" => "[创建测试] 停火预测2026 二元结果，验证 outcomes & options 完整保存",
                    "options" => [
                        [
                            "optionId" => 10083,
                            "title" => "Yes（是）",
                            "chance" => 58.0,
                            "price" => 0.58,
                            "priceBuy" => 0.21,
                            "priceSell" => 0.95,
                            "isWinFirst" => null,
                            "isWin" => null,
                            "optionIndex" => 0,
                            "share" => "3000000.00000000"
                        ],
                        [
                            "optionId" => 10084,
                            "title" => "No（否）",
                            "chance" => 58.0,
                            "price" => 0.58,
                            "priceBuy" => 0.21,
                            "priceSell" => 0.95,
                            "isWinFirst" => null,
                            "isWin" => null,
                            "optionIndex" => 1,
                            "share" => "4000000.00000000"
                        ]
                    ]
                ]
            ]
        ];
    }


    //preview for add() function
    public function preview()
    {
        $post = $this->request->post();
        
        if (empty($post['market_id'])) {
            return json(['code' => 0, 'msg' => 'Market ID is required']);
        }

        Log::info('Preview method called with data: ' . json_encode($post));

        //add mock data to test the flow before integrating with real Foregate data
        //$mockData = $this->getMockOutcome();
        try {
            // 1. Prepare data for the Python MM API    //needs validation  to fix
            $mmapi_payload = [];
            $effMid = []; // F1/F2 预览实时外盘中间价：title => ['yes'=>..,'no'=>..]
            foreach ($post['outcomes'] as $outcome) {
                // F4：铺单参数优先取 outcome 级，回退 top-level（向后兼容）
                $title  = $outcome['title'];
                $yesMid = $outcome['yes_mid_price'];
                $noMid  = $outcome['no_mid_price'] ?? null;
                // F1/F2：外盘数据源开启时用实时 Poly 中点覆盖；取价失败回退表单值
                $extYes = $this->fetchExternalYesMid(array_merge($post, $outcome));
                if ($extYes !== null) {
                    $yesMid = number_format($extYes, 6, '.', '');
                    $noMid  = number_format(1 - $extYes, 6, '.', '');
                }
                $effMid[$title] = ['yes' => $yesMid, 'no' => $noMid];
                // LMSR 曲线预览：p_star=YES 公允概率(NO 侧由 Go 镜像推出)，份额由曲线 size=b·logit差 决定(与预算无关)。
                $mmapi_payload[] = [
                    "outcome_name" => $title,
                    "p_star"       => (string)$yesMid,
                    "spread"       => (string)($outcome['base_spread'] ?? $post['base_spread'] ?? '0.04'),
                    "depth_b"      => (string)($outcome['depth_b'] ?? $post['depth_b'] ?? '500'),
                    "g0"           => (string)($outcome['g0'] ?? $post['g0'] ?? '0.008'),
                    "slope"        => (string)($outcome['slope'] ?? $post['slope'] ?? '0.25'),
                ];
            }

            // 2. Call your Python script via the existing fetchOrderLayers method
            // This method handles the Base64 encoding and JSON extraction
            $calcResult = $this->fetchOrderLayers($mmapi_payload);

            Log::info('MM Engine calculation result: ' . json_encode($calcResult));

            if (!isset($calcResult['code']) || $calcResult['code'] != 200) {
                return json(['code' => 0, 'msg' => 'MM Engine Error: ' . ($calcResult['message'] ?? 'Unknown error')]);
            }

            $gridData = $calcResult['data'];
            Log::info('MM Engine calculation successful, grid data: ' . json_encode($gridData));

            // 3. Reformat the Python result to match your Frontend's expected structure
            $outcomesPreview = [];
            foreach ($post['outcomes'] as $outcome) {
                $title = $outcome['title'];
                // Access the specific outcome data from Python result (keyed by outcome_name)
                $outcomeGrid = $gridData[$title] ?? null;

                if ($outcomeGrid) {
                    $outcomesPreview[] = [
                        'title'   => $title,
                        'options' => [
                            'Yes' => [
                                'shares'    => $outcome['yes_shares'],
                                'mid_price' => $effMid[$title]['yes'] ?? $outcome['yes_mid_price'],
                                'grid'      => [
                                    'asks' => $this->mapLayers($outcomeGrid['yes_ask']),
                                    'bids' => $this->mapLayers($outcomeGrid['yes_bid'])
                                ]
                            ],
                            'No' => [
                                'shares'    => $outcome['no_shares'],
                                'mid_price' => $effMid[$title]['no'] ?? $outcome['no_mid_price'],
                                'grid'      => [
                                    'asks' => $this->mapLayers($outcomeGrid['no_ask']),
                                    'bids' => $this->mapLayers($outcomeGrid['no_bid'])
                                ]
                            ]
                        ]
                    ];
                }
            }

            return json([
                'code' => 1,
                'data' => [
                    'meta'    => $post,
                    'preview' => $outcomesPreview
                ]
            ]);

        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Helper to map Python layer names to Frontend keys
     */
    private function mapLayers($layers)
    {
        return array_map(function($l) {
            return [
                'level'  => $l['layer_no'],
                'price'  => $l['price'],
                'amount' => $l['share']
            ];
        }, $layers);
    }

    //preview for edit() function
    public function previewEdit()
    {
        $post = $this->request->post();
        
        if (empty($post['market_id'])) {
            return json(['code' => 0, 'msg' => 'Market ID is required']);
        }

        $syncData = $this->getSyncData($post['market_id']);

        Log::info('Preview method called with data: ' . json_encode($post));

        //add mock data to test the flow before integrating with real Foregate data
        //$mockData = $this->getMockOutcome();
        try {
            // 1. Prepare data for the Python MM API    //needs validation  to fix
            $mmapi_payload = [];
            $effMid = []; // F1/F2 预览实时外盘中间价：title => ['yes'=>..,'no'=>..]
            foreach ($post['outcomes'] as $index => $outcome) {
                $title  = $syncData['outcomes'][$index]['title'];
                $yesMid = $outcome['yes_mid_price'];
                $noMid  = $outcome['no_mid_price'] ?? null;
                // F1/F2：外盘数据源开启时用实时 Poly 中点覆盖；取价失败回退表单值
                $extYes = $this->fetchExternalYesMid(array_merge($post, $outcome));
                if ($extYes !== null) {
                    $yesMid = number_format($extYes, 6, '.', '');
                    $noMid  = number_format(1 - $extYes, 6, '.', '');
                }
                $effMid[$title] = ['yes' => $yesMid, 'no' => $noMid];
                // LMSR 曲线预览：p_star=YES 公允概率(NO 侧由 Go 镜像推出)，份额由曲线决定(与预算无关)。
                $mmapi_payload[] = [
                    "outcome_name" => $title,                       //from sync data
                    "p_star"       => (string)$yesMid,              //外盘实时优先，回退表单
                    "spread"       => (string)$post['base_spread'], //from form input
                    "depth_b"      => (string)$post['depth_b'],     //from form input
                    "g0"           => (string)$post['g0'],          //from form input
                    "slope"        => (string)$post['slope'],       //from form input
                ];
            }

            // 2. Call your Python script via the existing fetchOrderLayers method
            // This method handles the Base64 encoding and JSON extraction
            $calcResult = $this->fetchOrderLayers($mmapi_payload);

            Log::info('MM Engine calculation result: ' . json_encode($calcResult));

            if (!isset($calcResult['code']) || $calcResult['code'] != 200) {
                return json(['code' => 0, 'msg' => 'MM Engine Error: ' . ($calcResult['message'] ?? 'Unknown error')]);
            }

            $gridData = $calcResult['data'];
            Log::info('MM Engine calculation successful, grid data: ' . json_encode($gridData));

            // 3. Reformat the Python result to match your Frontend's expected structure
            $outcomesPreview = [];
            foreach ($post['outcomes'] as $index => $outcome) {
                $title = $syncData['outcomes'][$index]['title'];
                // Access the specific outcome data from Python result (keyed by outcome_name)
                $outcomeGrid = $gridData[$title] ?? null;

                if ($outcomeGrid) {
                    $outcomesPreview[] = [
                        'title'   => $title,
                        'options' => [
                            'Yes' => [
                                'shares'    => $syncData['outcomes'][$index]['yes_shares'],
                                'mid_price' => $effMid[$title]['yes'] ?? $outcome['yes_mid_price'],
                                'grid'      => [
                                    'asks' => $this->mapLayers($outcomeGrid['yes_ask']),
                                    'bids' => $this->mapLayers($outcomeGrid['yes_bid'])
                                ]
                            ],
                            'No' => [
                                'shares'    => $syncData['outcomes'][$index]['no_shares'],
                                'mid_price' => $effMid[$title]['no'] ?? $outcome['no_mid_price'],
                                'grid'      => [
                                    'asks' => $this->mapLayers($outcomeGrid['no_ask']),
                                    'bids' => $this->mapLayers($outcomeGrid['no_bid'])
                                ]
                            ]
                        ]
                    ];
                }
            }

            return json([
                'code' => 1,
                'data' => [
                    'meta'    => $post,
                    'preview' => $outcomesPreview
                ]
            ]);

        } catch (\Exception $e) {
            return json(['code' => 0, 'msg' => $e->getMessage()]);
        }
    }




    public function edit()
    {
        // 1. Core Identification
        $id = $this->request->get('id') ?: $this->request->post('id');
        $model = MarketMappingConfigModel::where('id', $id)->find();

        if (!$model) {
            return json(['code' => 0, 'msg' => '未找到该配置记录']);
        }

        if ($this->request->isPost()) {
            $data = $this->request->post();

            try {
                // -- Mapping fields directly to the model per database schema --
                // 1. Collect Data & Convert Percentages
                // We convert them before validation so we can check the decimal values (0.05)
                $base_spread = (float)$data['base_spread'];
                $depth_b     = (int)$data['depth_b'];
                $g0          = (float)$data['g0'];
                $slope       = (float)$data['slope'];
                $mid_price_yes = (float)$data['mid_price_yes'];
                $mid_price_no  = (float)$data['mid_price_no'];
                $stock_limit = (int)($data['stock_limit'] ?? 0); // Assuming this is a percentage like 20 for 20%

                // 2. Define Validation Rules
                $rules = [
                'base_spread'      => 'require|float|between:0.001,0.999',
                'depth_b'          => 'require|integer|between:50,10000000',
                'g0'               => 'require|float|between:0.001,0.5',
                'slope'            => 'require|float|between:0,1',
                'mid_price_yes'    => 'require|float|between:0,1',
                'mid_price_no'     => 'require|float|between:0,1',
                'stock_limit'      => 'integer|between:0,100',
                'reprice_stale_seconds' => 'require|integer|between:0,3600',
                'market_status'    => 'require|in:PENDING,ACTIVE,CLOSE,CLOSED',
            ];

                // 2. Define Custom Error Messages
                $messages = [
                    'base_spread.require'      => '基础价差不能为空',
                    'base_spread.between'      => '基础价差必须在0.001到0.999之间',

                    'depth_b.require'          => '深度参数 b 不能为空',
                    'depth_b.integer'          => '深度参数 b 必须是整数',
                    'depth_b.between'          => '深度参数 b 必须在 50 到 10000000 之间',

                    'g0.require'               => 'g0 不能为空',
                    'g0.between'               => 'g0 必须在 0.001 到 0.5 之间',

                    'slope.require'            => 'slope 不能为空',
                    'slope.between'            => 'slope 必须在 0 到 1 之间',

                    'mid_price_yes.require'    => '"Yes"选项的概率 P* 不能为空',
                    'mid_price_yes.between'    => '"Yes"选项的概率 P* 必须在0和1之间',

                    'mid_price_no.require'     => '"No"选项的概率 P* 不能为空',
                    'mid_price_no.between'     => '"No"选项的概率 P* 必须在0和1之间',

                    'stock_limit.integer'       => '库存偏差限额必须是整数',
                    'stock_limit.between'      => '库存偏差限额必须在0到100之间',

                    'reprice_stale_seconds.require' => '调价触发兜底时间不能为空',
                    'reprice_stale_seconds.integer' => '调价触发兜底时间必须是整数',
                    'reprice_stale_seconds.between' => '调价触发兜底时间必须在0到3600秒之间',

                    'market_status.require'    => '请选择市场状态',
                ];

                // 3. Initialize Validator
                $validate = \think\facade\Validate::rule($rules)->message($messages);

                // We validate the $data array directly from the request
                if (!$validate->check($data)) {
                    return json(['code' => 0, 'msg' => $validate->getError()]);
                }

                // 5. If Pass, Assign to Model（LMSR sigmoid 曲线；旧多层列不再写入，保留 DB 原值）
                $model->base_spread           = $base_spread;
                $model->depth_b               = $depth_b;
                $model->g0                    = $g0;
                $model->slope                 = $slope;
                $model->mid_price_yes         = $mid_price_yes;
                $model->mid_price_no          = $mid_price_no;
                $model->reprice_stale_seconds = (int)($data['reprice_stale_seconds'] ?? 60);

                $model->is_position_limit_enabled = (isset($data['single_side_limit']) && $data['single_side_limit'] === 'on') ? 1 : 0;
                $model->is_spread_dynamic_enabled = (isset($data['dynamic_spread']) && $data['dynamic_spread'] === 'on') ? 1 : 0;
                $model->inventory_deviation_threshold = $stock_limit/100;

                // 数据源(天气/外盘) + F1外盘直连 + F2时间线（F3批量铺单已废弃，不再写入）
                $this->validateOutcomeConfig($data, $data['settle_time'] ?? '', $data['end_time'] ?? '', '');
                $this->applyOutcomeDataSourceAndBatch($model, $data);

                $result = $model->save();

                // If result is true (saved) OR no data was changed (not dirty)
                if ($result !== false) {
                    Log::info("edit.config.success: id[{$model->id}] updated.");
                    // 列表是服务端 {foreach} 静态渲染（非 layui 异步表）。弹层 iframe 内的 $.form.reload() 只会重载
                    // 弹层自己、不刷新父列表，故用 javascript:parent.location.reload() 刷新父列表页（≈手动 F5，工具栏完整）。
                    return json([
                        'code' => 1,
                        'msg'  => '配置更新成功',
                        // 优先用父页 ThinkAdmin 的 pjax 轻量刷新列表内容区（不整页闪）；拿不到再回退整页 reload
                        'url'  => 'javascript:(parent.$&&parent.$.form)?parent.$.form.reload():parent.location.reload()',
                    ]);
                }

                return json(['code' => 0, 'msg' => '保存失败，请重试']);

            } catch (\Exception $e) {
                Log::error("edit.config.error: " . $e->getMessage());
                return json(['code' => 0, 'msg' => '更新失败: ' . $e->getMessage()]);
            }
        }

        // Pass data to the view
        return $this->fetch('', ['vo' => $model]); 
    }


    private function getAccountID(){
        $accountId = Db::name('run_config')
            ->where('config_name', 'ws_foregate_account_ids')
            ->value('value');

        if ($accountId === null) {
            Log::error("Configuration 'fg_account_id' missing in run_config table.");
            throw new \Exception("系统配置错误：找不到 fg_account_id 记录");
        }

        return (string)$accountId;
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
            'CLOSE' => 'CLOSE',
            'CLOSED' => 'CLOSED',
            'ARCHIVED' => 'ARCHIVED'
        ];
    }

  

}
