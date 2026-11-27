package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// LedgerAccountRepo 提供 ledger_account 的持久化操作。
type LedgerAccountRepo interface {
	ModelRepo[entity.LedgerAccount]
}
