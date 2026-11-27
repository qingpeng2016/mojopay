<?php
declare (strict_types=1);

namespace app\backtest\controller;

use think\admin\Controller;
use think\facade\Db;

/**
 * 体育做市对冲测试（调试工具）
 *
 * 一行 sports_hedge = 一个对冲测试/市场。SO 输入与输出均整段以 JSON 保存（longtext），
 * 后台不解析具体结构（schema-agnostic），SO 字段更新后无需改后台。
 *
 * 数据流：详情页「SO调用」按钮 -> 本控制器的 soCall 动作 -> 内部调用回测服务 HTTP 端点
 *        POST http://fgmm-f-backtest:<port>/api/v1/sports-hedge/compute
 *        -> 回填 so_output + calc_time。
 */
class HedgeTest extends Controller
{
    /** 默认 SO 输入 JSON（新增表单预填）。字段随 SO 演进可自由增减，后台无需改动。 */
    private function defaultSoInput(): string
    {
        return json_encode([
            'request_id' => 'sports-hedge-001',      // 调用关联标识，由外层生成
            'market_id'  => 'internal-market-01',    // FG 内部市场，外部维护跨平台映射
            'as_of_ms'   => 1793152800000,           // 本次业务快照时间
            'match'      => [
                'phase'         => 'in_play',        // 保留场景信息，不再据此把橙色单改成市价单
                'remaining_sec' => 1200,             // 剩余秒数，含已知伤停补时
            ],
            'internal' => [
                'yes_user_balance' => '1600000',     // FG 用户未结算 YES 份额
                'no_user_balance'  => '700000',      // FG 用户未结算 NO 份额
                'net_cash'         => '1595000',     // FG 卖出现金减买入现金，非外部账户余额
            ],
            // 每个对冲平台一条；键名为单数 vendor（不是 vendors）。
            'vendor' => [
                [
                    'platform'           => 'polymarket', // 只接受小写 polymarket / kalshi
                    'platform_market_id' => 'poly-market-01',
                    'available'          => true,         // false 时 SO 不向该平台分配订单
                    'fees'               => [
                        'taker_rate' => '0.05',           // 本轮适用 Taker 费率
                        'maker_rate' => '0',              // 免手续费须显式传 "0"
                    ],
                    'account' => [
                        'positions' => [
                            'YES' => ['quantity' => '400000', 'average_cost' => '0.40'],
                            'NO'  => ['quantity' => '0',      'average_cost' => null],
                        ],
                    ],
                    'orderbooks' => [
                        'YES' => [
                            'bids' => [ // 买盘按价格降序；卖出扫描这些档位
                                ['price' => '0.38', 'quantity' => '60000'],
                                ['price' => '0.37', 'quantity' => '80000'],
                            ],
                            'asks' => [ // 卖盘按价格升序；买入扫描这些档位
                                ['price' => '0.44', 'quantity' => '150000'],
                                ['price' => '0.45', 'quantity' => '300000'],
                            ],
                        ],
                        'NO' => [
                            'bids' => [['price' => '0.55', 'quantity' => '500000']],
                            'asks' => [['price' => '0.57', 'quantity' => '500000']],
                        ],
                    ],
                ],
                [
                    'platform'           => 'kalshi',
                    'platform_market_id' => 'kalshi-market-01',
                    'available'          => true,
                    'fees'               => [
                        'taker_rate' => '0.07',
                        'maker_rate' => '0',
                    ],
                    'account' => [
                        'positions' => [
                            'YES' => ['quantity' => '100000', 'average_cost' => '0.42'],
                            'NO'  => ['quantity' => '0',      'average_cost' => null],
                        ],
                    ],
                    'orderbooks' => [
                        'YES' => [
                            'bids' => [['price' => '0.35', 'quantity' => '100000']],
                            'asks' => [['price' => '0.46', 'quantity' => '100000']],
                        ],
                        'NO' => [
                            'bids' => [['price' => '0.53', 'quantity' => '100000']],
                            'asks' => [['price' => '0.65', 'quantity' => '100000']],
                        ],
                    ],
                ],
            ],
            'history' => [
                'reference_user_net_position' => '1000000', // 对冲时 FG 用户净持仓；无基准传 null
                'warning_since_ms'            => null,      // 黄色连续关注起点；无记录为 null
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * 列表（调试分页页）：分页展示所有对冲测试，每行带 详情/编辑/删除。
     */
    public function index()
    {
        $this->title = '体育做市对冲测试';
        $get = $this->request->get();
        $query = Db::name('sports_hedge');
        if (($get['name'] ?? '') !== '') {
            $query->where('name', 'like', '%' . trim((string)$get['name']) . '%');
        }
        if (!empty($get['id'])) {
            $query->where('id', (int)$get['id']);
        }
        $result = $query->order('id desc')->paginate([
            'list_rows' => (int)($get['pageSize'] ?? 20),
            'page'      => (int)($get['page'] ?? 1),
            'query'     => $get,
            'path'      => '#/backtest/hedge_test/index.html',
        ], false);

        $list = $result->items();
        foreach ($list as &$row) {
            $row['so_input_len']  = $row['so_input'] ? mb_strlen((string)$row['so_input']) : 0;
            $row['so_output_len'] = $row['so_output'] ? mb_strlen((string)$row['so_output']) : 0;
            // calc_time 在库中存的是微秒（SO 包入口到输出的耗时差），展示时除以 1000 得毫秒
            $row['calc_time_ms'] = ($row['calc_time'] !== null) ? round((int)$row['calc_time'] / 1000, 3) : null;
            if (!empty($row['created_at']) && is_string($row['created_at'])) {
                $row['created_at'] = date('Y-m-d H:i:s', strtotime($row['created_at']));
            }
            if (!empty($row['updated_at']) && is_string($row['updated_at'])) {
                $row['updated_at'] = date('Y-m-d H:i:s', strtotime($row['updated_at']));
            }
        }
        unset($row);

        $this->assign([
            'list'     => $list,
            'pagehtml' => $result->render(),
            'get'      => $get,
        ]);
        return $this->fetch();
    }

    /** @auth true */
    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $post = $this->request->post();
                $name = trim((string)($post['name'] ?? ''));
                if ($name === '') {
                    throw new \InvalidArgumentException('测试名称不能为空');
                }
                $soInput = $post['so_input'] ?? '';
                $decoded = json_decode((string)$soInput, true);
                if ($decoded === null && trim((string)$soInput) !== '') {
                    throw new \InvalidArgumentException('SO 输入必须是合法 JSON');
                }
                $now = date('Y-m-d H:i:s');
                Db::name('sports_hedge')->insert([
                    'name'       => $name,
                    'remark'     => trim((string)($post['remark'] ?? '')),
                    'so_input'   => $soInput,
                    'so_output'  => null,
                    'calc_time'  => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return json(['code' => 1, 'info' => '创建成功', 'url' => '#/backtest/hedge_test/index.html']);
            } catch (\Throwable $e) {
                return json(['code' => 0, 'info' => $e->getMessage()]);
            }
        }
        $this->assign('vo', ['name' => '', 'remark' => '', 'so_input' => $this->defaultSoInput()]);
        return $this->fetch('form');
    }

    /** @auth true */
    public function edit()
    {
        $id = (int)$this->request->param('id');
        if ($this->request->isPost()) {
            try {
                if ($id <= 0 || !Db::name('sports_hedge')->where('id', $id)->find()) {
                    throw new \InvalidArgumentException('记录不存在');
                }
                $post = $this->request->post();
                $name = trim((string)($post['name'] ?? ''));
                if ($name === '') {
                    throw new \InvalidArgumentException('测试名称不能为空');
                }
                $soInput = $post['so_input'] ?? '';
                if (trim((string)$soInput) !== '' && json_decode((string)$soInput, true) === null) {
                    throw new \InvalidArgumentException('SO 输入必须是合法 JSON');
                }
                Db::name('sports_hedge')->where('id', $id)->update([
                    'name'       => $name,
                    'remark'     => trim((string)($post['remark'] ?? '')),
                    'so_input'   => $soInput,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                return json(['code' => 1, 'info' => '保存成功', 'url' => '#/backtest/hedge_test/index.html']);
            } catch (\Throwable $e) {
                return json(['code' => 0, 'info' => $e->getMessage()]);
            }
        }
        $vo = Db::name('sports_hedge')->where('id', $id)->find();
        if (!$vo) {
            return $this->error('记录不存在');
        }
        $this->assign('vo', $vo);
        return $this->fetch('form');
    }

    /** @auth true */
    public function delete()
    {
        $id = (int)$this->request->param('id');
        if ($id <= 0) {
            return json(['code' => 0, 'info' => '缺少ID']);
        }
        Db::name('sports_hedge')->where('id', $id)->delete();
        // 不返回 url：ThinkAdmin data-action 处理器在 code=1 且无 url 时会执行
        // $.form.reload() 重新拉取当前列表，从而自动刷新（返回 url 会跳转到相同 hash 而不刷新）。
        return json(['code' => 1, 'info' => '删除成功']);
    }

    /**
     * 详情：SO 输入（可编辑 JSON）+ SO 输出（JSON）+ 右上「SO调用」按钮。
     */
    public function detail()
    {
        $id = (int)$this->request->get('id');
        $vo = Db::name('sports_hedge')->where('id', $id)->find();
        if (!$vo) {
            return $this->error('记录不存在');
        }
        // calc_time 在库中存的是微秒（SO 包入口到输出的耗时差），展示时除以 1000 得毫秒
        $vo['calc_time_ms'] = ($vo['calc_time'] !== null) ? round((int)$vo['calc_time'] / 1000, 3) : null;
        $this->assign('vo', $vo);
        $this->assign('title', '对冲测试详情 · ' . $vo['name']);
        return $this->fetch();
    }

    /**
     * 调用体育对冲 SO：把当前 SO 输入发给回测服务 compute 端点，回填 so_output + calc_time。
     * 若提交时带了最新编辑的 so_input，先保存输入再调用（保证输入/输出都落库为 JSON）。
     * @auth true
     */
    public function soCall()
    {
        if (!$this->request->isPost()) {
            return json(['code' => 0, 'info' => '请以 POST 方式调用']);
        }
        $id = (int)$this->request->post('id');
        $soInput = (string)$this->request->post('so_input', '');
        if (trim($soInput) === '') {
            return json(['code' => 0, 'info' => 'SO 输入不能为空']);
        }
        if (json_decode($soInput, true) === null) {
            return json(['code' => 0, 'info' => 'SO 输入必须是合法 JSON']);
        }
        $row = Db::name('sports_hedge')->where('id', $id)->find();
        if (!$row) {
            return json(['code' => 0, 'info' => '记录不存在']);
        }

        // 先持久化当前输入，保证输入/输出均为 JSON 落库。
        Db::name('sports_hedge')->where('id', $id)->update([
            'so_input'   => $soInput,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $cfg = config('database.so_service', []);
        $host = $cfg['host'] ?? '';
        $port = $cfg['port'] ?? '';
        if (!$host || !$port) {
            return json(['code' => 0, 'info' => '未配置回测服务地址（database.so_service.host/port）']);
        }
        $url = sprintf('http://%s:%s/api/v1/sports-hedge/compute', $host, $port);

        $payload = json_encode(['input' => $soInput]);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return json(['code' => 0, 'info' => '调用回测服务失败：' . $curlErr]);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return json(['code' => 0, 'info' => '回测服务返回 HTTP ' . $httpCode . '：' . substr((string)$body, 0, 500)]);
        }

        $resp = json_decode((string)$body, true);
        if (!is_array($resp) || ($resp['code'] ?? -1) != 0) {
            $msg = is_array($resp) ? ($resp['msg'] ?? '未知错误') : substr((string)$body, 0, 500);
            return json(['code' => 0, 'info' => 'SO 调用失败：' . $msg]);
        }

        // 真实 SO 出参外层带信封 {code,msg,data}；回测服务已拆信封：
        //   data=内层业务输出，so_code/so_msg=业务码，raw=SO 原始信封字符串。
        $soCode = (int)($resp['so_code'] ?? 0);
        $soMsg  = (string)($resp['so_msg'] ?? '');
        $raw    = (string)($resp['raw'] ?? '');
        $calcTime = (int)($resp['calc_time'] ?? 0);
        $now = date('Y-m-d H:i:s');

        if ($soCode !== 0) {
            // 业务错误：仍落库 SO 原始输出（尽量美化为多行 JSON），便于按 SO 报错逐字段排查。
            $soOutput = $raw !== '' ? $raw : (string)json_encode($resp, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($raw !== '' && ($decodedRaw = json_decode($raw, true)) !== null) {
                $soOutput = (string)json_encode($decodedRaw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            Db::name('sports_hedge')->where('id', $id)->update([
                'so_output'  => $soOutput,
                'calc_time'  => $calcTime,
                'updated_at' => $now,
            ]);
            return json([
                'code'       => 1,
                'info'       => sprintf('SO 业务错误(%d)：%s', $soCode, $soMsg !== '' ? $soMsg : '未知错误'),
                'biz_error'  => 1,
                'so_code'    => $soCode,
                'so_msg'     => $soMsg,
                'so_output'  => $soOutput,
                'calc_time'  => $calcTime,
                'updated_at' => $now,
            ]);
        }

        // data 为 SO 信封内层业务输出（对象/数组/字符串），统一转回 JSON 字符串落库。
        $soOutput = json_encode($resp['data'] ?? null, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        Db::name('sports_hedge')->where('id', $id)->update([
            'so_output'  => $soOutput,
            'calc_time'  => $calcTime,
            'updated_at' => $now,
        ]);

        return json([
            'code'       => 1,
            'info'       => '调用成功',
            'biz_error'  => 0,
            'so_code'    => 0,
            'so_msg'     => $soMsg,
            'so_output'  => $soOutput,
            'calc_time'  => $calcTime,
            'updated_at' => $now,
        ]);
    }
}
