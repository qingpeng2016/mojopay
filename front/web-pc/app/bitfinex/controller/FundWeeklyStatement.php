<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\service\WeeklyStatementService;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\admin\service\AdminService;
use think\Paginator;

/**
 * 3.6.x 客户周账单
 */
class FundWeeklyStatement extends Controller
{
    use SafeQueryTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '周账单';
        $get         = $this->request->get();
        $service     = new WeeklyStatementService();

        // 当前 UTC 周若库中无记录，先补写占位行（无 PDF）
        $service->ensureCurrentWeekStubs();

        $weekStartInput = trim((string)($get['week_start'] ?? ''));
        [$weekStart, $weekEnd] = $service->resolveWeek($weekStartInput);

        $filters = [
            'client_id'      => $this->sanitizeId($get['client_id'] ?? ''),
            'statement_type' => $this->sanitizeEnum(
                $get['statement_type'] ?? '',
                ['FLOATING', 'GUARANTEED']
            ),
            'client_name'    => $this->sanitizeLike($get['client_name'] ?? ''),
        ];

        $allRows = $service->buildListRows($weekStart, $weekEnd, $filters);

        $pageSize = isset($get['pageSize']) ? min(100, max(10, intval($get['pageSize']))) : 20;
        $page     = isset($get['page']) ? max(1, intval($get['page'])) : 1;
        $total    = count($allRows);
        $list     = array_slice($allRows, ($page - 1) * $pageSize, $pageSize);

        $queryParams = array_filter([
            'week_start'     => $weekStartInput !== '' ? $weekStartInput : null,
            'client_id'      => $filters['client_id'] ?: null,
            'client_name'    => $get['client_name'] ?? null,
            'statement_type' => $filters['statement_type'] ?: null,
            'pageSize'       => $pageSize !== 20 ? $pageSize : null,
        ], static fn ($v) => $v !== null && $v !== '');

        $result = Paginator::make($list, $pageSize, $page, $total, false, [
            'query' => $queryParams,
            'path'  => '/admin.html#/bitfinex/fund_weekly_statement/index.html',
        ]);

        $this->assign([
            'list'            => $list,
            'pagehtml'        => $result->render(),
            'get'             => $get,
            'week_start'      => $weekStart,
            'week_end'        => $weekEnd,
            'week_range_fmt'  => $weekStart !== '' ? ($weekStart . ' ~ ' . $weekEnd) : '全部',
            'statement_types' => [
                'FLOATING'   => '进取浮动型',
                'GUARANTEED' => '稳健保底型',
            ],
        ]);

        return $this->fetch();
    }

    /**
     * 生成 PDF 报表
     *
     * @auth true
     */
    public function generate()
    {
        $clientId = $this->sanitizeId($this->request->post('client_id'));
        if (!$clientId) {
            return $this->error('客户 ID 无效');
        }

        $statementType = $this->sanitizeEnum(
            $this->request->post('statement_type'),
            ['FLOATING', 'GUARANTEED']
        );
        if ($statementType === null) {
            return $this->error('账单类型无效');
        }

        $weekStartInput = trim((string)$this->request->post('week_start', ''));
        $service        = new WeeklyStatementService();
        [$weekStart]    = $service->resolveWeek($weekStartInput);
        if ($weekStart === '') {
            return $this->error('请选择账单周');
        }

        $result = $service->generate(
            $clientId,
            $statementType,
            $weekStart,
            $this->currentOperator()
        );

        return $result['ok']
            ? $this->success($result['message'])
            : $this->error($result['message']);
    }

    /**
     * 下载已生成的 PDF
     *
     * @auth true
     */
    public function download()
    {
        $this->streamPdfResponse(false);
    }

    /**
     * 弹窗内联预览 PDF
     *
     * @auth true
     */
    public function preview()
    {
        $this->streamPdfResponse(true);
    }

    private function streamPdfResponse(bool $inline): void
    {
        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            $this->error('参数错误');
            return;
        }

        $service = new WeeklyStatementService();
        $result  = $service->resolveDownload($id);
        if (!$result['ok']) {
            $this->error($result['message']);
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filename = (string)$result['filename'];
        $encoded  = rawurlencode($filename);
        header('Content-Type: application/pdf');
        if ($inline) {
            header('Content-Disposition: inline; filename="' . $encoded . '"; filename*=UTF-8\'\'' . $encoded);
        } else {
            header('Content-Disposition: attachment; filename="' . $encoded . '"; filename*=UTF-8\'\'' . $encoded);
        }
        header('Content-Length: ' . (string)strlen((string)$result['content']));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        echo $result['content'];
        exit;
    }

    private function currentOperator(): string
    {
        if (class_exists(AdminService::class)) {
            if (method_exists(AdminService::class, 'getUserName')) {
                $name = AdminService::getUserName();
                if ($name !== null && $name !== '') {
                    return (string)$name;
                }
            }
            if (method_exists(AdminService::class, 'getUserId')) {
                $uid = AdminService::getUserId();
                if ($uid) {
                    return 'admin#' . $uid;
                }
            }
        }

        $user = $this->app->session->get('user');
        if (is_array($user)) {
            $username = (string)($user['username'] ?? '');
            if ($username !== '') {
                return $username;
            }
        }

        return '';
    }
}
