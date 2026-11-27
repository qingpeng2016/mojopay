package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// PayoutFunding 对应：将出款金额分配到实际商户结算明细和上游资金位置。
type PayoutFunding struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PayoutID          uint64          `gorm:"column:payout_id;type:bigint unsigned;not null"`                                                // 出款申请主键
	SettlementItemID  uint64          `gorm:"column:settlement_item_id;type:bigint unsigned;not null"`                                       // 结算明细主键
	MerchantAccountID uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	AssetID           uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	ProviderAccountID uint64          `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 上游账号主键
	Amount            decimal.Decimal `gorm:"column:amount;type:decimal(36,0);not null"`                                                     // 已预留的资金来源金额
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:RESERVED"`                                      // 出款资金预留状态
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (PayoutFunding) TableName() string { return "payout_funding" }
