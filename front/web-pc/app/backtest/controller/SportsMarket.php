<?php
declare(strict_types=1);

namespace app\backtest\controller;

use app\backtest\service\SportsSnapshotPresenter;
use think\admin\Controller;
use think\facade\Db;
use think\facade\Log;

class SportsMarket extends Controller
{
    private function presenter(): SportsSnapshotPresenter { return new SportsSnapshotPresenter(); }

    public function index()
    {
        $this->title = '体育市场信息';
        $get = $this->request->get();
        $query = Db::name('sports_market')->field('id,sports_config_id,machine_name,league_id,league_name,event_id,event_name,market_key, sport_type,model,market_status,save_placement_history,quote_status,match_score_difference,remaining_sec,match_home_score,match_away_score,match_status,match_progress,yes_bids,yes_asks,no_bids,no_asks,micro_v_buy,micro_v_sell,micro_v_aggressive,micro_v_passive,inventory_q,inventory_q_cap,calc_seq,calc_code,calc_msg,calc_time,updated_at');
        $this->applyFilters($query, $get);
        $result = $query->order('id desc')->paginate(['list_rows'=>(int)($get['pageSize']??20),'page'=>(int)($get['page']??1),'query'=>$get,'path'=>'/admin.html#/backtest/sports_market/index.html'], false);
        $list = $result->items(); $presenter = $this->presenter();
        foreach ($list as &$row) $row = $presenter->presentList($row); unset($row);
        $this->assign(['list'=>$list,'pagehtml'=>$result->render(),'get'=>$get]);
        return $this->fetch();
    }

    public function detail()
    {
        $id = (int)$this->request->get('id');
        $row = Db::name('sports_market')->where('id',$id)->find();
        if (!$row) return $this->error('体育市场不存在');
        $presenter=$this->presenter(); $row=$presenter->present($row);
        $config=Db::name('sports_market_config')->where('id',$row['sports_config_id'])->find() ?: [];
        $refreshMs=max(500,(int)($config['calc_interval_ms']??1000));
        $this->assign([
            'title'=>'盘口详情 · '.$row['event_id'].' · '.$row['market_key'],
            'row'=>$row,'inputs'=>$presenter->inputLabels(),'diagnostics'=>$presenter->diagnosticLabels(),
            'editableParams'=>$this->editableParams(),'refreshMs'=>$refreshMs,'refreshSec'=>$refreshMs/1000,
        ]);
        return $this->fetch('detail');
    }

    /**
     * 编辑当前市场的库存与微观参数。
     * 与快盘 Market::saveParam 保持一致，不增加独立权限节点；
     * 控制器登录态、字段白名单、市场状态和数值范围仍在服务端校验。
     */
    public function saveParam()
    {
        $id = (int)$this->request->post('id');
        $field = (string)$this->request->post('field');
        $value = $this->request->post('value');
        $labels = $this->editableParams();
        if ($id <= 0 || !array_key_exists($field, $labels)) return $this->error('参数错误');
        $row = Db::name('sports_market')->where('id', $id)->find();
        if (!$row) return $this->error('体育市场不存在');
        if (!in_array($row['market_status'], ['pending', 'active'], true)) return $this->error('已关闭市场不能修改参数');

        if ($field === 'micro_position_distribution') {
            try {
                $encoded = $this->normalizePositionDistribution($value);
            } catch (\Throwable $e) {
                return $this->error($e->getMessage());
            }
            $affected = Db::name('sports_market')->where('id', $id)->whereIn('market_status', ['pending', 'active'])->update([$field => $encoded]);
            if ($affected === 0) {
                $current = Db::name('sports_market')->where('id', $id)->find();
                if (!$current || !in_array($current['market_status'], ['pending', 'active'], true)) return $this->error('市场状态已变化，请刷新页面');
            }
            Log::info('sports market param updated', [
                'operator_id'=>session('user.id'),'market_id'=>$id,'field'=>$field,
                'before'=>$row[$field],'after'=>$encoded,'ip'=>$this->request->ip(),
            ]);
            return $this->success($labels[$field] . ' 已更新，下一轮铺单生效');
        }

        if ($value === null || $value === '' || !is_numeric($value) || !is_finite((float)$value)) return $this->error('请输入有限数值');

        $merged = [];
        foreach (array_keys($labels) as $key) {
            if ($key !== 'micro_position_distribution') $merged[$key] = (float)$row[$key];
        }
        $merged[$field] = (float)$value;
        try { $this->validatePlatformParams($merged); } catch (\Throwable $e) { return $this->error($e->getMessage()); }

        $affected = Db::name('sports_market')->where('id', $id)->whereIn('market_status', ['pending', 'active'])->update([$field => (float)$value]);
        if ($affected === 0) {
            $current = Db::name('sports_market')->where('id', $id)->find();
            if (!$current || !in_array($current['market_status'], ['pending', 'active'], true)) return $this->error('市场状态已变化，请刷新页面');
        }
        Log::info('sports market param updated', [
            'operator_id'=>session('user.id'),'market_id'=>$id,'field'=>$field,
            'before'=>$row[$field],'after'=>(float)$value,'ip'=>$this->request->ip(),
        ]);
        return $this->success($labels[$field] . ' 已更新为 ' . (float)$value . '，下一轮铺单生效');
    }

    /** @auth true */
    public function saveHistorySetting()
    {
        $id = (int)$this->request->post('id');
        $enabled = (string)$this->request->post('enabled');
        if ($id <= 0 || !in_array($enabled, ['0', '1'], true)) return $this->error('参数错误');

        $row = Db::name('sports_market')->where('id', $id)->find();
        if (!$row) return $this->error('体育市场不存在');
        if (!in_array($row['market_status'], ['pending', 'active'], true)) return $this->error('已关闭市场不能修改历史保存设置');

        $value = (int)$enabled;
        $affected = Db::name('sports_market')->where('id', $id)->whereIn('market_status', ['pending', 'active'])->update(['save_placement_history' => $value]);
        if ($affected === 0) {
            $current = Db::name('sports_market')->where('id', $id)->find();
            if (!$current || !in_array($current['market_status'], ['pending', 'active'], true)) return $this->error('市场状态已变化，请刷新页面');
        }
        Log::info('sports market placement history setting updated', [
            'operator_id'=>session('user.id'),'market_id'=>$id,
            'before'=>(int)$row['save_placement_history'],'after'=>$value,'ip'=>$this->request->ip(),
        ]);
        if ($value === 1) return $this->success('已开启铺单历史保存');
        return $this->success('已停止生成新铺单历史，已有历史不会删除');
    }

    public function export()
    {
        $get=$this->request->get(); $query=Db::name('sports_market'); $this->applyFilters($query,$get);
        $columns=$this->exportColumns();
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="sports_market_'.date('YmdHis').'.csv"');
        $fp=fopen('php://output','w'); fwrite($fp,"\xEF\xBB\xBF"); fputcsv($fp,array_values($columns));
        $query->order('id desc')->chunk(200,function($rows)use($fp,$columns){foreach($rows as $row){$line=[];foreach(array_keys($columns)as$key){$v=$row[$key]??'';if(in_array($key,['id','sports_config_id','input_timestamp_ms','output_timestamp_ms','calc_seq'],true)&&$v!=='')$v="\t".$v;$line[]=$v;}fputcsv($fp,$line);}});
        fclose($fp); exit;
    }

    private function applyFilters($query,array &$get):void
    {
        foreach(['sport_type','model','machine_name','market_status','quote_status']as$f)if(($get[$f]??'')!=='')$query->where($f,$get[$f]);
        if (($get['save_placement_history'] ?? '') !== '') $query->where('save_placement_history', (int)$get['save_placement_history']);
        foreach(['league_id','league_name','event_id','event_name','market_key','request_id']as$f)if(($get[$f]??'')!=='')$query->where($f,'like','%'.trim((string)$get[$f]).'%');
        foreach(['id','sports_config_id','calc_code']as$f)if(($get[$f]??'')!=='')$query->where($f,(int)$get[$f]);
        if(!empty($get['calc_start']))$query->where('calc_time','>=',$get['calc_start']); if(!empty($get['calc_end']))$query->where('calc_time','<=',$get['calc_end']);
    }

    private function validatePlatformParams(array $values): void
    {
        if($values['inventory_q_cap']<=0||abs($values['inventory_q'])>$values['inventory_q_cap'])throw new \InvalidArgumentException('库存 Q 必须位于 q_cap 范围内');
        foreach(['micro_v_bid_5s_ago','micro_v_bid_now','micro_v_ask_5s_ago','micro_v_ask_now','micro_v_buy','micro_v_sell','micro_v_aggressive','micro_v_passive','slippage_estimate'] as $field) if($values[$field]<0) throw new \InvalidArgumentException("{$field} 不得小于 0");
    }

    private function editableParams(): array
    {
        return [
            'inventory_q'=>'库存 Q', 'inventory_q_cap'=>'库存 Q 上限',
            'micro_v_bid_5s_ago'=>'5 秒前买量', 'micro_v_bid_now'=>'当前买量',
            'micro_v_ask_5s_ago'=>'5 秒前卖量', 'micro_v_ask_now'=>'当前卖量',
            'micro_v_buy'=>'成交买量', 'micro_v_sell'=>'成交卖量',
            'micro_v_aggressive'=>'主动成交量', 'micro_v_passive'=>'被动成交量',
            'micro_position_distribution'=>'仓位分布（JSON）',
            'slippage_estimate'=>'滑点估计',
        ];
    }

    private function normalizePositionDistribution($value): string
    {
        if (is_array($value)) {
            $distribution = array_values($value);
        } else {
            $raw = trim((string)$value);
            if ($raw === '') throw new \InvalidArgumentException('仓位分布不能为空');
            $distribution = json_decode($raw, true);
        }
        if (!is_array($distribution) || !$distribution) throw new \InvalidArgumentException('仓位分布必须是非空 JSON 数组');
        if (array_keys($distribution) !== range(0, count($distribution) - 1)) {
            throw new \InvalidArgumentException('仓位分布必须是 JSON 数组，不能是对象');
        }
        foreach ($distribution as $item) {
            if (!is_numeric($item) || !is_finite((float)$item) || (float)$item < 0) {
                throw new \InvalidArgumentException('仓位分布必须是非负有限数值数组');
            }
        }
        $encoded = json_encode(array_map(static fn($item) => (float)$item, $distribution), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if ($encoded === false) throw new \InvalidArgumentException('仓位分布 JSON 编码失败');
        return $encoded;
    }

    private function exportColumns():array
    {
        return ['id'=>'ID','sports_config_id'=>'配置ID','league_id'=>'联赛ID','league_name'=>'联赛名称','machine_name'=>'机器','event_id'=>'赛事ID','event_name'=>'赛事名称','market_key'=>'盘口标识','sport_type'=>'体育类型','model'=>'模型','market_status'=>'市场状态','save_placement_history'=>'保存铺单历史','quote_status'=>'报价状态','request_id'=>'Request ID','match_home_score'=>'主队比分','match_away_score'=>'客队比分','match_status'=>'比赛状态','match_progress'=>'比赛进度','input_timestamp_ms'=>'输入时间戳','micro_v_bid_now'=>'当前买量','micro_v_ask_now'=>'当前卖量','micro_v_buy'=>'成交买量','micro_v_sell'=>'成交卖量','micro_v_aggressive'=>'主动成交量','micro_v_passive'=>'被动成交量','slippage_estimate'=>'滑点估计','inventory_q'=>'库存Q','inventory_q_cap'=>'库存Q上限','diag_fair_probability'=>'公平概率','diag_target_probability'=>'目标概率','diag_spread1'=>'基础动态点差','diag_spread2'=>'微观调整点差','diag_hedge_required'=>'需要对冲','calc_seq'=>'计算序号','calc_code'=>'返回码','calc_msg'=>'返回消息','calc_time'=>'计算时间','updated_at'=>'更新时间','diag_state_old_sources'=>'状态老来源'];
    }
}
