package entity

import (
	"github.com/shopspring/decimal"
)

// JournalEntry 对应：只追加的借贷分录。
type JournalEntry struct {
	ID              uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"` // 自增主键
	JournalID       uint64          `gorm:"column:journal_id;type:bigint unsigned;not null"`                  // 记账凭证主键
	LedgerAccountID uint64          `gorm:"column:ledger_account_id;type:bigint unsigned;not null"`           // 账本科目主键
	AssetID         uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                    // 分录记账资产主键
	LineNo          uint16          `gorm:"column:line_no;type:smallint unsigned;not null"`                   // 凭证分录行号
	Direction       string          `gorm:"column:direction;type:char(1);not null"`                           // 借贷方向（D借，C贷）
	Amount          decimal.Decimal `gorm:"column:amount;type:decimal(36,0);not null"`                        // 分录借方或贷方金额
}

func (JournalEntry) TableName() string { return "journal_entry" }
