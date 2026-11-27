<?php
// PHP 文件必须以 <?php 开头。这是 PHP 的标准起始标记。

declare (strict_types=1);
// declare(strict_types=1) 表示开启"严格类型模式"。
// 在此模式下，如果传入函数的参数类型与声明不符，PHP 会直接报错而不是悄悄转换。
// 例如，一个声明接受 int 的函数，传入字符串 "3" 时会报错（宽松模式下会自动变成整数 3）。
// 这是现代 PHP 的最佳实践，让 Bug 更早暴露。

namespace app\rmpd;
// namespace（命名空间）相当于文件的"地址"，防止不同模块的类名冲突。
// 这里声明当前文件属于 app\rmpd 命名空间。
// 反斜杠 \ 是命名空间的分隔符（类似文件夹路径中的 /）。

use think\Service as BaseService;
// use 语句用于引入其他命名空间中的类，这样后面就不用写完整路径了。
// think\Service 是 ThinkPHP 框架提供的服务基类。
// "as BaseService" 是给它起一个别名，避免与当前文件的类名 Service 冲突。

class Service extends BaseService
// class 定义一个类（面向对象的基本单位）。
// extends BaseService 表示"继承"父类 BaseService，
// 也就是说当前 Service 类自动拥有 BaseService 的所有方法和属性。
// ThinkAdmin 框架会自动扫描并实例化所有继承自 BaseService 的类。
{
    public function boot()
    // public 表示这个方法可以被外部代码调用（与 private/protected 相对）。
    // function 定义一个方法（面向对象中叫方法，普通函数叫函数，本质一样）。
    // boot() 是 ThinkPHP 服务类的约定入口，框架启动时会自动调用它。
    // 这里 boot() 方法体为空，表示 rmpd 模块目前不需要在框架启动时做额外初始化。
    // 如果将来需要注册路由、绑定接口、初始化缓存等，可以在这里添加。
    {
    }
}
