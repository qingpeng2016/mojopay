<?php
declare (strict_types=1);

namespace app\rmpd\controller;
// 控制器类放在 app\rmpd\controller 命名空间下。
// ThinkAdmin 框架会根据 URL 路径自动找到对应的控制器类和方法：
//   URL /rmpd/risk_config/index  →  RiskConfig::index()

use think\admin\Controller;
// 引入 ThinkAdmin 的控制器基类。
// 我们自己的控制器继承它，就自动拥有：
//   $this->request   → 获取请求参数
//   $this->assign()  → 向模板传递变量
//   $this->fetch()   → 渲染并返回 HTML 模板
//   $this->success() → 返回成功 JSON 响应
//   $this->error()   → 返回失败 JSON 响应
//   $this->title     → 设置页面标题

use think\facade\Db;
// 引入 ThinkPHP 的数据库 Facade（门面）。
// Facade 是一种设计模式：提供一个简洁的静态接口（Db::xxx()），
// 内部自动处理连接池、事务等复杂逻辑。

/**
 * 风控配置（只读展示）
 *
 * 这个控制器只有一个职责：从数据库读取风控参数配置并展示。
 * 风控配置由策略团队通过后台直接修改数据库，此页面只做监控展示，
 * 不提供在线编辑功能。
 */
class RiskConfig extends Controller
// extends Controller 表示继承 ThinkAdmin 的 Controller 基类，
// 获得所有基础功能（模板渲染、请求处理等）。
{
    /**
     * 风控配置展示页
     *
     * @auth true  → ThinkAdmin 注解：表示访问此方法需要登录认证
     * @menu true  → ThinkAdmin 注解：表示在左侧菜单中显示这个页面的入口
     *
     * 这两个注解由 ThinkAdmin 框架扫描，不是 PHP 原生功能，
     * 框架通过解析注释块来决定权限控制和菜单生成。
     */
    public function index()
    {
        // $this->title 是父类 Controller 提供的属性，用于设置页面标题。
        // 框架会把这个值传给模板，显示在浏览器标签页和页面 header 区域。
        $this->title = '风控配置';

        // Db::connect('db_rmpd') → 使用名为 'db_rmpd' 的数据库连接（策略库）
        // ->name('risk_config')  → 操作 risk_config 表
        // ->find()               → 查询第一条记录，返回关联数组（或 null，如果表为空）
        //
        // ThinkPHP ORM 查询链：每个方法返回查询构造器对象，支持链式调用，
        // 最终 find()/select()/count() 等方法才真正执行 SQL 并返回结果。
        // 单行查询不用 find()：本表的 TINYINT(1) 字段（is_paused / is_enabled）会让
        // ThinkORM 的 find() 单行处理路径在类型 cast 阶段静默返回 NULL（getLastSql
        // 停在 SHOW FULL COLUMNS，真正的 SELECT 没跑到结果）。
        // 改用 select()->toArray() 取首行，绕开这个分支。
        $rows = Db::connect('db_rmpd')->name('risk_config')->limit(1)->select()->toArray();
        $config = $rows[0] ?? [];

        // $this->assign('变量名', 变量值) 把 PHP 变量传给 HTML 模板。
        // 模板中用 {$config} 或 {$config.字段名} 来读取这个值。
        //
        // $config ?: [] 是 PHP 的"或运算"语法：
        //   如果 $config 是"假值"（null、false、空数组等），就使用 [] 空数组。
        //   这样模板里取 $config.xxx 时不会因为 null 报错。
        $this->assign('config', $config ?: []);

        // $this->fetch() 渲染对应的 HTML 模板文件并返回响应。
        // ThinkAdmin 按约定自动找到模板路径：
        //   app/rmpd/view/risk_config/index.html
        // （控制器名转小写加下划线，方法名同理）
        return $this->fetch();
    }
}
