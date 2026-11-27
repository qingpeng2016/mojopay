<?php
declare (strict_types=1);

namespace app\data;

use think\facade\Db;
use think\admin\service\NodeService;
use think\Service as BaseService;

class Service extends BaseService
{
    public function boot()
    {
        // 新增控制器动作后，旧的持久节点缓存会把未知动作当成公开接口。
        // 仅在目标节点缺失时强制重扫，避免每个请求都清理/重建缓存。
        $methods = NodeService::getMethods();
        foreach ($this->archiveNodes() as $node) {
            $method = $methods[$node] ?? [];
            if (empty($method['isauth']) || !empty($method['ismenu'])) {
                NodeService::getMethods(true);
                break;
            }
        }
        // Kafka 归档复用现有“归一化数据”菜单；这里只同步操作权限，绝不重建菜单树。
        $this->ensureArchiveAccess();
    }

    private function archiveNodes(): array
    {
        return [
            'data/normalized/archive',
            'data/normalized/detail',
            'data/normalized/export',
        ];
    }

    private function ensureArchiveAccess(): void
    {
        try {
            // 清掉旧版本可能创建的独立菜单项；正常部署时这是空操作。
            Db::name('SystemMenu')->where('node', 'data/normalized/archive')->delete();

            // 仅让已经拥有“归一化数据”入口的角色继承归档操作权限。
            $authIds = Db::name('SystemAuthNode')
                ->where('node', 'data/normalized/index')->column('auth');
            foreach ($authIds as $authId) {
                foreach ($this->archiveNodes() as $node) {
                    $exists = Db::name('SystemAuthNode')
                        ->where(['auth' => $authId, 'node' => $node])->count();
                    if (!$exists) {
                        Db::name('SystemAuthNode')->insert(['auth' => $authId, 'node' => $node]);
                    }
                }
            }
        } catch (\Throwable $e) {
            // 权限自愈失败不能影响后台其它功能；显式登录校验仍会保护归档接口。
        }
    }
}
