package entity

import (
	"time"

	"gorm.io/datatypes"
)

// ApprovalCase 对应：大额订单、提现和资金调整审批。
type ApprovalCase struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID          string          `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	MerchantAccountID *uint64         `gorm:"column:merchant_account_id;type:bigint unsigned"`                                               // 商户账号主键
	BusinessID        uint64          `gorm:"column:business_id;type:bigint unsigned;not null"`                                              // 业务记录主键
	BusinessType      string          `gorm:"column:business_type;type:varchar(32);not null"`                                                // 关联业务类型
	InitiatorID       uint64          `gorm:"column:initiator_id;type:bigint unsigned;not null"`                                             // 审批发起人主键
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:PENDING"`                                       // 人工审批状态
	DecisionLog       *datatypes.JSON `gorm:"column:decision_log;type:json"`                                                                 // 审批记录
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (ApprovalCase) TableName() string { return "approval_case" }
