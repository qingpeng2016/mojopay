<?php
declare (strict_types=1);

namespace app\cool_pay\controller;

use app\cool_pay\model\ConfigTrade as ConfigTradeModel;
use app\cool_pay\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;
use think\facade\Log;

/**
 * 交易配置管理
 */
class ConfigTrade extends Controller
{
    use SafeQueryTrait;
    /**
     * 交易配置列表
     * @auth true
     * @menu true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function index()
    {
        $this->title = '交易配置';

        // 记录日志
        $this->app->log->info('ConfigTrade index method called');

        // 获取请求参数
        $get = $this->request->get();
        $this->app->log->info('Request parameters: ' . json_encode($get));

        $query = Db::name('config_trade');
        $this->app->log->info('Query object created');

        // 添加搜索条件
        if (isset($get['id']) && $get['id'] !== '') {
            $query->where('id', $get['id']);
            $this->app->log->info('Added id condition: ' . $get['id']);
        }
        if (isset($get['config_type']) && $get['config_type'] !== '') {
            $query->where('config_type', $get['config_type']);
            $this->app->log->info('Added config_type condition: ' . $get['config_type']);
        }
        if (isset($get['config_name']) && $get['config_name'] !== '') {
            $name = $this->sanitizeLike($get['config_name']);
            $query->where('config_name', 'like', "%{$name}%");
            $this->app->log->info('Added config_name condition: ' . $name);
        }
        if (isset($get['description']) && $get['description'] !== '') {
            $desc = $this->sanitizeLike($get['description']);
            $query->where('description', 'like', "%{$desc}%");
            $this->app->log->info('Added description condition: ' . $desc);
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
            'path' => '/admin.html#/cool_pay/config_trade/index.html', // 使用正确的路径
        ], false);
        $this->app->log->info('Pagination query executed, total: ' . $result->total());

        // 格式化数据
        $list = $result->items();

        foreach ($list as &$item) {
            // 处理NULL值，避免htmlentities错误
            $item['description'] = $item['description'] ?? '';
            $item['value'] = $item['value'] ?? '';
            
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
            'config_types' => $this->getConfigTypes()
        ]);
        $this->app->log->info('Variables assigned to view');

        // 渲染视图
        $this->app->log->info('Rendering view');
        return $this->fetch();
    }

    /**
     * 添加交易配置
     * @auth true
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function add()
    {
        // 记录日志
        Log::info('ConfigTrade add method called');
        
        // 如果是POST请求，处理表单提交
        if ($this->request->isPost()) {
            // 记录日志
            Log::info('ConfigTrade add POST request received');
            
            // 获取表单数据
            $data = $this->request->post();
            Log::info('Form data: ' . json_encode($data));
            
            // 数据验证
            $validate = \think\facade\Validate::rule([
                'config_type' => 'require|in:trading,hedge,order',
                'config_name' => 'require|max:100',
                'description' => 'max:255',
                'value' => 'require|max:500'
            ])->message([
                'config_type.require' => '配置类型不能为空',
                'config_type.in' => '配置类型必须是trading、hedge或order',
                'config_name.require' => '配置名称不能为空',
                'config_name.max' => '配置名称最多100个字符',
                'description.max' => '配置描述最多255个字符',
                'value.require' => '配置值不能为空',
                'value.max' => '配置值最多500个字符'
            ]);
            
            // 验证数据
            if (!$validate->check($data)) {
                $error = $validate->getError();
                Log::error('Validation error: ' . $error);
                return $this->error($error);
            }
            
            try {
                // 检查配置名称是否已存在
                $exists = Db::name('config_trade')->where('config_name', $data['config_name'])->find();
                if ($exists) {
                    return $this->error('配置名称已存在');
                }
                
                // 设置默认字段
                $data['description'] = isset($data['description']) ? $data['description'] : '';
                $data['created_at'] = date('Y-m-d H:i:s');
                $data['updated_at'] = date('Y-m-d H:i:s');
                
                // 插入数据
                $result = Db::name('config_trade')->insert($data);
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
        
        // 分配变量到视图（简化版，不需要传递枚举数据）
        $this->assign(['config_types' => $this->getConfigTypes()]);
        
        // 渲染添加表单
        return $this->fetch('form');
    }

    /**
     * 编辑交易配置
     * @auth true
     */
    public function edit()
    {
        Log::info('ConfigTrade edit method called');
        
        if ($this->request->isPost()) {
            Log::info('ConfigTrade edit POST request received');
            
            // 获取表单数据
            $data = $this->request->post();
            Log::info('Form data: ' . json_encode($data));
            
            // 数据验证
            $validate = \think\facade\Validate::rule([
                'id' => 'require|integer|gt:0',
                'config_type' => 'require|in:trading,hedge,order',
                'config_name' => 'require|max:100',
                'description' => 'max:255',
                'value' => 'require|max:500'
            ])->message([
                'id.require' => 'ID不能为空',
                'id.integer' => 'ID必须是整数',
                'id.gt' => 'ID必须大于0',
                'config_type.require' => '配置类型不能为空',
                'config_type.in' => '配置类型必须是trading、hedge或order',
                'config_name.require' => '配置名称不能为空',
                'config_name.max' => '配置名称最多100个字符',
                'description.max' => '配置描述最多255个字符',
                'value.require' => '配置值不能为空',
                'value.max' => '配置值最多500个字符'
            ]);
            
            if (!$validate->check($data)) {
                $error = $validate->getError();
                Log::error('Validation error: ' . $error);
                return $this->error($error);
            }
            
            try {
                // 检查配置名称是否已存在（排除当前记录）
                $exists = Db::name('config_trade')
                    ->where('config_name', $data['config_name'])
                    ->where('id', '<>', $data['id'])
                    ->find();
                if ($exists) {
                    return $this->error('配置名称已存在');
                }
                
                // 处理数据
                $updateData = [
                    'config_type' => $data['config_type'],
                    'config_name' => $data['config_name'],
                    'description' => isset($data['description']) ? $data['description'] : '',
                    'value' => $data['value'],
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                // 更新数据
                $result = Db::name('config_trade')
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
        
        $vo = Db::name('config_trade')->where('id', $id)->find();
        if (empty($vo)) {
            return $this->error('交易配置不存在');
        }
        
        $this->assign(['vo' => $vo, 'config_types' => $this->getConfigTypes()]);
        return $this->fetch('form');
    }

    /**
     * 删除交易配置
     * @auth true
     */
    public function remove()
    {
        Log::info('ConfigTrade remove method called');
        
        $id = $this->request->post('id');
        if (empty($id)) {
            return $this->error('参数错误');
        }
        
        try {
            $result = Db::name('config_trade')->where('id', $id)->delete();
            Log::info('Delete result: ' . ($result ? 'success' : 'failed'));
            
            if ($result) {
                return json(['code' => 1, 'info' => '删除成功']);
            } else {
                return $this->error('删除失败或交易配置不存在');
            }
        } catch (\Exception $e) {
            Log::error('Exception in remove method: ' . $e->getMessage());
            return $this->error('删除失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取配置类型选项
     * @return array
     */
    private function getConfigTypes()
    {
        return [
            'trading' => '交易配置',
            'hedge' => '对冲配置',
            'order' => '订单配置'
        ];
    }
}
