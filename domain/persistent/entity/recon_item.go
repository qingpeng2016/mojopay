package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// ReconItem 对应：账单与内部账本核对及差异处理。
type ReconItem struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	ProviderAccountID uint64          `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 上游账号主键
	StatementItemID   *uint64         `gorm:"column:statement_item_id;type:bigint unsigned"`                                                 // 上游账单流水主键
	JournalID         *uint64         `gorm:"column:journal_id;type:bigint unsigned"`                                                        // 记账凭证主键
	AssetID           uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	BillDate          time.Time       `gorm:"column:bill_date;type:date;not null"`                                                           // 渠道账单日期
	Difference        decimal.Decimal `gorm:"column:difference;type:decimal(36,0);not null;default:0"`                                       // 对账差额
	Reason            string          `gorm:"column:reason;type:varchar(32);not null"`                                                       // 对账差异原因编码
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:OPEN"`                                          // 对账差异处理状态
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (ReconItem) TableName() string { return "recon_item" }
