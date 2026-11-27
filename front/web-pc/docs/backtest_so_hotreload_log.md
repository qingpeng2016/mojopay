# Backtest SO Hot Reload Security and Log

Database: `fgmm_f_data`.

Migration scripts:

- `scripts/ddl/placement_params_hotreload_log.sql`: creates the hot reload operation log table.
- `scripts/ddl/placement_params_hotreload_password_20260916.sql`: creates the singleton password config, per-operator/IP failed-attempt state, and password-change audit tables in `fgmm_f_data`.
- `scripts/ddl/placement_params_hotreload_password_menu_20260916.sql`: existing-environment companion migration for the MIS main database. It only adds the new `hotreloadpreview` and `password` route permissions to existing roles; the password button itself is rendered inside the existing SO page and does not require a new `system_menu` row.
- `scripts/ddl/placement_params_strategy_capability_20260904.sql`: migrates SO params to `strategy + capability + profile + param_key` and rebuilds `placement_params_log`.
- `scripts/menu_backtest.sql`: incrementally initializes the complete Backtest menu and permissions in the MIS main database. It performs no deletes, preserves existing IDs, scopes the two parent menus exactly, and matches pages only by complete `backtest/...` nodes, so the separate `SO策略 → 快盘策略` tree is not affected.
- `scripts/apply_so_hotreload_password_20260916.sh`: one-command existing-environment password-feature installer. Because the MIS main database and `fgmm_f_data` may be on different servers, it uses each connection to apply the password DDL and its two new route permissions in sequence. Fresh environments should additionally run the complete `scripts/menu_backtest.sql` initialization.

## Password-protected release flow

1. Clicking **热更新** calls `hotreloadpreview`. The server checks password age before returning the complete current `placement_params` snapshot.
2. The browser shows the snapshot in a scrollable confirmation window.
3. After confirmation, the operator must enter the release password. `hotreload` checks expiry and verifies the password again on the server before Jenkins can be called. A SHA-256 digest binds execution to the snapshot that was displayed; if configuration changes in between, execution is rejected and a new preview is required.
4. An expired password redirects to `backtest/sofile/password`; changing it requires the old password and returns to the paginated SO config page.

Passwords are never stored in plaintext. `placement_params_hotreload_auth_config.password_hash` and both values in the password audit use Argon2id hashes. The audit also records the logged-in admin username, source IP, and time. Failed verification is limited by logged-in admin username plus source IP (defaults: 5 failures in 15 minutes, then locked for 30 minutes).

## Deployment configuration

Run the password DDL and main-database menu initialization before enabling this version. To apply both with their respective existing deployment database variables:

```bash
scripts/apply_so_hotreload_password_20260916.sh
```

The runner requires the existing `DB_HOST_F_BACKTEST`, `DB_PORT_F_BACKTEST`, `DB_USERNAME_F_BACKTEST`, `DB_PASSWORD_F_BACKTEST` variables for `fgmm_f_data`, plus `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` for the MIS main database.

Generate the initial password hash outside the repository:

```bash
php -r "echo password_hash('replace-with-initial-password', PASSWORD_ARGON2ID), PHP_EOL;"
```

Paste the complete output into `initial_password_hash` in `config/so_hotreload.php`. The application validates that it is an Argon2id hash, inserts it into the database on the first request, and starts its age at that time. It never treats this setting as plaintext and it does not overwrite an existing database password.

Runtime settings live in `config/so_hotreload.php`. The password validity period is
fixed at `7` days there. The remaining settings can be overridden with:

- `SO_HOTRELOAD_MAX_FAILED_ATTEMPTS` (default `5`)
- `SO_HOTRELOAD_FAILURE_WINDOW_MINUTES` (default `15`)
- `SO_HOTRELOAD_LOCK_MINUTES` (default `30`)
- `SO_HOTRELOAD_MIN_PASSWORD_LENGTH` (default `6`, and cannot be configured below `6`)

## Progress and verification (2026-09-16)

- Implemented: expiry gate, scrollable config preview, preview digest binding, password-confirmed execution, expired-password redirect, old-password-verified change, Argon2id storage, per-operator/IP lockout, password audit, DDL, and role-node initialization.
- Verified: all changed PHP files pass `php -l` on PHP 8.2.33; Argon2id hash/valid-password/invalid-password behavior passes a focused runtime check; both changed page scripts parse successfully with Node; `git diff --check` passes.
- Local Docker verified: authenticated config page and preview return 15 sample rows; each incorrect password response reports the remaining attempts; the fifth consecutive failure explicitly locks the admin/IP pair for 30 minutes and returns the unlock time; a correct password clears prior failures; 5-character passwords are rejected and 6-character passwords are accepted; password changes require the old password and create hash-only audit rows; an eight-day-old password redirects to the password page. The local lock was cleared and the original local test password restored after verification.
- Menu SQL verified: executed twice against a temporary MySQL 8 fixture containing both `SO策略 → 快盘策略` and another unrelated `快盘策略`; all six pre-existing menu IDs remained, exactly one Backtest root/group and five Backtest pages existed, and no duplicate menu or permission nodes were produced.
- External rollout pending: fill `initial_password_hash` in `config/so_hotreload.php`, run the one-command installer (or its two SQL files against their respective databases), clear application cache, and verify one live Jenkins release with an authorized operator. Live Jenkins was intentionally not triggered during code verification.

`so_config` snapshot structure:

```json
{
  "captured_at": "2026-09-04 12:00:00",
  "total": 2,
  "items": [
    {
      "strategy": "fastbook",
      "capability": "placement",
      "profile": "default",
      "param_key": "QCap",
      "param_value": "8000.0",
      "updated_at": "2026-09-04 11:59:00"
    }
  ]
}
```
