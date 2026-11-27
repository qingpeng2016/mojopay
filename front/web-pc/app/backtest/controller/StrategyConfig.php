<?php
declare(strict_types=1);

namespace app\backtest\controller;

use think\admin\Controller;
use think\facade\Db;

class StrategyConfig extends Controller
{
    /** @auth true */
    public function index()
    {
        $this->title = '策略配置';
        $get = $this->request->get();
        $query = Db::connect('db_f_backtest')->name('strategy_config');
        if (($get['strategy_name'] ?? '') !== '') {
            $query->whereLike('strategy_name', '%' . trim((string)$get['strategy_name']) . '%');
        }
        if (($get['status'] ?? '') !== '') {
            $query->where('status', (int)$get['status']);
        }
        $result = $query->order('id desc')->paginate([
            'list_rows' => min(100, max(1, (int)($get['pageSize'] ?? 20))),
            'page' => max(1, (int)($get['page'] ?? 1)),
            'query' => $get,
            'path' => '/admin.html#/backtest/strategy_config/index.html',
        ], false);
        $this->assign(['list' => $result->items(), 'pagehtml' => $result->render(), 'get' => $get]);
        return $this->fetch();
    }

    /** @auth true */
    public function add()
    {
        if ($this->request->isPost()) {
            try {
                $data = $this->formData($this->request->post(), true);
                $db = Db::connect('db_f_backtest');
                if ($db->name('strategy_config')->where('strategy_name', $data['strategy_name'])->find()) {
                    throw new \InvalidArgumentException('策略族名已存在');
                }
                $db->name('strategy_config')->insert($data);
                return json(['code' => 1, 'info' => '添加成功', 'url' => '#/backtest/strategy_config/index.html']);
            } catch (\InvalidArgumentException $e) {
                return json(['code' => 0, 'info' => $e->getMessage()]);
            } catch (\Throwable $e) {
                return json(['code' => 0, 'info' => '保存失败，请检查策略族名是否重复及数据库连接']);
            }
        }
        $this->assign('vo', ['status' => 1]);
        return $this->fetch('form');
    }

    /** @auth true */
    public function edit()
    {
        $id = (int)$this->request->param('id');
        $db = Db::connect('db_f_backtest');
        if ($this->request->isPost()) {
            try {
                if ($id <= 0 || !$db->name('strategy_config')->where('id', $id)->find()) {
                    throw new \InvalidArgumentException('策略配置不存在');
                }
                $data = $this->formData($this->request->post(), false);
                $db->name('strategy_config')->where('id', $id)->update($data);
                return json(['code' => 1, 'info' => '保存成功', 'url' => '#/backtest/strategy_config/index.html']);
            } catch (\InvalidArgumentException $e) {
                return json(['code' => 0, 'info' => $e->getMessage()]);
            } catch (\Throwable $e) {
                return json(['code' => 0, 'info' => '保存失败，请检查数据库连接']);
            }
        }
        $vo = $id > 0 ? $db->name('strategy_config')->where('id', $id)->find() : null;
        if (!$vo) return $this->error('策略配置不存在');
        $this->assign('vo', $vo);
        return $this->fetch('form');
    }

    /** @auth true */
    public function delete()
    {
        $id = (int)$this->request->param('id');
        if ($id <= 0) return json(['code' => 0, 'info' => '缺少ID']);
        try {
            $db = Db::connect('db_f_backtest');
            $db->transaction(function () use ($db, $id): void {
                $row = $db->name('strategy_config')->where('id', $id)->lock(true)->find();
                if (!$row) throw new \InvalidArgumentException('策略配置不存在');
                $name = (string)$row['strategy_name'];
                if ($db->name('bot_schedule_config')->where('strategy_name', $name)->count() > 0 ||
                    $db->name('machine_strategy_mapping')->whereRaw("FIND_IN_SET(?, REPLACE(strategy_ids, '|', ',')) > 0", [$name])->count() > 0) {
                    throw new \InvalidArgumentException('请先删除该策略的任务并移除机器分配');
                }
                $db->name('strategy_config')->where('id', $id)->delete();
            });
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
            $name = trim((string)($post['strategy_name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 128 || preg_match('/\s/u', $name)) {
                throw new \InvalidArgumentException('策略族名不能为空、含空白字符或超过128字');
            }
            $data['strategy_name'] = $name;
        }
        $description = trim((string)($post['description'] ?? ''));
        $status = (string)($post['status'] ?? '');
        if (mb_strlen($description) > 512 || !in_array($status, ['1', '2', '3', '4'], true)) {
            throw new \InvalidArgumentException('描述过长或状态无效');
        }
        $data['description'] = $description;
        $data['status'] = (int)$status;
        return $data;
    }
}
