package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// DisputeCase 对应：渠道争议与拒付事实。
type DisputeCase struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID          string          `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	PaymentReceiptID  uint64          `gorm:"column:payment_receipt_id;type:bigint unsigned;not null"`                                       // 原支付到账记录主键
	MerchantAccountID uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	ProviderAccountID uint64          `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 上游账号主键
	AssetID           uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	ExternalDisputeID string          `gorm:"column:external_dispute_id;type:varchar(128);not null"`                                         // 上游争议单号
	Amount            decimal.Decimal `gorm:"column:amount;type:decimal(36,0);not null"`                                                     // 争议涉及金额
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:OPEN"`                                          // 争议处理状态
	EvidenceRef       *string         `gorm:"column:evidence_ref;type:varchar(255)"`                                                         // 证明材料存储引用
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (DisputeCase) TableName() string { return "dispute_case" }
