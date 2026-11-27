package entity

import (
	"time"
)

// Asset 对应：资产字典。
type Asset struct {
	ID        uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	Code      string    `gorm:"column:code;type:varchar(16);not null"`                                                         // 资产代码（如BTC、USD）
	Kind      string    `gorm:"column:kind;type:varchar(12);not null"`                                                         // 资产类型（法币或加密资产）
	Decimals  uint8     `gorm:"column:decimals;type:tinyint unsigned;not null"`                                                // 资产小数位数
	CreatedAt time.Time `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt time.Time `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (Asset) TableName() string { return "asset" }
