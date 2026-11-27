// +----------------------------------------------------------------------
// | Static Plugin for ThinkAdmin
// +----------------------------------------------------------------------
// | 官方网站: https://thinkadmin.top
// +----------------------------------------------------------------------
// | 版权所有 2014~2023 Anyon <zoujingli@qq.com>
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// | 免责声明 ( https://thinkadmin.top/disclaimer )
// +----------------------------------------------------------------------
// | gitee 代码仓库：https://gitee.com/zoujingli/think-plugs-static
// | github 代码仓库：https://github.com/zoujingli/think-plugs-static
// +----------------------------------------------------------------------
// | 自定义后台扩展脚本，需要在加载 admin.js 后载入
// | 使用 composer require zoujingli/think-plugs-static 时不会更新此文件
// +----------------------------------------------------------------------

$(function () {
    window.$body = $('body');

    /*! 初始化异步加载的内容扩展动作 */
    // $body.on('reInit', function (evt, $dom) {
    //     console.log('Event.reInit', $dom);
    // });

    /*! 追加 require 配置参数
    /*! 加载的文件不能与主配置重复 */
    // require.config({
    //     paths: {
    //         'vue': ['plugs/vue/vue.min'],
    //     },
    //     shim: {
    //         'vue': ['json']
    //     },
    // });
    // // 基于 Require 加载测试
    // require(['vue', 'md5'], function (vue, md5) {
    //     console.log(vue)
    //     console.log(md5.hash('content'))
    // });

    /*! 其他 javascript 脚本代码 */

    // 显示表格图片
    window.showTableImage = function (image, circle, size, title) {
        if (typeof image !== 'string' || image.length < 5) {
            return '<span class="color-desc">-</span>' + (title ? laytpl('<span class="margin-left-5">{{d.title}}</span>').render({title: title}) : '');
        }
        return laytpl('<div class="headimg {{d.class}} headimg-{{d.size}}" data-tips-image data-tips-hover data-lazy-src="{{d.image}}" style="{{d.style}}"></div>').render({
            size: size || 'ss', class: circle ? 'shadow-inset' : 'headimg-no', image: image, style: 'background-image:url(' + image + ');margin-right:0'
        }) + (title ? laytpl('<span class="margin-left-5">{{d.title}}</span>').render({title: title}) : '');
    };

    /*! 回测详情页局部自动刷新（SPA 注入内容，内联脚本不执行，故在此常驻处理） */
    var _bfTimer = null;
    var _bfSeq = 0; // 请求代次：页面切换/停止刷新时自增，使已发出的 $.get 回调失效

    /*! 体育市场盘口方向切换：事件委托保证 SPA 注入、自动刷新替换 DOM 后仍然可用。 */
    var _sportsBookDir = 'yes';
    function _sportsApplyBookDir() {
        var $root = $('.sports-detail, .sports-placement-detail').first();
        if (!$root.length) return;
        $root.find('.dir-btn').each(function () {
            var $btn = $(this), active = $btn.attr('data-dir') === _sportsBookDir;
            $btn.toggleClass('active', active).toggleClass('layui-btn-primary', !active);
        });
        $root.find('.book-dir').each(function () {
            $(this).toggle($(this).attr('data-dir') === _sportsBookDir);
        });
    }
    $(document).off('click.sportsBook').on('click.sportsBook', '.sports-detail .dir-btn, .sports-placement-detail .dir-btn', function () {
        _sportsBookDir = $(this).attr('data-dir') || 'yes';
        _sportsApplyBookDir();
    });

    /*! 体育市场库存与微观参数编辑。 */
    var _sportsParamSaving = false;
    $(document).off('click.sportsParamEdit').on('click.sportsParamEdit', '.sports-detail .edit-param-btn', function () {
        var $btn = $(this);
        var $cell = $btn.closest('td');
        var field = $btn.attr('data-field');
        var label = $btn.attr('data-label') || field;
        var current = $.trim($cell.find('.param-value').first().text());
        var isDistribution = field === 'micro_position_distribution' || /^\s*\[/.test(current);
        layui.use(['layer'], function () {
            var layer = layui.layer;
            layer.prompt({
                title: '编辑 ' + label,
                value: current,
                formType: isDistribution ? 2 : 0,
                area: isDistribution ? ['420px', '240px'] : ['320px', '180px']
            }, function (value, index) {
                value = $.trim(value);
                if (isDistribution) {
                    var distribution;
                    try {
                        distribution = JSON.parse(value);
                    } catch (e) {
                        layer.msg('请输入合法 JSON 数组，例如 [0.5,0.3,0.2]', {icon: 2});
                        return;
                    }
                    if (!Array.isArray(distribution) || distribution.length === 0 || distribution.some(function (item) {
                        return typeof item !== 'number' || !isFinite(item) || item < 0;
                    })) {
                        layer.msg('仓位分布必须是非空的非负数值数组', {icon: 2});
                        return;
                    }
                    value = JSON.stringify(distribution);
                } else if (value === '' || isNaN(value)) {
                    layer.msg('请输入数值', {icon: 2});
                    return;
                }
                if (_sportsParamSaving) {
                    layer.msg('正在保存，请稍候…', {icon: 0});
                    return;
                }
                _sportsParamSaving = true;
                $.ajax({
                    url: '/backtest/sports_market/saveparam.html',
                    type: 'POST',
                    dataType: 'json',
                    data: {id: $btn.attr('data-id'), field: field, value: value},
                    success: function (res) {
                        _sportsParamSaving = false;
                        if (res && parseInt(res.code, 10) === 1) {
                            layer.close(index);
                            $cell.find('.param-value').first().text(value);
                            layer.msg(res.info || '已更新，下一轮铺单生效', {icon: 1});
                        } else {
                            layer.msg((res && res.info) || '更新失败', {icon: 2});
                        }
                    },
                    error: function (xhr) {
                        _sportsParamSaving = false;
                        layer.msg('请求失败（HTTP ' + xhr.status + '）', {icon: 2});
                    }
                });
            });
        });
    });

    /*! 体育市场铺单历史保存开关。 */
    var _sportsHistorySaving = false;
    $(document).off('click.sportsHistorySetting').on('click.sportsHistorySetting', '.sports-detail .history-setting-btn', function () {
        var $btn = $(this);
        var enabled = parseInt($btn.attr('data-enabled'), 10) === 1 ? 1 : 0;
        layui.use(['layer'], function () {
            var layer = layui.layer;
            var save = function () {
                if (_sportsHistorySaving) {
                    layer.msg('正在保存，请稍候…', {icon: 0});
                    return;
                }
                _sportsHistorySaving = true;
                $btn.prop('disabled', true).addClass('layui-btn-disabled');
                $.ajax({
                    url: '/backtest/sports_market/savehistorysetting.html',
                    type: 'POST',
                    dataType: 'json',
                    data: {id: $btn.attr('data-id'), enabled: enabled},
                    success: function (res) {
                        if (res && parseInt(res.code, 10) === 1) {
                            var $setting = $('.sports-detail .history-setting').first();
                            var $currentBtn = $setting.find('.history-setting-btn');
                            $setting.find('.history-setting-state').text(enabled ? '已开启' : '已关闭');
                            $currentBtn.attr('data-enabled', enabled ? '0' : '1')
                                .text(enabled ? '停止保存' : '开启保存')
                                .toggleClass('layui-btn-danger', enabled === 1)
                                .toggleClass('layui-btn-normal', enabled === 0);
                            layer.msg(res.info || '设置已更新', {icon: 1});
                        } else {
                            layer.msg((res && res.info) || '更新失败', {icon: 2});
                        }
                    },
                    error: function (xhr) {
                        layer.msg('请求失败（HTTP ' + xhr.status + '）', {icon: 2});
                    },
                    complete: function () {
                        _sportsHistorySaving = false;
                        $('.sports-detail .history-setting-btn').prop('disabled', false).removeClass('layui-btn-disabled');
                    }
                });
            };
            if (enabled === 0) {
                layer.confirm('停止后将不再生成新铺单历史，已有历史不会删除。确认停止保存？', {title: '确认操作'}, function (index) {
                    layer.close(index);
                    save();
                });
            } else {
                save();
            }
        });
    });

    function _bfStop() {
        if (_bfTimer) { clearInterval(_bfTimer); _bfTimer = null; }
        _bfSeq++; // 作废所有在途刷新请求，避免旧盘口响应晚到覆盖当前详情页
    }
    function _bfStart() {
        _bfStop();
        var $box = $('#detail-content');
        if (!$box.length) return;
        var ms = parseInt($box.attr('data-refresh-ms') || '1000', 10);
        if (!(ms >= 1000)) ms = 1000;
        var seq = _bfSeq; // 本代次刷新请求标识
        _bfTimer = setInterval(function () {
            var route = (location.hash || '').replace(/^#/, '');
            if (!route) return;
            var mySeq = seq;
            $.get(route, function (html) {
                /*! 过期响应丢弃：页面已切换（_bfStop 已使 _bfSeq 自增）或 hash 已变更，
                    说明这次返回的不是当前详情页数据（如切换详情/返回列表后旧请求晚到），
                    若继续 diff/整块替换会把当前详情页覆盖成别的盘口。 */
                if (mySeq !== _bfSeq) return;
                if (((location.hash || '').replace(/^#/, '')) !== route) return;
                try {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    var nc = tmp.querySelector('#detail-content');
                    var oc = document.getElementById('detail-content');
                    if (!nc || !oc) return;
                    var oKv = oc.querySelectorAll('.kv-grid .kv-v');
                    var nKv = nc.querySelectorAll('.kv-grid .kv-v');
                    var oTds = oc.querySelectorAll('.module-table td');
                    var nTds = nc.querySelectorAll('.module-table td');
                    var payloads = oc.querySelectorAll('[data-quick-market-payload]');
                    for (var k = 0; k < payloads.length; k++) {
                        var oldPayload = payloads[k];
                        var kind = oldPayload.getAttribute('data-quick-market-payload');
                        var newPayload = nc.querySelector('[data-quick-market-payload="' + kind + '"]');
                        if (!newPayload) continue;
                        newPayload.open = oldPayload.open;
                        var oldText = oldPayload.querySelector('pre');
                        var newText = newPayload.querySelector('pre');
                        if (oldText && newText && oldText.textContent !== newText.textContent) {
                            oldText.textContent = newText.textContent;
                        }
                    }
                    /*! 行结构变化时（如档位条数变动）退化为整块替换 */
                    if (oKv.length !== nKv.length || oTds.length !== nTds.length) {
                        oc.innerHTML = nc.innerHTML;
                    } else {
                        /*! 局部刷新：结构固定，按位置 diff，只替换变动的单元格，避免整块闪烁 */
                        for (var i = 0; i < oKv.length; i++) {
                            if (oKv[i].textContent !== nKv[i].textContent) oKv[i].innerHTML = nKv[i].innerHTML;
                        }
                        for (var j = 0; j < oTds.length; j++) {
                            if (oTds[j].textContent !== nTds[j].textContent) oTds[j].innerHTML = nTds[j].innerHTML;
                        }
                    }
                    _sportsApplyBookDir();
                    /*! 配置的计算间隔若变化，动态切换定时器频率 */
                    var nms = parseInt(nc.getAttribute('data-refresh-ms') || '1000', 10);
                    if (nms >= 1000 && nms !== ms) {
                        oc.setAttribute('data-refresh-ms', nms);
                        _bfStop(); _bfStart();
                    }
                } catch (e) {}
            });
        }, ms);
    }
    $(document).on('reInit', function () {
        _bfStop();
        if ($('#detail-content').length) {
            _bfStart();
            _sportsBookDir = 'yes';
            _sportsApplyBookDir();
            var ms = parseInt($('#detail-content').attr('data-refresh-ms') || '1000', 10);
            if (!(ms >= 1000)) ms = 1000;
            var $btn = $('#btn-auto');
            if ($btn.length) $btn.text('自动刷新：' + (ms / 1000) + 's').addClass('layui-btn-warm').removeClass('layui-btn-primary');
        }
    });
    $(document).on('click', '#btn-auto', function () {
        if (_bfTimer) {
            _bfStop();
            $(this).text('自动刷新：关').removeClass('layui-btn-warm').addClass('layui-btn-primary');
        } else {
            _bfStart();
            var ms = parseInt($('#detail-content').attr('data-refresh-ms') || '1000', 10);
            if (!(ms >= 1000)) ms = 1000;
            $(this).text('自动刷新：' + (ms / 1000) + 's').addClass('layui-btn-warm').removeClass('layui-btn-primary');
        }
    });

    /*! 列表页点击单元格复制（左键）：含 data-copy 的由框架处理，这里复制普通文本 */
    function _copyText(t) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(t).catch(function () {});
        } else {
            var ta = document.createElement('textarea');
            ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
        }
    }
    $(document).on('click', '.table-block .layui-table td', function (e) {
        if ($(e.target).closest('a,button,input,select,textarea,.layui-badge').length) return;
        var td = $(this);
        if (td.find('[data-copy]').length) return; // 含 data-copy 的 json 单元格交给框架处理
        var text = (td.text() || '').trim();
        if (!text) return;
        _copyText(text);
        if (window.layer) window.layer.msg('已复制：' + (text.length > 50 ? text.slice(0, 50) + '…' : text), {time: 900});
    });
});
