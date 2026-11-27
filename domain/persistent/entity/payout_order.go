package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// PayoutOrder 对应：商户提现申请（V1 type=WITHDRAWAL）。
type PayoutOrder struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID          string          `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	MerchantAccountID uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	BeneficiaryID     uint64          `gorm:"column:beneficiary_id;type:bigint unsigned;not null"`                                           // 受益账户主键
	AssetID           uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	NetworkID         *uint64         `gorm:"column:network_id;type:bigint unsigned"`                                                        // 资产网络主键
	FreezeJournalID   *uint64         `gorm:"column:freeze_journal_id;type:bigint unsigned"`                                                 // 余额冻结凭证主键
	PayoutNo          string          `gorm:"column:payout_no;type:varchar(64);not null"`                                                    // 商户出款单号
	Type              string          `gorm:"column:type;type:varchar(16);not null"`                                                         // 出款类型（V1 固定 WITHDRAWAL）
	RequestedAmount   decimal.Decimal `gorm:"column:requested_amount;type:decimal(36,0);not null"`                                           // 申请出款金额
	Fee               decimal.Decimal `gorm:"column:fee;type:decimal(36,0);not null;default:0"`                                              // 预计出款手续费
	FeeMode           string          `gorm:"column:fee_mode;type:varchar(12);not null"`                                                     // 手续费承担方式（内扣或外收）
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:CREATED"`                                       // 出款申请状态
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (PayoutOrder) TableName() string { return "payout_order" }
