<?php

declare (strict_types=1);

namespace app\mojopay\service;

use think\facade\Db;

/**
 * 按 provider 表同步「上游管理」下的动态二级菜单。
 */
class ProviderMenuSync
{
    private const ROOT_TITLE     = 'MojoPay';
    private const UPSTREAM_TITLE = '上游管理';
    private const HUB_NODE       = 'mojopay/provider_hub/index';
    private const HUB_URL        = 'mojopay/provider_hub/index';
    private const LIB_NODE       = 'mojopay/provider/index';
    private const LIB_URL        = 'mojopay/provider/index';

    /** @var list<string> 旧版静态子菜单 node，同步时删除 */
    private const LEGACY_NODES = [
        'mojopay/provider/index',
        'mojopay/provider_account/index',
        'mojopay/provider_credential/index',
        'mojopay/channel_capability/index',
        'mojopay/provider_event_inbox/index',
    ];

    public function sync(): void
    {
        $admin = Db::connect('mysql');
        $biz   = Db::connect('db_mojopay');

        $rootId = (int)$admin->name('system_menu')
            ->where('pid', 0)
            ->where('title', self::ROOT_TITLE)
            ->value('id');
        if ($rootId <= 0) {
            return;
        }

        $this->ensureRootDirOrder($admin, $rootId);

        $upstreamId = (int)$admin->name('system_menu')
            ->where('pid', $rootId)
            ->where('title', self::UPSTREAM_TITLE)
            ->value('id');
        if ($upstreamId <= 0) {
            $upstreamId = (int)$admin->name('system_menu')->insertGetId([
                'pid'       => $rootId,
                'title'     => self::UPSTREAM_TITLE,
                'icon'      => 'layui-icon layui-icon-upload',
                'node'      => '',
                'url'       => '#',
                'params'    => '',
                'target'    => '_self',
                'sort'      => 20,
                'status'    => 1,
                'create_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $admin->name('system_menu')->where('id', $upstreamId)->update(['sort' => 20]);
        }

        $childIds = $admin->name('system_menu')->where('pid', $upstreamId)->column('id');
        if ($childIds) {
            $admin->name('system_menu')->whereIn('id', $childIds)->delete();
        }

        $providers = $biz->name('provider')->order('name', 'asc')->select()->toArray();
        $sort      = 10;
        foreach ($providers as $row) {
            $pid = (int)($row['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $title = trim((string)($row['name'] ?? ''));
            if ($title === '') {
                $title = (string)($row['code'] ?? ('#' . $pid));
            }
            $admin->name('system_menu')->insert([
                'pid'       => $upstreamId,
                'title'     => $title,
                'icon'      => 'layui-icon layui-icon-app',
                'node'      => self::HUB_NODE,
                'url'       => self::HUB_URL,
                'params'    => 'provider_id=' . $pid,
                'target'    => '_self',
                'sort'      => $sort,
                'status'    => 1,
                'create_at' => date('Y-m-d H:i:s'),
            ]);
            $sort += 10;
        }

        $admin->name('system_menu')->insert([
            'pid'       => $upstreamId,
            'title'     => '商家列表',
            'icon'      => 'layui-icon layui-icon-add-circle',
            'node'      => self::LIB_NODE,
            'url'       => self::LIB_URL,
            'params'    => '',
            'target'    => '_self',
            'sort'      => 900,
            'status'    => 1,
            'create_at' => date('Y-m-d H:i:s'),
        ]);

        $this->ensureAuthNodes($admin);
    }

    /** 上游管理始终在下游商户之上（ThinkAdmin 按 sort desc，越大越靠前） */
    private function ensureRootDirOrder($admin, int $rootId): void
    {
        $admin->name('system_menu')->where('pid', $rootId)->where('title', self::UPSTREAM_TITLE)->update(['sort' => 20]);
        foreach (['下游商户', '下游商家'] as $title) {
            $admin->name('system_menu')->where('pid', $rootId)->where('title', $title)->update(['sort' => 10]);
        }
    }

    private function ensureAuthNodes($admin): void
    {
        $nodes = [
            self::HUB_NODE,
            self::LIB_NODE,
            'mojopay/provider/add',
            'mojopay/provider/edit',
            'mojopay/provider/remove',
            'mojopay/provider_account/index',
            'mojopay/provider_account/add',
            'mojopay/provider_account/edit',
            'mojopay/provider_account/remove',
            'mojopay/provider_credential/index',
            'mojopay/provider_credential/edit',
            'mojopay/channel_capability/index',
            'mojopay/channel_capability/add',
            'mojopay/channel_capability/edit',
            'mojopay/channel_capability/remove',
            'mojopay/provider_event_inbox/index',
            'mojopay/provider_order/index',
        ];
        foreach ($nodes as $node) {
            $exists = $admin->name('system_auth_node')->where(['auth' => 1, 'node' => $node])->find();
            if (empty($exists)) {
                $admin->name('system_auth_node')->insert(['auth' => 1, 'node' => $node]);
            }
        }
    }
}