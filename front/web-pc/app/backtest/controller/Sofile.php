<?php
declare (strict_types=1);

namespace app\backtest\controller;

use app\backtest\service\SoHotreloadPasswordService;
use app\backtest\service\SoHotreloadPasswordException;
use think\admin\Controller;
use think\admin\service\AdminService;
use think\facade\Db;
use think\facade\Validate;

/**
 * SO file config.
 *
 * Maintains fgmm_f_data.placement_params.
 * Primary key: strategy + capability + profile + param_key.
 */
class Sofile extends Controller
{
    private function table()
    {
        return Db::connect('db_f_data')->name('placement_params');
    }

    private function logTable()
    {
        return Db::connect('db_f_data')->name('placement_params_log');
    }

    private function hotreloadLogTable()
    {
        return Db::connect('db_f_data')->name('placement_params_hotreload_log');
    }

    private function hotreloadPasswordService(): SoHotreloadPasswordService
    {
        return new SoHotreloadPasswordService();
    }

    private function currentSoConfig(string $capturedAt): array
    {
        $items = $this->table()
            ->field('strategy,capability,profile,param_key,param_value,updated_at')
            ->order('strategy asc, capability asc, profile asc, param_key asc')
            ->select()
            ->toArray();

        return [
            'captured_at' => $capturedAt,
            'total' => count($items),
            'items' => $items,
        ];
    }

    private function soConfigDigest(array $config): string
    {
        $json = json_encode($config['items'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $json ?: '[]');
    }

    public function index()
    {
        $this->title = 'SO文件配置';
        $get = $this->request->get();

        $query = $this->table();
        if (!empty($get['strategy'])) {
            $query->where('strategy', 'like', "%{$get['strategy']}%");
        }
        if (!empty($get['capability'])) {
            $query->where('capability', 'like', "%{$get['capability']}%");
        }
        if (!empty($get['profile'])) {
            $query->where('profile', 'like', "%{$get['profile']}%");
        }
        if (!empty($get['param_key'])) {
            $query->where('param_key', 'like', "%{$get['param_key']}%");
        }

        $result = $query->order('strategy asc, capability asc, profile asc, param_key asc')->paginate([
            'list_rows' => isset($get['pageSize']) ? (int)$get['pageSize'] : 20,
            'page'      => isset($get['page']) ? (int)$get['page'] : 1,
            'query'     => $get,
            'path'      => '/admin.html#/backtest/sofile/index.html',
        ], false);

        $this->assign([
            'list'     => $result->items(),
            'pagehtml' => $result->render(),
            'get'      => $get,
        ]);
        return $this->fetch();
    }

    /**
     * Edit param_value only.
     * @auth true
     */
    public function edit()
    {
        $strategy = (string)$this->request->get('strategy', '');
        $capability = (string)$this->request->get('capability', '');
        $profile = (string)$this->request->get('profile', '');
        $paramKey = (string)$this->request->get('param_key', '');

        if ($this->request->isPost()) {
            $data = $this->request->post();
            $strategy = (string)($data['strategy'] ?? $strategy);
            $capability = (string)($data['capability'] ?? $capability);
            $profile = (string)($data['profile'] ?? $profile);
            $paramKey = (string)($data['param_key'] ?? $paramKey);

            $validate = Validate::rule([
                'strategy'    => 'require|max:32',
                'capability'  => 'require|max:32',
                'profile'     => 'require|max:64',
                'param_key'   => 'require|max:64',
                'param_value' => 'require',
            ])->message([
                'strategy.require'    => 'strategy 不能为空',
                'capability.require'  => 'capability 不能为空',
                'profile.require'     => 'profile 不能为空',
                'param_key.require'   => 'param_key 不能为空',
                'param_value.require' => 'param_value 不能为空',
            ]);
            if (!$validate->check($data)) {
                return json(['code' => 0, 'info' => $validate->getError()]);
            }

            $exist = $this->table()
                ->where('strategy', $strategy)
                ->where('capability', $capability)
                ->where('profile', $profile)
                ->where('param_key', $paramKey)
                ->find();
            if (!$exist) {
                return json(['code' => 0, 'info' => '记录不存在或已被删除']);
            }

            $oldValue = $exist['param_value'] ?? null;
            $newValue = $data['param_value'] ?? null;
            if ((string)$oldValue !== (string)$newValue) {
                $now = date('Y-m-d H:i:s');
                $this->table()
                    ->where('strategy', $strategy)
                    ->where('capability', $capability)
                    ->where('profile', $profile)
                    ->where('param_key', $paramKey)
                    ->update([
                        'param_value' => $newValue,
                        'updated_at'  => $now,
                    ]);

                $this->logTable()->insert([
                    'strategy'   => $strategy,
                    'capability' => $capability,
                    'profile'    => $profile,
                    'param_key'  => $paramKey,
                    'operator'   => AdminService::getUserName(),
                    'old_value'  => $oldValue,
                    'new_value'  => $newValue,
                    'created_at' => $now,
                ]);
                return json(['code' => 1, 'info' => '保存成功', 'url' => '#/backtest/sofile/index.html']);
            }
            return json(['code' => 1, 'info' => '值未变化，无需保存', 'url' => '#/backtest/sofile/index.html']);
        }

        $vo = $this->table()
            ->where('strategy', $strategy)
            ->where('capability', $capability)
            ->where('profile', $profile)
            ->where('param_key', $paramKey)
            ->find();
        if (!$vo) {
            return $this->error('记录不存在');
        }

        $logs = $this->logTable()
            ->where('strategy', $strategy)
            ->where('capability', $capability)
            ->where('profile', $profile)
            ->where('param_key', $paramKey)
            ->order('id desc')
            ->limit(20)
            ->select()
            ->toArray();

        $this->assign(['vo' => $vo, 'logs' => $logs]);
        return $this->fetch('form');
    }

    /**
     * Param edit logs.
     * @auth true
     */
    public function logs()
    {
        $this->title = 'SO文件配置 - 修改日志';
        $get = $this->request->get();

        $query = $this->logTable();
        if (!empty($get['strategy'])) {
            $query->where('strategy', 'like', "%{$get['strategy']}%");
        }
        if (!empty($get['capability'])) {
            $query->where('capability', 'like', "%{$get['capability']}%");
        }
        if (!empty($get['profile'])) {
            $query->where('profile', 'like', "%{$get['profile']}%");
        }
        if (!empty($get['param_key'])) {
            $query->where('param_key', 'like', "%{$get['param_key']}%");
        }
        if (!empty($get['operator'])) {
            $query->where('operator', 'like', "%{$get['operator']}%");
        }

        $result = $query->order('id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? (int)$get['pageSize'] : 20,
            'page'      => isset($get['page']) ? (int)$get['page'] : 1,
            'query'     => $get,
            'path'      => '/admin.html#/backtest/sofile/logs.html',
        ], false);

        $this->assign([
            'list'     => $result->items(),
            'pagehtml' => $result->render(),
            'get'      => $get,
        ]);
        return $this->fetch();
    }

    /**
     * SO hot reload logs.
     * @auth true
     */
    public function hotreloadlogs()
    {
        $this->title = 'SO文件配置 - 热更新日志';
        $get = $this->request->get();

        $query = $this->hotreloadLogTable();
        if (!empty($get['operator'])) {
            $query->where('operator', 'like', "%{$get['operator']}%");
        }
        if (isset($get['status']) && $get['status'] !== '') {
            $query->where('status', (int)$get['status']);
        }

        $result = $query->order('id desc')->paginate([
            'list_rows' => isset($get['pageSize']) ? (int)$get['pageSize'] : 20,
            'page'      => isset($get['page']) ? (int)$get['page'] : 1,
            'query'     => $get,
            'path'      => '/admin.html#/backtest/sofile/hotreloadlogs.html',
        ], false);

        $this->assign([
            'list'     => $result->items(),
            'pagehtml' => $result->render(),
            'get'      => $get,
        ]);
        return $this->fetch('hotreload_logs');
    }

    /**
     * Check password age and return the complete config for confirmation.
     * @auth true
     */
    public function hotreloadpreview()
    {
        if (!$this->request->isPost()) {
            return json(['code' => 0, 'info' => '请以 POST 方式调用']);
        }

        try {
            $status = $this->hotreloadPasswordService()->status();
            if ($status['expired']) {
                return json([
                    'code' => 0,
                    'reason' => 'expired',
                    'info' => '密码已过期，请先修改密码',
                    'url' => '#/backtest/sofile/password.html',
                ]);
            }
            $config = $this->currentSoConfig(date('Y-m-d H:i:s'));
            return json([
                'code' => 1,
                'info' => '请核对本次热更新配置',
                'password_expires_at' => $status['expires_at'],
                'config_digest' => $this->soConfigDigest($config),
                'config' => $config,
            ]);
        } catch (SoHotreloadPasswordException $e) {
            return json(['code' => 0, 'info' => $e->getMessage()]);
        } catch (\Throwable $e) {
            trace('[SO-Hotreload] preview failed: ' . $e->getMessage(), 'error');
            return json(['code' => 0, 'info' => 'SO 热更新密码服务暂不可用']);
        }
    }

    /**
     * Change the SO hot reload password.
     * @auth true
     */
    public function password()
    {
        $this->title = 'SO文件配置 - 修改发布密码';
        if (!$this->request->isPost()) {
            try {
                $this->assign('passwordStatus', $this->hotreloadPasswordService()->status());
            } catch (SoHotreloadPasswordException $e) {
                $this->assign('passwordStatus', ['error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                trace('[SO-Hotreload] password status failed: ' . $e->getMessage(), 'error');
                $this->assign('passwordStatus', ['error' => 'SO 热更新密码服务暂不可用']);
            }
            return $this->fetch('password');
        }

        $oldPassword = (string)$this->request->post('old_password', '');
        $newPassword = (string)$this->request->post('new_password', '');
        $confirmPassword = (string)$this->request->post('confirm_password', '');
        if ($oldPassword === '' || $newPassword === '') {
            return json(['code' => 0, 'info' => '旧密码和新密码不能为空']);
        }
        if ($newPassword !== $confirmPassword) {
            return json(['code' => 0, 'info' => '两次输入的新密码不一致']);
        }

        try {
            $result = $this->hotreloadPasswordService()->changePassword(
                $oldPassword,
                $newPassword,
                AdminService::getUserName(),
                $this->request->ip()
            );
            if (!$result['ok']) {
                return json(array_merge(['code' => 0], $result));
            }
            return json([
                'code' => 1,
                'info' => '密码修改成功，请继续热更新流程',
                'url' => '#/backtest/sofile/index.html',
            ]);
        } catch (SoHotreloadPasswordException $e) {
            return json(['code' => 0, 'info' => $e->getMessage()]);
        } catch (\Throwable $e) {
            trace('[SO-Hotreload] password change failed: ' . $e->getMessage(), 'error');
            return json(['code' => 0, 'info' => '密码修改失败，请稍后再试']);
        }
    }

    /**
     * Trigger Jenkins hot reload.
     * @auth true
     */
    public function hotreload()
    {
        if (!$this->request->isPost()) {
            return json(['code' => 0, 'info' => '请以 POST 方式调用']);
        }

        $operator = AdminService::getUserName();
        $password = (string)$this->request->post('password', '');
        $expectedConfigDigest = (string)$this->request->post('config_digest', '');
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedConfigDigest)) {
            return json(['code' => 0, 'info' => '请先预览并确认当前配置']);
        }
        try {
            $passwordCheck = $this->hotreloadPasswordService()->verify(
                $password,
                $operator,
                $this->request->ip()
            );
        } catch (SoHotreloadPasswordException $e) {
            return json(['code' => 0, 'info' => $e->getMessage()]);
        } catch (\Throwable $e) {
            trace('[SO-Hotreload] password verify failed: ' . $e->getMessage(), 'error');
            return json(['code' => 0, 'info' => 'SO 热更新密码服务暂不可用']);
        }
        if (!$passwordCheck['ok']) {
            $response = array_merge(['code' => 0], $passwordCheck);
            if ($passwordCheck['reason'] === 'expired') {
                $response['url'] = '#/backtest/sofile/password.html';
            }
            return json($response);
        }

        $createdAt = date('Y-m-d H:i:s');
        $currentConfig = $this->currentSoConfig($createdAt);
        if (!hash_equals($expectedConfigDigest, $this->soConfigDigest($currentConfig))) {
            return json(['code' => 0, 'reason' => 'config_changed', 'info' => '配置已发生变化，请重新预览确认']);
        }
        $soConfig = json_encode($currentConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: '{"captured_at":"' . $createdAt . '","total":0,"items":[]}';

        $cfg = config('database.jenkins', []);
        $url = $cfg['url'] ?? '';
        $user = $cfg['user'] ?? '';
        $token = $cfg['token'] ?? '';
        if (!$url) {
            $this->hotreloadLogTable()->insert([
                'operator'      => $operator,
                'status'        => 0,
                'http_code'     => 0,
                'error_message' => 'database.jenkins.url 未配置',
                'so_config'     => $soConfig,
                'created_at'    => $createdAt,
            ]);
            return json(['code' => 0, 'info' => '未配置 Jenkins 构建地址（database.jenkins.url）']);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $user . ':' . $token,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        $success = (!$curlErr && $httpCode >= 200 && $httpCode < 300) ? 1 : 0;
        $errorMessage = $curlErr ?: ($success ? '' : ('Jenkins HTTP ' . $httpCode . ': ' . substr((string)$body, 0, 500)));
        $this->hotreloadLogTable()->insert([
            'operator'      => $operator,
            'status'        => $success,
            'http_code'     => (int)$httpCode,
            'error_message' => $errorMessage,
            'so_config'     => $soConfig,
            'created_at'    => $createdAt,
        ]);

        if ($curlErr) {
            return json(['code' => 0, 'info' => '发送失败']);
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return json(['code' => 1, 'info' => '发送成功']);
        }
        return json(['code' => 0, 'info' => '发送失败']);
    }
}
