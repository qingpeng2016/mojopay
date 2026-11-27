<?php
declare(strict_types=1);

namespace app\backtest\service;

use think\facade\Db;

/**
 * SO 热更新共享密码、有效期及防暴力破解逻辑。
 */
class SoHotreloadPasswordService
{
    private const CONFIG_ID = 1;

    private function connection()
    {
        return Db::connect('db_f_data');
    }

    private function authTable()
    {
        return $this->connection()->name('placement_params_hotreload_auth_config');
    }

    private function attemptTable()
    {
        return $this->connection()->name('placement_params_hotreload_auth_attempt');
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $auth = $this->getOrInitializeAuth();
        $changedAt = strtotime((string)$auth['password_changed_at']);
        $validDays = (int)config('so_hotreload.password_valid_days', 7);
        $expiresAt = $changedAt + $validDays * 86400;

        return [
            'expired' => $expiresAt <= time(),
            'password_changed_at' => date('Y-m-d H:i:s', $changedAt),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'valid_days' => $validDays,
        ];
    }

    /**
     * 验证发布密码。过期密码仅允许在修改密码时验证。
     *
     * @return array{ok:bool,reason:string,info:string,retry_after?:int,locked_until?:string,attempts_remaining?:int}
     */
    public function verify(string $password, string $operator, string $ip, bool $allowExpired = false): array
    {
        $status = $this->status();
        if (!$allowExpired && $status['expired']) {
            return ['ok' => false, 'reason' => 'expired', 'info' => '密码已过期，请先修改密码'];
        }

        $attemptKey = hash('sha256', $operator . "\0" . $ip);
        $attempt = $this->attemptTable()->where('attempt_key', $attemptKey)->find();
        $now = time();
        if ($attempt && !empty($attempt['locked_until']) && strtotime((string)$attempt['locked_until']) > $now) {
            $retryAfter = max(1, strtotime((string)$attempt['locked_until']) - $now);
            $retryMinutes = (int)ceil($retryAfter / 60);
            return [
                'ok' => false,
                'reason' => 'locked',
                'info' => "密码错误次数过多，已锁定，请 {$retryMinutes} 分钟后再试",
                'retry_after' => $retryAfter,
                'locked_until' => (string)$attempt['locked_until'],
                'attempts_remaining' => 0,
            ];
        }

        $auth = $this->getOrInitializeAuth();
        if (!password_verify($password, (string)$auth['password_hash'])) {
            return $this->recordFailure($attemptKey, $operator, $ip);
        }

        $this->attemptTable()->where('attempt_key', $attemptKey)->delete();
        return ['ok' => true, 'reason' => '', 'info' => '验证成功'];
    }

    /** @return array{ok:bool,reason:string,info:string,retry_after?:int,locked_until?:string,attempts_remaining?:int} */
    private function recordFailure(string $attemptKey, string $operator, string $ip): array
    {
        $connection = $this->connection();
        $seededAt = date('Y-m-d H:i:s');
        // 先用原子 upsert 建立计数行，再在事务中 FOR UPDATE，避免并发首次失败漏计数。
        $connection->execute(
            'INSERT INTO placement_params_hotreload_auth_attempt '
            . '(attempt_key, operator, ip_address, failed_attempts, first_failed_at, locked_until, updated_at) '
            . 'VALUES (:attempt_key, :operator, :ip_address, 0, :first_failed_at, NULL, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE attempt_key = VALUES(attempt_key)',
            [
                'attempt_key' => $attemptKey,
                'operator' => mb_substr($operator, 0, 64),
                'ip_address' => mb_substr($ip, 0, 45),
                'first_failed_at' => $seededAt,
                'updated_at' => $seededAt,
            ]
        );
        return $connection->transaction(function () use ($connection, $attemptKey, $operator, $ip) {
            $table = $connection->name('placement_params_hotreload_auth_attempt');
            $rows = $connection->query(
                'SELECT * FROM placement_params_hotreload_auth_attempt WHERE attempt_key = :attempt_key FOR UPDATE',
                ['attempt_key' => $attemptKey]
            );
            $row = $rows[0] ?? null;
            $now = time();
            $windowSeconds = (int)config('so_hotreload.failure_window_minutes', 15) * 60;
            $maxAttempts = (int)config('so_hotreload.max_failed_attempts', 5);
            $lockSeconds = (int)config('so_hotreload.lock_minutes', 30) * 60;
            $firstFailedAt = $row ? strtotime((string)$row['first_failed_at']) : 0;
            $failedAttempts = (!$row || $firstFailedAt + $windowSeconds <= $now)
                ? 1
                : ((int)$row['failed_attempts'] + 1);
            $lockedUntil = $failedAttempts >= $maxAttempts ? date('Y-m-d H:i:s', $now + $lockSeconds) : null;
            $data = [
                'operator' => mb_substr($operator, 0, 64),
                'ip_address' => mb_substr($ip, 0, 45),
                'failed_attempts' => $failedAttempts,
                'first_failed_at' => (!$row || $firstFailedAt + $windowSeconds <= $now)
                    ? date('Y-m-d H:i:s', $now)
                    : $row['first_failed_at'],
                'locked_until' => $lockedUntil,
                'updated_at' => date('Y-m-d H:i:s', $now),
            ];
            $table->where('attempt_key', $attemptKey)->update($data);

            if ($lockedUntil !== null) {
                $lockMinutes = (int)config('so_hotreload.lock_minutes', 30);
                return [
                    'ok' => false,
                    'reason' => 'locked',
                    'info' => "密码连续错误 {$maxAttempts} 次，已锁定 {$lockMinutes} 分钟",
                    'retry_after' => $lockSeconds,
                    'locked_until' => $lockedUntil,
                    'attempts_remaining' => 0,
                ];
            }
            $attemptsRemaining = max(0, $maxAttempts - $failedAttempts);
            return [
                'ok' => false,
                'reason' => 'incorrect',
                'info' => "密码错误，拒绝执行；连续再错 {$attemptsRemaining} 次将被锁定",
                'attempts_remaining' => $attemptsRemaining,
            ];
        });
    }

    /** @return array{ok:bool,reason:string,info:string,retry_after?:int,locked_until?:string,attempts_remaining?:int} */
    public function changePassword(
        string $oldPassword,
        string $newPassword,
        string $operator,
        string $ip
    ): array {
        $oldCheck = $this->verify($oldPassword, $operator, $ip, true);
        if (!$oldCheck['ok']) {
            return $oldCheck;
        }

        $minLength = (int)config('so_hotreload.min_password_length', 6);
        $passwordLength = mb_strlen($newPassword, 'UTF-8');
        if ($passwordLength < $minLength || $passwordLength > 128) {
            return ['ok' => false, 'reason' => 'weak', 'info' => "新密码长度必须为 {$minLength} 至 128 个字符"];
        }
        if (password_verify($newPassword, (string)$this->getOrInitializeAuth()['password_hash'])) {
            return ['ok' => false, 'reason' => 'unchanged', 'info' => '新密码不能与旧密码相同'];
        }
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new SoHotreloadPasswordException('当前 PHP 环境不支持 Argon2id');
        }
        $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
        if ($newHash === false) {
            throw new SoHotreloadPasswordException('密码加密失败');
        }

        $connection = $this->connection();
        $connection->transaction(function () use ($connection, $oldPassword, $newHash, $operator, $ip) {
            $table = $connection->name('placement_params_hotreload_auth_config');
            $rows = $connection->query(
                'SELECT * FROM placement_params_hotreload_auth_config WHERE id = :id FOR UPDATE',
                ['id' => self::CONFIG_ID]
            );
            $auth = $rows[0] ?? null;
            if (!$auth || !password_verify($oldPassword, (string)$auth['password_hash'])) {
                throw new SoHotreloadPasswordException('密码已被其他人修改，请重新操作');
            }
            $oldHash = (string)$auth['password_hash'];
            $now = date('Y-m-d H:i:s');
            $table->where('id', self::CONFIG_ID)->update([
                'password_hash' => $newHash,
                'password_changed_at' => $now,
                'updated_at' => $now,
            ]);
            $connection->name('placement_params_hotreload_password_log')->insert([
                'operator' => $operator,
                'ip_address' => $ip,
                'old_password_hash' => $oldHash,
                'new_password_hash' => $newHash,
                'created_at' => $now,
            ]);
        });

        return ['ok' => true, 'reason' => '', 'info' => '密码修改成功'];
    }

    /** @return array<string,mixed> */
    private function getOrInitializeAuth(): array
    {
        $auth = $this->authTable()->where('id', self::CONFIG_ID)->find();
        if ($auth) {
            return (array)$auth;
        }

        $initialHash = trim((string)config('so_hotreload.initial_password_hash', ''));
        $hashInfo = $initialHash === '' ? [] : password_get_info($initialHash);
        if ($initialHash === '' || ($hashInfo['algoName'] ?? 'unknown') !== 'argon2id') {
            throw new SoHotreloadPasswordException('SO 热更新初始密码未配置，请在 config/so_hotreload.php 中填写有效的 initial_password_hash（Argon2id）');
        }
        $now = date('Y-m-d H:i:s');
        try {
            $this->authTable()->insert([
                'id' => self::CONFIG_ID,
                'password_hash' => $initialHash,
                'password_changed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $ignored) {
            // 并发首次访问可能已由另一个请求完成初始化，下面重新读取。
        }

        $auth = $this->authTable()->where('id', self::CONFIG_ID)->find();
        if (!$auth) {
            throw new SoHotreloadPasswordException('SO 热更新密码初始化失败');
        }
        return (array)$auth;
    }
}
