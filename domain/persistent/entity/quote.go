package entity

import (
	"time"

	"github.com/shopspring/decimal"
	"gorm.io/datatypes"
)

// Quote 对应：价格、费用、金额和有效期快照。
type Quote struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID          string          `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	MerchantAccountID uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	SourceAssetID     uint64          `gorm:"column:source_asset_id;type:bigint unsigned;not null"`                                          // 报价源资产主键
	TargetAssetID     uint64          `gorm:"column:target_asset_id;type:bigint unsigned;not null"`                                          // 报价目标资产主键
	ProviderAccountID *uint64         `gorm:"column:provider_account_id;type:bigint unsigned"`                                               // 上游账号主键
	Rate              decimal.Decimal `gorm:"column:rate;type:decimal(38,18);not null"`                                                      // 目标资产金额与源资产金额的比率
	SourceAmount      decimal.Decimal `gorm:"column:source_amount;type:decimal(36,0);not null"`                                              // 报价的源资产金额
	TargetAmount      decimal.Decimal `gorm:"column:target_amount;type:decimal(36,0);not null"`                                              // 报价的目标资产金额
	Fee               decimal.Decimal `gorm:"column:fee;type:decimal(36,0);not null;default:0"`                                              // 手续费金额
	FeeAssetID        uint64          `gorm:"column:fee_asset_id;type:bigint unsigned;not null"`                                             // 手续费资产主键
	PricingMode       string          `gorm:"column:pricing_mode;type:varchar(24);not null"`                                                 // 报价计价方式
	Terms             *datatypes.JSON `gorm:"column:terms;type:json"`                                                                        // 报价保价及成交条件
	ExpiresAt         time.Time       `gorm:"column:expires_at;type:datetime(6);not null"`                                                   // 报价到期时间
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (Quote) TableName() string { return "quote" }
