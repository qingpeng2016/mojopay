package entity

import (
	"time"

	"gorm.io/datatypes"
)

// ChannelBinding 对应：商户账号和上游能力的授权及路由版本。
type ChannelBinding struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID          string          `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	MerchantAccountID uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	CapabilityID      uint64          `gorm:"column:capability_id;type:bigint unsigned;not null"`                                            // 通道能力主键
	Version           uint32          `gorm:"column:version;type:int unsigned;not null"`                                                     // 路由绑定版本
	Priority          *int32          `gorm:"column:priority;type:int;not null;default:100"`                                                 // 路由优先级（越小越优先）
	Weight            *uint32         `gorm:"column:weight;type:int unsigned;not null;default:1"`                                            // 同优先级的路由分配权重
	Terms             *datatypes.JSON `gorm:"column:terms;type:json"`                                                                        // 通道绑定费率及授权条件
	EffectiveFrom     time.Time       `gorm:"column:effective_from;type:datetime(6);not null"`                                               // 绑定生效时间
	EffectiveTo       *time.Time      `gorm:"column:effective_to;type:datetime(6)"`                                                          // 绑定失效时间
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:INACTIVE"`                                      // 路由绑定启用状态
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (ChannelBinding) TableName() string { return "channel_binding" }
