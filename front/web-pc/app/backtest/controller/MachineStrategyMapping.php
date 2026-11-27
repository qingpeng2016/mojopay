<?php
declare(strict_types=1);

namespace app\backtest\controller;

use think\admin\Controller;
use think\facade\Db;

class MachineStrategyMapping extends Controller
{
    /** @auth true */
    public function index()
    {
        $this->title = '机器策略分配';
        $get = $this->request->get();
        $query = Db::connect('db_f_backtest')->name('machine_strategy_mapping');
        if (($get['machine_name'] ?? '') !== '') {
            $query->whereLike('machine_name', '%' . trim((string)$get['machine_name']) . '%');
        }
        if (($get['strategy_name'] ?? '') !== '') {
            $query->whereRaw("FIND_IN_SET(?, REPLACE(strategy_ids, '|', ',')) > 0", [trim((string)$get['strategy_name'])]);
        }
        if (in_array((string)($get['status'] ?? ''), ['1', '2'], true)) {
            $query->where('status', (int)$get['status']);
        }
        $result = $query->order('id desc')->paginate([
            'list_rows' => min(100, max(1, (int)($get['pageSize'] ?? 20))),
            'page' => max(1, (int)($get['page'] ?? 1)),
            'query' => $get,
            'path' => '/admin.html#/backtest/machine_strategy_mapping/index.html',
        ], false);
        $list = $result->items();
        foreach ($list as &$row) {
            $row['strategy_names'] = array_filter(explode('|', (string)$row['strategy_ids']), 'strlen');
        }
        unset($row);
        $this->assign(['list' => $list, 'pagehtml' => $result->render(), 'get' => $get]);
        return $this->fetch();
    }

    /** @auth true */
    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $db = Db::connect('db_f_backtest');
                $data = $this->formData($this->request->post(), $db);
                $db->transaction(function () use ($db, $data): void {
                    if ($db->name('machine_strategy_mapping')->where('machine_name', $data['machine_name'])->lock(true)->find()) {
                        throw new \InvalidArgumentException('该实例已有分配，请编辑现有记录');
                    }
                    $db->name('machine_strategy_mapping')->insert($data);
                });
                return json(['code' => 1, 'info' => '添加成功', 'url' => '#/backtest/machine_strategy_mapping/index.html']);
            } catch (\InvalidArgumentException $e) {
                return json(['code' => 0, 'info' => $e->getMessage()]);
            } catch (\Throwable $e) {
                return json(['code' => 0, 'info' => '保存失败，请检查数据库连接']);
            }
        }
        $this->assign('vo', ['strategy_ids' => '', 'status' => 1]);
        return $this->fetch('form');
    }

    /** @auth true */
    public function edit()
    {
        $id = (int)$this->request->param('id');
        $db = Db::connect('db_f_backtest');
        if ($this->request->isPost()) {
            try {
                $data = $this->formData($this->request->post(), $db);
                $db->transaction(function () use ($db, $id, $data): void {
                    if ($id <= 0 || !$db->name('machine_strategy_mapping')->where('id', $id)->lock(true)->find()) {
                        throw new \InvalidArgumentException('机器分配不存在');
                    }
                    if ($db->name('machine_strategy_mapping')->where('machine_name', $data['machine_name'])->where('id', '<>', $id)->find()) {
                        throw new \InvalidArgumentException('该实例已有分配，请编辑现有记录');
                    }
                    $db->name('machine_strategy_mapping')->where('id', $id)->update($data);
                });
                return json(['code' => 1, 'info' => '保存成功', 'url' => '#/backtest/machine_strategy_mapping/index.html']);
            } catch (\InvalidArgumentException $e) {
                return json(['code' => 0, 'info' => $e->getMessage()]);
            } catch (\Throwable $e) {
                return json(['code' => 0, 'info' => '保存失败，请检查数据库连接']);
            }
        }
        $vo = $id > 0 ? $db->name('machine_strategy_mapping')->where('id', $id)->find() : null;
        if (!$vo) return $this->error('机器分配不存在');
        $this->assign('vo', $vo);
        return $this->fetch('form');
    }

    /** @auth true */
    public function delete()
    {
        $id = (int)$this->request->param('id');
        if ($id <= 0) return json(['code' => 0, 'info' => '缺少ID']);
        try {
            $deleted = Db::connect('db_f_backtest')->name('machine_strategy_mapping')->where('id', $id)->delete();
            if (!$deleted) throw new \InvalidArgumentException('机器分配不存在');
            return json(['code' => 1, 'info' => '删除成功']);
        } catch (\InvalidArgumentException $e) {
            return json(['code' => 0, 'info' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return json(['code' => 0, 'info' => '删除失败，请检查数据库连接']);
        }
    }

    private function formData(array $post, $db): array
    {
        $machine = trim((string)($post['machine_name'] ?? ''));
        if ($machine === '' || strlen($machine) > 128 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $machine)) {
            throw new \InvalidArgumentException('实例标识只能使用字母、数字、点、下划线或连字符，且不超过128字');
        }
        $raw = $post['strategy_ids'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            throw new \InvalidArgumentException('请填写策略族名');
        }
        $names = [];
        foreach (explode('|', $raw) as $part) {
            $name = trim($part);
            if ($name === '' || strlen($name) > 128 || preg_match('/[,\s]/u', $name)) {
                throw new \InvalidArgumentException('策略族名无效');
            }
            $names[$name] = true;
        }
        $strategyIDs = implode('|', array_keys($names));
        if (strlen($strategyIDs) > 1024) {
            throw new \InvalidArgumentException('策略族清单不能超过1024字节');
        }
        $existing = $db->name('strategy_config')->whereIn('strategy_name', array_keys($names))->column('strategy_name');
        if (count($existing) !== count($names)) {
            throw new \InvalidArgumentException('所选策略族不存在，请刷新后重试');
        }
        $status = (string)($post['status'] ?? '');
        if (!in_array($status, ['1', '2'], true)) {
            throw new \InvalidArgumentException('分配状态无效');
        }
        return ['machine_name' => $machine, 'strategy_ids' => $strategyIDs, 'status' => (int)$status];
    }
}
