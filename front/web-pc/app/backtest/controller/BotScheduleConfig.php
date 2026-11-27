<?php
declare(strict_types=1);

namespace app\backtest\controller;

use think\admin\Controller;
use think\facade\Db;

class BotScheduleConfig extends Controller
{
    /** @auth true */
    public function index()
    {
        $this->title = '任务调度配置';
        $get = $this->request->get();
        $query = Db::connect('db_f_backtest')->name('bot_schedule_config');
        if (($get['strategy_name'] ?? '') !== '') $query->where('strategy_name', (string)$get['strategy_name']);
        if (($get['task_name'] ?? '') !== '') $query->whereLike('task_name', '%' . trim((string)$get['task_name']) . '%');
        if (($get['is_enabled'] ?? '') !== '') $query->where('is_enabled', (int)$get['is_enabled']);
        $result = $query->order('id desc')->paginate([
            'list_rows' => min(100, max(1, (int)($get['pageSize'] ?? 20))),
            'page' => max(1, (int)($get['page'] ?? 1)),
            'query' => $get,
            'path' => '/admin.html#/backtest/bot_schedule_config/index.html',
        ], false);
        $list = $result->items();
        foreach ($list as &$row) {
            $heartbeat = $row['last_live_time_by_machine'] ?? null;
            $row['machine_heartbeat'] = is_array($heartbeat)
                ? json_encode($heartbeat, JSON_UNESCAPED_UNICODE)
                : (string)$heartbeat;
        }
        unset($row);
        $this->assign(['list' => $list, 'pagehtml' => $result->render(), 'get' => $get]);
        return $this->fetch();
    }

    /** @auth true */
    public function add()
    {
        $db = Db::connect('db_f_backtest');
        if ($this->request->isPost()) {
            try {
                $data = $this->formData($this->request->post(), true);
                $db->transaction(function () use ($db, $data): void {
                    if (!$db->name('strategy_config')->where('strategy_name', $data['strategy_name'])->lock(true)->find()) {
                        throw new \InvalidArgumentException('请先新增对应的策略配置');
                    }
                    if ($db->name('bot_schedule_config')->where('strategy_name', $data['strategy_name'])->where('task_name', $data['task_name'])->find()) {
                        throw new \InvalidArgumentException('该策略的任务名已存在');
                    }
                    $db->name('bot_schedule_config')->insert($data);
                });
                return json(['code' => 1, 'info' => '添加成功', 'url' => '#/backtest/bot_schedule_config/index.html']);
            } catch (\InvalidArgumentException $e) {
                return json(['code' => 0, 'info' => $e->getMessage()]);
            } catch (\Throwable $e) {
                return json(['code' => 0, 'info' => '保存失败，请检查任务是否重复及数据库连接']);
            }
        }
        $this->assign('vo', [
            'strategy_name' => trim((string)$this->request->get('strategy_name', '')),
            'interval_seconds' => 60,
            'is_enabled' => 1,
            'is_strategy_enabled' => 1,
            'is_primary_machine_run' => 0,
        ]);
        return $this->fetch('form');
    }

    /** @auth true */
    public function edit()
    {
        $id = (int)$this->request->param('id');
        $db = Db::connect('db_f_backtest');
        if ($this->request->isPost()) {
            try {
                if ($id <= 0 || !$db->name('bot_schedule_config')->where('id', $id)->find()) {
                    throw new \InvalidArgumentException('任务配置不存在');
                }
                $data = $this->formData($this->request->post(), false);
                $db->name('bot_schedule_config')->where('id', $id)->update($data);
                return json(['code' => 1, 'info' => '保存成功', 'url' => '#/backtest/bot_schedule_config/index.html']);
            } catch (\InvalidArgumentException $e) {
                return json(['code' => 0, 'info' => $e->getMessage()]);
            } catch (\Throwable $e) {
                return json(['code' => 0, 'info' => '保存失败，请检查数据库连接']);
            }
        }
        $vo = $id > 0 ? $db->name('bot_schedule_config')->where('id', $id)->find() : null;
        if (!$vo) return $this->error('任务配置不存在');
        $this->assign('vo', $vo);
        return $this->fetch('form');
    }

    /** @auth true */
    public function delete()
    {
        $id = (int)$this->request->param('id');
        if ($id <= 0) return json(['code' => 0, 'info' => '缺少ID']);
        try {
            $deleted = Db::connect('db_f_backtest')->name('bot_schedule_config')->where('id', $id)->delete();
            if (!$deleted) throw new \InvalidArgumentException('任务配置不存在');
            return json(['code' => 1, 'info' => '删除成功']);
        } catch (\InvalidArgumentException $e) {
            return json(['code' => 0, 'info' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return json(['code' => 0, 'info' => '删除失败，请检查数据库连接']);
        }
    }

    private function formData(array $post, bool $creating): array
    {
        $data = [];
        if ($creating) {
            foreach (['strategy_name' => 50, 'task_name' => 100] as $field => $max) {
                $value = trim((string)($post[$field] ?? ''));
                if ($value === '' || mb_strlen($value) > $max || preg_match('/\s/u', $value)) {
                    throw new \InvalidArgumentException($field . ' 不能为空、含空白字符或超长');
                }
                $data[$field] = $value;
            }
        }
        $interval = $post['interval_seconds'] ?? '';
        if (!is_numeric($interval) || !is_finite((float)$interval) || (float)$interval <= 0) {
            throw new \InvalidArgumentException('执行间隔必须是大于0的有限秒数');
        }
        $data['interval_seconds'] = (float)$interval;
        $concurrency = trim((string)($post['concurrency'] ?? ''));
        if ($concurrency !== '' && (filter_var($concurrency, FILTER_VALIDATE_INT) === false || (int)$concurrency < 1)) {
            throw new \InvalidArgumentException('并发数必须是正整数或留空');
        }
        $data['concurrency'] = $concurrency === '' ? null : (int)$concurrency;
        $description = trim((string)($post['description'] ?? ''));
        if (mb_strlen($description) > 500) throw new \InvalidArgumentException('任务描述不能超过500字');
        $data['description'] = $description === '' ? null : $description;
        foreach (['is_enabled', 'is_strategy_enabled', 'is_primary_machine_run'] as $flag) {
            $value = (string)($post[$flag] ?? '');
            if (!in_array($value, ['0', '1'], true)) throw new \InvalidArgumentException($flag . ' 必须是0或1');
            $data[$flag] = (int)$value;
        }
        return $data;
    }
}
