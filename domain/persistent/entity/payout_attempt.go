package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// PayoutAttempt 对应：真实上游出款指令及结果。
type PayoutAttempt struct {
	ID                uint64           `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PayoutID          uint64           `gorm:"column:payout_id;type:bigint unsigned;not null"`                                                // 出款申请主键
	ProviderAccountID uint64           `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 上游账号主键
	ProviderRequestNo string           `gorm:"column:provider_request_no;type:varchar(128);not null"`                                         // 提交上游的幂等请求号
	ProviderPayoutID  *string          `gorm:"column:provider_payout_id;type:varchar(128)"`                                                   // 上游出款单号
	Amount            decimal.Decimal  `gorm:"column:amount;type:decimal(36,0);not null"`                                                     // 本次上游出款金额
	ActualFee         *decimal.Decimal `gorm:"column:actual_fee;type:decimal(36,0)"`                                                          // 实际出款手续费
	ExecutionRef      *string          `gorm:"column:execution_ref;type:varchar(160)"`                                                        // 银行参考号或链上交易哈希
	Status            string           `gorm:"column:status;type:varchar(24);not null;default:CREATED"`                                       // 上游出款尝试状态
	CreatedAt         time.Time        `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time        `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (PayoutAttempt) TableName() string { return "payout_attempt" }
