package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// ProviderStatementItem 对应：归档的上游真实账单流水。
type ProviderStatementItem struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	ProviderAccountID uint64          `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 上游账号主键
	AssetID           uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	BillDate          time.Time       `gorm:"column:bill_date;type:date;not null"`                                                           // 渠道账单日期
	ExternalEntryKey  string          `gorm:"column:external_entry_key;type:varchar(180);not null"`                                          // 上游账单流水标识
	NetAmount         decimal.Decimal `gorm:"column:net_amount;type:decimal(36,0);not null"`                                                 // 上游流水净额
	BookedAt          time.Time       `gorm:"column:booked_at;type:datetime(6);not null"`                                                    // 渠道入账时间
	EvidenceRef       *string         `gorm:"column:evidence_ref;type:varchar(255)"`                                                         // 上游账单原文存储引用
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (ProviderStatementItem) TableName() string { return "provider_statement_item" }
