package entity

import (
	"time"
)

// AssetNetwork 对应：资产支持的链网络。
type AssetNetwork struct {
	ID              uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	AssetID         uint64    `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	NetworkCode     string    `gorm:"column:network_code;type:varchar(32);not null"`                                                 // 区块链网络代码
	ContractAddress *string   `gorm:"column:contract_address;type:varchar(160)"`                                                     // 代币合约地址
	Status          string    `gorm:"column:status;type:varchar(24);not null;default:INACTIVE"`                                      // 链网络启用状态
	CreatedAt       time.Time `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt       time.Time `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (AssetNetwork) TableName() string { return "asset_network" }
