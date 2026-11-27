<?php
declare (strict_types=1);

namespace app\cool_pay\controller;

use app\cool_pay\model\RebalanceConfig as RebalanceConfigModel;
use app\cool_pay\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;
use think\facade\Log;

/**
 * 调仓配置管理
 */
class RebalanceConfig extends Controller
{
    use SafeQueryTrait;
    /**
     * 调仓配置列表
     * @auth true
     * @menu true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $this->title = '调仓配置';

        // 记录日志
        $this->app->log->info('RebalanceConfig index method called');

        // 获取请求参数
        $get = $this->request->get();
        $this->app->log->info('Request parameters: ' . json_encode($get));

        $query = Db::name('rebalance_config');
        $this->app->log->info('Query object created');

        // 添加搜索条件
        if (isset($get['id']) && $get['id'] !== '') {
            $query->where('id', $get['id']);
            $this->app->log->info('Added id condition: ' . $get['id']);
        }
        if (isset($get['config_key']) && $get['config_key'] !== '') {
            $key = $this->sanitizeLike($get['config_key']);
            $query->where('config_key', 'like', "%{$key}%");
            $this->app->log->info('Added config_key condition: ' . $key);
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
        $result = $query->order('id asc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page' => isset($get['page']) ? intval($get['page']) : 1,
            'query' => $get,
            'path' => '/admin.html#/cool_pay/rebalance_config/index.html',
        ], false);
        $this->app->log->info('Pagination query executed, total: ' . $result->total());

        // 格式化数据
        $list = $result->items();

        foreach ($list as &$item) {
            // 处理NULL值
            $item['config_desc'] = $item['config_desc'] ?? '';
            
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
            'statuses' => $this->getStatuses()
        ]);
        $this->app->log->info('Variables assigned to view');

        // 渲染视图
        $this->app->log->info('Rendering view');
        return $this->fetch();
    }

    /**
     * 添加调仓配置
     * @auth true
     */
    public function add()
    {
        Log::info('RebalanceConfig add method called');
        
        if ($this->request->isPost()) {
            Log::info('RebalanceConfig add POST request received');
            
            $data = $this->request->post();
            Log::info('Form data: ' . json_encode($data));
            
            // 数据验证
            $validate = \think\facade\Validate::rule([
                'config_key' => 'require|max:100',
                'config_value' => 'require',
                'config_desc' => 'max:255',
                'status' => 'in:0,1'
            ])->message([
                'config_key.require' => '配置键名不能为空',
                'config_key.max' => '配置键名最多100个字符',
                'config_value.require' => '配置值不能为空',
                'config_desc.max' => '配置描述最多255个字符',
                'status.in' => '状态必须是0或1'
            ]);
            
            if (!$validate->check($data)) {
                $error = $validate->getError();
                Log::error('Validation error: ' . $error);
                return $this->error($error);
            }
            
            try {
                // 检查配置键名是否已存在
                $exists = Db::name('rebalance_config')
                    ->where('config_key', $data['config_key'])
                    ->find();
                if ($exists) {
                    return $this->error('该配置键名已存在');
                }
                
                // 设置默认字段
                $data['config_desc'] = isset($data['config_desc']) ? $data['config_desc'] : '';
                $data['status'] = isset($data['status']) ? intval($data['status']) : 1;
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['updated_at'] = date('Y-m-d H:i:s');
                
                // 插入数据
                $result = Db::name('rebalance_config')->insert($data);
                Log::info('Insert result: ' . ($result ? 'success' : 'failed'));
                
                if ($result) {
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
        
        $this->assign([
            'statuses' => $this->getStatuses()
        ]);
        
        return $this->fetch('form');
    }

    /**
     * 编辑调仓配置
     * @auth true
     */
    public function edit()
    {
        Log::info('RebalanceConfig edit method called');
        
        if ($this->request->isPost()) {
            Log::info('RebalanceConfig edit POST request received');
            
            $data = $this->request->post();
            Log::info('Form data: ' . json_encode($data));
            
            // 数据验证
            $validate = \think\facade\Validate::rule([
                'id' => 'require|integer|gt:0',
                'config_key' => 'require|max:100',
                'config_value' => 'require',
                'config_desc' => 'max:255',
                'status' => 'in:0,1'
            ])->message([
                'id.require' => 'ID不能为空',
                'id.integer' => 'ID必须是整数',
                'id.gt' => 'ID必须大于0',
                'config_key.require' => '配置键名不能为空',
                'config_key.max' => '配置键名最多100个字符',
                'config_value.require' => '配置值不能为空',
                'config_desc.max' => '配置描述最多255个字符',
                'status.in' => '状态必须是0或1'
            ]);
            
            if (!$validate->check($data)) {
                $error = $validate->getError();
                Log::error('Validation error: ' . $error);
                return $this->error($error);
            }
            
            try {
                // 检查配置键名是否已存在（排除当前记录）
                $exists = Db::name('rebalance_config')
                    ->where('config_key', $data['config_key'])
                    ->where('id', '<>', $data['id'])
                    ->find();
                if ($exists) {
                    return $this->error('该配置键名已存在');
                }
                
                // 处理数据
                $updateData = [
                    'config_key' => $data['config_key'],
                    'config_value' => $data['config_value'],
                    'config_desc' => isset($data['config_desc']) ? $data['config_desc'] : '',
                    'status' => isset($data['status']) ? intval($data['status']) : 1,
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                // 更新数据
                $result = Db::name('rebalance_config')
                    ->where('id', $data['id'])
                    ->update($updateData);
                Log::info('Update result: ' . ($result !== false ? 'success' : 'failed'));
                
                if ($result !== false) {
                    return json(['code' => 1, 'info' => '更新成功', 'url' => '']);
                } else {
                    return $this->error('更新失败或配置不存在');
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
        
        $vo = Db::name('rebalance_config')->where('id', $id)->find();
        if (empty($vo)) {
            return $this->error('配置不存在');
        }
        
        $this->assign([
            'vo' => $vo,
            'statuses' => $this->getStatuses()
        ]);
        return $this->fetch('form');
    }

    /**
     * 删除调仓配置
     * @auth true
     */
    public function remove()
    {
        Log::info('RebalanceConfig remove method called');
        
        $id = $this->request->post('id');
        if (empty($id)) {
            return $this->error('参数错误');
        }
        
        try {
            $result = Db::name('rebalance_config')->where('id', $id)->delete();
            Log::info('Delete result: ' . ($result ? 'success' : 'failed'));
            
            if ($result) {
                return json(['code' => 1, 'info' => '删除成功']);
            } else {
                return $this->error('删除失败或配置不存在');
            }
        } catch (\Exception $e) {
            Log::error('Exception in remove method: ' . $e->getMessage());
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取状态选项
     * @return array
     */
    private function getStatuses()
    {
        return [
            '1' => '启用',
            '0' => '禁用'
        ];
    }
}

