<?php

declare (strict_types=1);

namespace app\mojopay\service;

use think\facade\Db;

/**
 * 按 merchant 表同步「下游商户」下的动态二级菜单。
 */
class MerchantMenuSync
{
    private const ROOT_TITLE       = 'MojoPay';
    private const DOWNSTREAM_TITLE = '下游商户';
    private const UPSTREAM_TITLE   = '上游管理';
    private const HUB_NODE         = 'mojopay/merchant_hub/index';
    private const HUB_URL          = 'mojopay/merchant_hub/index';
    private const LIB_NODE         = 'mojopay/merchant/index';
    private const LIB_URL          = 'mojopay/merchant/index';

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

        $downstreamId = (int)$admin->name('system_menu')
            ->where('pid', $rootId)
            ->where('title', self::DOWNSTREAM_TITLE)
            ->value('id');
        if ($downstreamId <= 0) {
            $downstreamId = (int)$admin->name('system_menu')->insertGetId([
                'pid'       => $rootId,
                'title'     => self::DOWNSTREAM_TITLE,
                'icon'      => 'layui-icon layui-icon-group',
                'node'      => '',
                'url'       => '#',
                'params'    => '',
                'target'    => '_self',
                'sort'      => 10,
                'status'    => 1,
                'create_at' => date('Y-m-d H:i:s'),
            ]);
        } else {
            $admin->name('system_menu')->where('id', $downstreamId)->update(['sort' => 10]);
        }

        $childIds = $admin->name('system_menu')->where('pid', $downstreamId)->column('id');
        if ($childIds) {
            $admin->name('system_menu')->whereIn('id', $childIds)->delete();
        }

        $merchants = $biz->name('merchant')->order('legal_name', 'asc')->select()->toArray();
        $sort      = 10;
        foreach ($merchants as $row) {
            $mid = (int)($row['id'] ?? 0);
            if ($mid <= 0) {
                continue;
            }
            $title = trim((string)($row['legal_name'] ?? ''));
            if ($title === '') {
                $title = (string)($row['code'] ?? ('#' . $mid));
            }
            $admin->name('system_menu')->insert([
                'pid'       => $downstreamId,
                'title'     => $title,
                'icon'      => 'layui-icon layui-icon-app',
                'node'      => self::HUB_NODE,
                'url'       => self::HUB_URL,
                'params'    => 'merchant_id=' . $mid,
                'target'    => '_self',
                'sort'      => $sort,
                'status'    => 1,
                'create_at' => date('Y-m-d H:i:s'),
            ]);
            $sort += 10;
        }

        $admin->name('system_menu')->insert([
            'pid'       => $downstreamId,
            'title'     => '商户列表',
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

    private function ensureRootDirOrder($admin, int $rootId): void
    {
        $admin->name('system_menu')->where('pid', $rootId)->where('title', self::UPSTREAM_TITLE)->update(['sort' => 20]);
        $admin->name('system_menu')->where('pid', $rootId)->where('title', self::DOWNSTREAM_TITLE)->update(['sort' => 10]);
        $admin->name('system_menu')->where('pid', $rootId)->where('title', '下游商家')->update(['sort' => 10]);
    }

    private function ensureAuthNodes($admin): void
    {
        $nodes = [
            self::HUB_NODE,
            self::LIB_NODE,
            'mojopay/merchant/add',
            'mojopay/merchant/edit',
            'mojopay/merchant/remove',
            'mojopay/merchant_account/index',
            'mojopay/merchant_account/add',
            'mojopay/merchant_account/edit',
            'mojopay/merchant_account/remove',
            'mojopay/merchant_compliance/manage',
            'mojopay/merchant_compliance/case_add',
            'mojopay/merchant_compliance/case_edit',
            'mojopay/merchant_open_api/index',
            'mojopay/merchant_open_api/add',
            'mojopay/merchant_open_api/edit',
            'mojopay/merchant_open_api/remove',
            'mojopay/merchant_open_api/webhook_add',
            'mojopay/merchant_open_api/webhook_edit',
            'mojopay/merchant_open_api/webhook_remove',
            'mojopay/merchant_channel_binding/index',
            'mojopay/merchant_channel_binding/add',
            'mojopay/merchant_channel_binding/edit',
            'mojopay/merchant_channel_binding/remove',
            'mojopay/merchant_event_outbox/index',
            'mojopay/merchant_event_inbox/index',
            'mojopay/merchant_payment_order/index',
            'mojopay/merchant_payment_order/add',
            'mojopay/merchant_payment_order/binding_options',
        ];
        foreach ($nodes as $node) {
            $exists = $admin->name('system_auth_node')->where(['auth' => 1, 'node' => $node])->find();
            if (empty($exists)) {
                $admin->name('system_auth_node')->insert(['auth' => 1, 'node' => $node]);
            }
        }
    }
}