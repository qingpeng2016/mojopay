package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// Conversion 对应：实际执行的换汇操作。
type Conversion struct {
	ID                uint64           `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID          string           `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	PaymentOrderID    uint64           `gorm:"column:payment_order_id;type:bigint unsigned;not null"`                                         // 支付订单主键
	MerchantAccountID uint64           `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	QuoteID           uint64           `gorm:"column:quote_id;type:bigint unsigned;not null"`                                                 // 报价记录主键
	ProviderAccountID uint64           `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 上游账号主键
	SellAssetID       uint64           `gorm:"column:sell_asset_id;type:bigint unsigned;not null"`                                            // 换出资产主键
	BuyAssetID        uint64           `gorm:"column:buy_asset_id;type:bigint unsigned;not null"`                                             // 换入资产主键
	ProviderRequestNo string           `gorm:"column:provider_request_no;type:varchar(128);not null"`                                         // 提交上游的幂等请求号
	ProviderTradeID   *string          `gorm:"column:provider_trade_id;type:varchar(128)"`                                                    // 上游换汇成交单号
	SoldAmount        *decimal.Decimal `gorm:"column:sold_amount;type:decimal(36,0)"`                                                         // 实际换出金额
	BoughtAmount      *decimal.Decimal `gorm:"column:bought_amount;type:decimal(36,0)"`                                                       // 实际换入金额
	Fee               decimal.Decimal  `gorm:"column:fee;type:decimal(36,0);not null;default:0"`                                              // 换汇手续费
	FeeAssetID        uint64           `gorm:"column:fee_asset_id;type:bigint unsigned;not null"`                                             // 手续费资产主键
	Status            string           `gorm:"column:status;type:varchar(24);not null;default:CREATED"`                                       // 换汇执行状态
	CreatedAt         time.Time        `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time        `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (Conversion) TableName() string { return "conversion" }
