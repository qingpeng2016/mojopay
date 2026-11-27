package entity

import (
	"time"
)

// MerchantUser 对应：商户范围内识别的最终用户。
type MerchantUser struct {
	ID             uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID       string    `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	MerchantID     uint64    `gorm:"column:merchant_id;type:bigint unsigned;not null"`                                              // 所属商户主键
	ExternalUserID string    `gorm:"column:external_user_id;type:varchar(128);not null"`                                            // 商户侧用户编号
	Status         string    `gorm:"column:status;type:varchar(24);not null;default:ACTIVE"`                                        // 最终用户账号状态
	ProfileRef     *string   `gorm:"column:profile_ref;type:varchar(255)"`                                                          // 最终用户资料存储引用
	CreatedAt      time.Time `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt      time.Time `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (MerchantUser) TableName() string { return "merchant_user" }
