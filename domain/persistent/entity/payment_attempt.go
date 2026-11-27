package entity

import (
	"time"

	"github.com/shopspring/decimal"
	"gorm.io/datatypes"
)

// PaymentAttempt 对应：渠道选择与收款指令快照。
type PaymentAttempt struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PaymentOrderID    uint64          `gorm:"column:payment_order_id;type:bigint unsigned;not null"`                                         // 支付订单主键
	MerchantAccountID uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	CapabilityID      uint64          `gorm:"column:capability_id;type:bigint unsigned;not null"`                                            // 通道能力主键
	ChannelBindingID  uint64          `gorm:"column:channel_binding_id;type:bigint unsigned;not null"`                                       // 路由绑定主键
	ProviderAccountID uint64          `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 执行收款的上游账号主键
	PaymentAssetID    uint64          `gorm:"column:payment_asset_id;type:bigint unsigned;not null"`                                         // 付款资产主键
	NetworkID         *uint64         `gorm:"column:network_id;type:bigint unsigned"`                                                        // 资产网络主键
	ExpectedAmount    decimal.Decimal `gorm:"column:expected_amount;type:decimal(36,0);not null"`                                            // 预计应付金额
	ProviderRequestNo string          `gorm:"column:provider_request_no;type:varchar(128);not null"`                                         // 提交上游的幂等请求号
	ProviderOrderID   *string         `gorm:"column:provider_order_id;type:varchar(128)"`                                                    // 上游支付订单号
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:CREATED"`                                       // 上游支付尝试状态
	RouteSnapshot     datatypes.JSON  `gorm:"column:route_snapshot;type:json;not null"`                                                      // 路由选择及配置快照
	InstructionRef    *string         `gorm:"column:instruction_ref;type:varchar(255)"`                                                      // 支付指令存储引用
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (PaymentAttempt) TableName() string { return "payment_attempt" }
