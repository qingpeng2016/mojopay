package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// RefundOrder 对应：原到账下的部分或全额退款。
type RefundOrder struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID          string          `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	PaymentReceiptID  uint64          `gorm:"column:payment_receipt_id;type:bigint unsigned;not null"`                                       // 原支付到账记录主键
	MerchantAccountID uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	ProviderAccountID uint64          `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 上游账号主键
	AssetID           uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	MerchantRefundNo  string          `gorm:"column:merchant_refund_no;type:varchar(128);not null"`                                          // 商户退款单号
	ProviderRequestNo string          `gorm:"column:provider_request_no;type:varchar(128);not null"`                                         // 提交上游的幂等请求号
	ProviderRefundID  *string         `gorm:"column:provider_refund_id;type:varchar(128)"`                                                   // 上游退款单号
	Amount            decimal.Decimal `gorm:"column:amount;type:decimal(36,0);not null"`                                                     // 申请退款金额
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:CREATED"`                                       // 退款处理状态
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (RefundOrder) TableName() string { return "refund_order" }
