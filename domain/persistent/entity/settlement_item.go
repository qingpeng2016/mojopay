package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// SettlementItem 对应：关联结算批次和原始分录行的结算明细。
type SettlementItem struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	SettlementBatchID uint64          `gorm:"column:settlement_batch_id;type:bigint unsigned;not null"`                                      // 结算批次主键
	MerchantAccountID uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	AssetID           uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	SourceJournalID   uint64          `gorm:"column:source_journal_id;type:bigint unsigned;not null"`                                        // 结算来源凭证主键
	SourceLineNo      uint16          `gorm:"column:source_line_no;type:smallint unsigned;not null"`                                         // 结算来源分录行号
	ProviderAccountID uint64          `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 上游账号主键
	Net               decimal.Decimal `gorm:"column:net;type:decimal(36,0);not null"`                                                        // 净结算金额
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:PENDING"`                                       // 结算明细状态
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (SettlementItem) TableName() string { return "settlement_item" }
