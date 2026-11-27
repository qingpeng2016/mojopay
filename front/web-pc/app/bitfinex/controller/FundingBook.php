<?php

declare (strict_types=1);

namespace app\bitfinex\controller;

use app\bitfinex\const\Enum;
use app\bitfinex\trait\RecordViewTrait;
use app\bitfinex\trait\SafeQueryTrait;
use think\admin\Controller;
use think\db\Query;
use think\facade\Db;

/**
 * 订单簿数据（funding_book，只读 + 按日期范围导出 CSV）
 */
class FundingBook extends Controller
{
    use SafeQueryTrait;
    use RecordViewTrait;

    /**
     * @auth true
     * @menu true
     */
    public function index()
    {
        $this->title = '订单簿数据';
        $get = $this->request->get();

        $pageQuery = $this->filteredQuery($get)->order('bucket_start', 'desc')->order('id', 'desc');
        $result = $pageQuery->paginate([
            'list_rows' => isset($get['pageSize'])
                ? min(100, max(10, intval($get['pageSize'])))
                : 20,
            'page'      => isset($get['page']) ? max(1, intval($get['page'])) : 1,
            'query'     => $get,
            'path'      => '/admin.html#/bitfinex/funding_book/index.html',
        ], false);

        $list = $result->items();
        foreach ($list as &$row) {
            $row = $this->formatRow($row);
        }
        unset($row);

        $this->assign([
            'list'       => $list,
            'pagehtml'   => $result->render(),
            'get'        => $get,
            'symbols'    => array_combine(Enum::getAllFundingSymbols(), Enum::getAllFundingSymbols()),
            'export_url' => (string)url('export'),
        ]);

        return $this->fetch();
    }

    /**
     * @auth true
     */
    public function detail()
    {
        $id = $this->sanitizeId($this->request->get('id'));
        if (!$id) {
            return $this->error('参数错误');
        }

        $vo = $this->loadRow($id);
        if ($vo === null) {
            return $this->error('记录不存在');
        }

        $this->assign('vo', $vo);
        return $this->fetch();
    }

    /**
     * 导出 CSV（未选日期时默认最近 30 天，最多 31 天）
     *
     * @auth true
     */
    public function export()
    {
        $get = $this->request->get();

        [$start, $end] = $this->resolveExportDateRange($get);
        if (empty($get['date_range'])) {
            $get['date_range'] = "{$start} - {$end}";
        }

        $rangeErr = $this->validateExportDateRange($start, $end);
        if ($rangeErr !== null) {
            return $this->error($rangeErr);
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filename = $this->buildCsvExportFilename('订单簿数据', $start, $end);
        $this->sendCsvDownloadHeaders($filename);
        $fp = fopen('php://output', 'w');
        if ($fp === false) {
            return $this->error('无法创建导出流');
        }
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            'id', 'symbol', 'bucket_start', 'book_prec', 'book_len', 'fetched_at_ms',
            'borrow_book_json', 'lend_book_json', 'created_at',
        ], ',', '"', '');

        $query = $this->filteredQuery($get, false)
            ->order('bucket_start', 'asc')
            ->order('id', 'asc');

        foreach ($query->cursor() as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            fputcsv($fp, [
                $row['id'] ?? '',
                $row['symbol'] ?? '',
                $row['bucket_start'] ?? '',
                $row['book_prec'] ?? '',
                $row['book_len'] ?? '',
                $row['fetched_at_ms'] ?? '',
                $this->csvJsonCell($row['borrow_book_json'] ?? null),
                $this->csvJsonCell($row['lend_book_json'] ?? null),
                $row['created_at'] ?? '',
            ], ',', '"', '');
        }

        fclose($fp);
        exit;
    }

    /**
     * @param mixed $value
     */
    private function csvJsonCell($value): string
    {
        if ($value === null || $value === '') {
            return '[]';
        }
        if (is_string($value)) {
            return $value;
        }
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $get
     */
    private function filteredQuery(array $get, bool $defaultLast30Days = true): Query
    {
        /** @var Query $query */
        $query = Db::connect('db_bitfinex')->name('funding_book');
        $this->applyDateRangeFilter($query, $get, 'bucket_start', $defaultLast30Days);

        if (!empty($get['id'])) {
            $id = $this->sanitizeId($get['id']);
            if ($id) {
                $query->where('id', $id);
            }
        }

        if (!empty($get['symbol'])) {
            $symbol = $this->sanitizeEnum($get['symbol'], Enum::getAllFundingSymbols());
            if ($symbol) {
                $query->where('symbol', $symbol);
            }
        }

        if (!empty($get['book_prec'])) {
            $prec = $this->sanitizeLike((string)$get['book_prec'], 16);
            $query->where('book_prec', $prec);
        }

        if (isset($get['book_len']) && $get['book_len'] !== '') {
            if (filter_var($get['book_len'], FILTER_VALIDATE_INT) !== false && (int)$get['book_len'] >= 0) {
                $query->where('book_len', (int)$get['book_len']);
            }
        }

        return $query;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRow(int $id): ?array
    {
        $row = Db::connect('db_bitfinex')
            ->name('funding_book')
            ->where('id', $id)
            ->find();

        if (empty($row)) {
            return null;
        }

        return $this->formatRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(array $row): array
    {
        $row['bucket_start'] = $this->formatDatetime($row['bucket_start'] ?? null);
        $row['fetched_at_fmt'] = $this->formatTsMs($row['fetched_at_ms'] ?? 0);
        $row['borrow_book_json_display'] = $this->jsonForDisplay($row['borrow_book_json'] ?? null);
        $row['lend_book_json_display'] = $this->jsonForDisplay($row['lend_book_json'] ?? null);
        $row['created_at'] = $this->formatDatetime($row['created_at'] ?? null);

        return $row;
    }
}
