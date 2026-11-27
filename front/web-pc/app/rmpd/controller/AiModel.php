<?php
declare (strict_types=1);

namespace app\rmpd\controller;

use app\rmpd\trait\SafeQueryTrait;
use think\admin\Controller;
use think\facade\Db;

/**
 * AI 模型配置（只读展示）
 *
 * 策略侧已多模型化：Claude / DeepSeek 都走 Anthropic 协议（DeepSeek 用兼容端点），
 * risk_config.ai_scan_model / ai_review_model 与 ai_call_log.model 均按 ai_model.name 引用，
 * 成本按本表费率计算。本页只读展示，配置由策略团队直接操作数据库。
 *
 * 安全：api_key 在库中为明文，本页**绝不展示明文密钥**，只显示"是否已配置"+环境变量名。
 *
 * 相关数据库表：ai_model
 */
class AiModel extends Controller
{
    use SafeQueryTrait;

    /**
     * AI 模型列表
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = 'AI 模型配置';
        $get = $this->request->get();

        $query = Db::connect('db_rmpd')->name('ai_model');

        // 按模型名模糊搜索
        if (!empty($get['name'])) {
            $kw = $this->sanitizeLike($get['name']);
            $query->where('name', 'like', "%{$kw}%");
        }

        // 按启用状态过滤（'1' / '0'）
        if (isset($get['is_enabled']) && $get['is_enabled'] !== '') {
            $query->where('is_enabled', (int)$get['is_enabled']);
        }

        $result = $query->order('id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? intval($get['pageSize']) : 20,
            'page'      => isset($get['page']) ? intval($get['page']) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/rmpd/ai_model/index.html',
        ], false);

        $list = $result->items();

        foreach ($list as &$item) {
            // 安全：标记 api_key 是否已配置明文，但不回传明文本身。
            $item['has_api_key'] = !empty($item['api_key']);
            unset($item['api_key']);

            $item['updated_at'] = !empty($item['updated_at'])
                ? date('Y-m-d H:i:s', strtotime($item['updated_at']))
                : '-';
        }

        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
        ]);

        return $this->fetch();
    }
}
