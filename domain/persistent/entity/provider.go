package entity

import (
	"time"
)

// Provider 对应：上游服务商。
type Provider struct {
	ID        uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	Code      string    `gorm:"column:code;type:varchar(32);not null"`                                                         // 上游服务商编码
	Name      string    `gorm:"column:name;type:varchar(128);not null"`                                                        // 上游服务商名称
	Status    string    `gorm:"column:status;type:varchar(24);not null;default:INACTIVE"`                                      // 上游服务商启用状态
	CreatedAt time.Time `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt time.Time `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (Provider) TableName() string { return "provider" }
