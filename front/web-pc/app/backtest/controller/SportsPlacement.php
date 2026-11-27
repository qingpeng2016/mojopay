<?php
declare(strict_types=1);

namespace app\backtest\controller;

use app\backtest\service\SportsSnapshotPresenter;
use think\admin\Controller;
use think\facade\Db;

class SportsPlacement extends Controller
{
    private function presenter():SportsSnapshotPresenter{return new SportsSnapshotPresenter();}

    public function index()
    {
        $this->title='体育铺单历史';$get=$this->request->get();
        $columns=$this->columns();
        unset($columns['machine_name'], $columns['calc_seq']);
        $query=Db::name('sports_placement')->field(array_keys($columns));
        $this->applyFilters($query,$get);
        $result=$query->order('id desc')->paginate(['list_rows'=>(int)($get['pageSize']??20),'page'=>(int)($get['page']??1),'query'=>$get,'path'=>'/admin.html#/backtest/sports_placement/index.html'],false);
        $list=$result->items();$presenter=$this->presenter();foreach($list as&$row)$row=$presenter->presentList($row);unset($row);
        // 铺单历史只会出现「仍在铺单过程中」的状态：
        // 市场 pending/active（close 已停止铺单，不会进历史表）；赛事 scheduled/live（finished/cancelled 已停止铺单）。
        // 异常状态（close/finished/cancelled）不放下拉，但页面文本输入框仍可模糊匹配排查。
        $marketStatusOptions=['pending','active'];
        $eventStatusOptions=['scheduled','live'];
        $this->assign(['list'=>$list,'columns'=>$columns,'pagehtml'=>$result->render(),'get'=>$get,'marketStatusOptions'=>$marketStatusOptions,'eventStatusOptions'=>$eventStatusOptions]);return $this->fetch();
    }

    public function detail()
    {
        $id=(int)$this->request->get('id');$row=Db::name('sports_placement')->where('id',$id)->find();if(!$row)return $this->error('铺单历史不存在');
        $presenter=$this->presenter();$row=$presenter->present($row);
        $inputs=$presenter->inputLabels();$inputNames=[];
        foreach (array_keys($inputs) as $field) $inputNames[$field]=str_starts_with($field,'in_') ? $field : 'in_'.$field;
        $this->assign(['row'=>$row,'inputs'=>$inputs,'inputNames'=>$inputNames,'diagnostics'=>$presenter->diagnosticLabels()]);return $this->fetch('detail');
    }

    public function export()
    {
        $get = $this->request->get();
        $columns = $this->columns();
        $query = Db::name('sports_placement')->field(array_keys($columns));
        $this->applyFilters($query, $get);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sports_placement_' . date('YmdHis') . '.csv"');
        $fp = fopen('php://output', 'w');
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, array_column($columns, 'label'), ',', '"', '');
        // 预分类各列时间类型，避免每行每格重复做 str_ends_with / 正则判断（性能优化）
        $dtCols = ['calc_time','created_at','event_last_published_at','event_expires_at','event_updated_at','source_inserted_at','diag_built_at'];
        $colType = [];
        foreach (array_keys($columns) as $k) {
            if (str_ends_with($k, '_ms')) {
                $colType[$k] = 'ms';
            } elseif (str_ends_with($k, '_at')) {
                $colType[$k] = 'at';
            } elseif (in_array($k, $dtCols, true)) {
                $colType[$k] = 'dt';
            } else {
                $colType[$k] = '';
            }
        }
        // 导出按主键 id 升序（插入时间早的在前），与快盘铺单导出口径一致，方便产品按时间顺序分析。
        $query->order('id asc')->chunk(20, function ($rows) use ($fp, $columns, $colType) {
            foreach ($rows as $row) {
                $line = [];
                foreach (array_keys($columns) as $key) {
                    $line[] = $this->csvValue($row[$key] ?? '', $key, $colType[$key] ?? '');
                }
                fputcsv($fp, $line, ',', '"', '');
            }
            flush();
        }, 'id', 'asc');
        fclose($fp);
        exit;
    }

    private function applyFilters($query,array &$get):void
    {
        foreach(['sport_type','model','quote_status']as$f)if(($get[$f]??'')!=='')$query->where($f,$get[$f]);foreach(['league_id','league_name','event_id','event_name','market_key','request_id']as$f)if(($get[$f]??'')!=='')$query->where($f,'like','%'.trim((string)$get[$f]).'%');
        foreach(['id','sports_market_id','sports_config_id','calc_seq','calc_code']as$f)if(($get[$f]??'')!=='')$query->where($f,(int)$get[$f]);
        // 市场状态：下拉精确 + 自由文本模糊
        if(($get['market_status']??'')!=='')$query->where('market_status',$get['market_status']);
        if(($get['market_status_text']??'')!=='')$query->where('market_status','like','%'.trim((string)$get['market_status_text']).'%');
        // 赛事状态：GET 参数名用 in_event_status（与列表展示字段名一致），数据库列名为 event_status
        if(($get['in_event_status']??'')!=='')$query->where('event_status',$get['in_event_status']);
        if(($get['in_event_status_text']??'')!=='')$query->where('event_status','like','%'.trim((string)$get['in_event_status_text']).'%');
        // 时间筛选值经 URL 往返（尤其分页链接会经 laydate/parseUri 二次编解码）后，空格会被编码成「+」：
        // 直接用于 SQL 比较会错位，且回显到输入框时「+」会让 laydate 解析失败弹「日期格式不合法，已自动重置」。
        // 这里统一把「+」还原为空格。$get 按引用传入，修正后同样作用于分页链接与模板回显。
        foreach(['calc_start','calc_end']as$k)if(isset($get[$k])&&is_string($get[$k]))$get[$k]=str_replace('+',' ',$get[$k]);
        if(!empty($get['calc_start']))$query->where('calc_time','>=',$get['calc_start']);
        if(!empty($get['calc_end']))$query->where('calc_time','<=',$get['calc_end']);
    }

    /**
     * CSV 单元格值格式化（仿 SO 策略快盘数据导出口径）。
     * 时间列统一显示为完整时间（带秒），并以制表符前缀让 Excel 原样显示，
     * 避免被自动重排成 2026/9/28 8:00 把秒吃掉；毫秒时间戳字段（*_ms）转为秒级完整时间；
     * *_at 时间戳数字同样规整。公式型文本加引号前缀；长 ID 加制表符前缀保精度。
     *
     * 注意：所有 date() 调用均做范围校验并用 @ 抑制警告，避免个别异常时间值（如超范围毫秒）
     * 触发 PHP 警告被写入导出流，导致响应损坏、浏览器报 ERR_INVALID_RESPONSE。
     */
    private function csvValue($value, string $field, string $type = ''): string
    {
        if ($value === null) {
            return '';
        }
        $text = is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE);
        // 时间类型列才做毫秒/时间戳/日期解析；普通列直接走下方轻量处理，避免每行每格跑正则
        if ($type === 'ms' || $type === 'at' || $type === 'dt') {
            if ($type === 'ms' && is_numeric($text) && (int)$text > 0) {
                $dt = $this->safeDate((int)$text / 1000);
                if ($dt !== '') {
                    return "\t" . $dt;
                }
            } elseif ($type === 'at' && ctype_digit($text)) {
                $ts = (int)$text;
                if ($ts > 1000000000000) {
                    $ts = (int)($ts / 1000);
                }
                $dt = $this->safeDate($ts);
                if ($dt !== '') {
                    return "\t" . $dt;
                }
            }
            // 日期字符串（含 ISO8601 的 T 分隔 / datetime(3) 微秒）：去掉微秒，保留到秒
            $normalized = str_replace('T', ' ', $text);
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?$/', $normalized, $m)) {
                return "\t" . $m[1];
            }
        }
        // 公式型文本转义，避免被表格软件当成公式
        if (!is_numeric($text) && preg_match('/^[=+\-@]/', $text)) {
            $text = "'" . $text;
        }
        // 长 ID 保留精度（避免 Excel 丢失大整数精度）
        if ($field === 'id' || str_ends_with($field, '_id') || $field === 'calc_seq') {
            return "\t" . $text;
        }
        return $text;
    }

    /**
     * 安全的时间戳转日期：超范围或非法值返回空串，且抑制 date() 警告，绝不向导出流写入错误文本。
     */
    private function safeDate($ts): string
    {
        $ts = (int)$ts;
        if ($ts <= 0 || $ts > 253402300799) { // > 9999-12-31 23:59:59 视为非法
            return '';
        }
        return @date('Y-m-d H:i:s', $ts);
    }

    private function columns():array
    {
        $identity = [
            'id'=>'历史ID','sports_market_id'=>'市场记录ID','sports_config_id'=>'配置ID','machine_name'=>'机器名称',
            'calc_seq'=>'计算序号','league_id'=>'联赛ID','league_name'=>'联赛名称','event_id'=>'赛事ID',
            'event_name'=>'赛事名称','market_key'=>'盘口标识','sport_type'=>'体育类型','model'=>'模型',
            'request_id'=>'请求ID','market_status'=>'市场状态',
        ];
        $input = [
            'home_team_id'=>'主队ID','home_team_name'=>'主队名称',
            'away_team_id'=>'客队ID','away_team_name'=>'客队名称',
            'event_start_time_ms'=>'赛事开始时间(ms)','event_end_time_ms'=>'赛事结束时间(ms)',
            'event_status'=>'赛事状态','event_market_keys'=>'赛事可用盘口(JSON)',
            'event_topic'=>'赛事行情Topic','event_last_published_at'=>'赛事最近发布时间',
            'event_expires_at'=>'赛事目录过期时间','event_updated_at'=>'赛事目录更新时间',
            'input_schema_version'=>'输入协议版本','input_timestamp_ms'=>'输入时间戳(ms)',
            'remaining_sec'=>'剩余秒数','match_total_sec'=>'比赛总时长(秒)',
            'match_remaining_sec'=>'比赛剩余秒数',            'match_score_difference'=>'比分差',
            'event_state'=>'比赛事件',
            'match_home_score'=>'主队比分','match_away_score'=>'客队比分',
            'match_status'=>'比赛状态','match_progress'=>'比赛进度',
            'in_sources'=>'来源快照(JSON)',
            'in_sources_reference'=>'来源数据参考(JSON)',
            'source_topic'=>'来源Topic','source_event_id'=>'来源赛事ID',
            'source_league_id'=>'来源联赛ID','source_key'=>'来源Key',
            'source_snapshot_at_ms'=>'来源快照时间(ms)','source_version'=>'来源版本',
            'source_inserted_at'=>'来源入库时间',
        ] + $this->presenter()->inputLabels();
        $output = [
            'output_schema_version'=>'输出协议版本','quote_version'=>'报价版本',
            'output_timestamp_ms'=>'输出时间戳(ms)','quote_status'=>'报价状态',
            'quote_reason'=>'报价原因','yes_bids'=>'YES买档(JSON)',
            'yes_asks'=>'YES卖档(JSON)','no_bids'=>'NO买档(JSON)',
            'no_asks'=>'NO卖档(JSON)',
            'poly_best_bid'=>'Poly买一价','poly_best_ask'=>'Poly卖一价',
        ];
        $diagnostics = $this->presenter()->diagnosticLabels();
        $meta = [
            'alerts'=>'告警(JSON)','calc_code'=>'返回码','calc_msg'=>'返回信息',
            'calc_cost_us'=>'耗时(us)','calc_time'=>'计算时间','created_at'=>'入库时间',
            'diag_state_old_sources'=>'状态老来源(JSON)',
        ];

        $columns=[];
        foreach ([$identity, $input, $output, $diagnostics, $meta] as $groupIndex => $group) {
            foreach ($group as $field => $description) {
                $name = $groupIndex === 1 && !str_starts_with($field, 'in_') ? 'in_' . $field : $field;
                $columns[$field] = [
                    'name'=>$name,
                    // BOM 后的首字段不能以引号起始，否则部分 CSV 解析器无法识别多行表头。
                    'label'=>$field === 'id' ? '历史ID(id)' : $description . "\n" . $name,
                    'width'=>max(130, strlen($name) * 8 + 24),
                    'json'=>in_array($field, ['event_market_keys','in_sources','in_sources_reference','micro_position_distribution','pricing_runtime_state','yes_bids','yes_asks','no_bids','no_asks','alerts','diag_state_old_sources'], true),
                ];
            }
        }
        return $columns;
    }
}
