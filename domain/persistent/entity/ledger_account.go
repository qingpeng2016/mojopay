package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// LedgerAccount 对应：按归属和资产划分的虚拟账本科目。
type LedgerAccount struct {
	ID          uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	AssetID     uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	ScopeType   string          `gorm:"column:scope_type;type:varchar(16);not null"`                                                   // 科目归属（商户、上游或平台）
	ScopeID     uint64          `gorm:"column:scope_id;type:bigint unsigned;not null"`                                                 // 科目归属对象主键
	AccountCode string          `gorm:"column:account_code;type:varchar(32);not null"`                                                 // 科目编码
	Balance     decimal.Decimal `gorm:"column:balance;type:decimal(36,0);not null;default:0"`                                          // 科目账面余额
	Version     uint64          `gorm:"column:version;type:bigint unsigned;not null;default:0"`                                        // 余额并发控制版本
	CreatedAt   time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt   time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (LedgerAccount) TableName() string { return "ledger_account" }
