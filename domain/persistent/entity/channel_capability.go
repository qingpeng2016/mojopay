package entity

import (
	"time"

	"github.com/shopspring/decimal"
	"gorm.io/datatypes"
)

// ChannelCapability 对应：上游账户的收款、退款、换汇、划转及出款权限。
type ChannelCapability struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	ProviderAccountID uint64          `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 上游账号主键
	AssetID           uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 通道收付资产主键
	NetworkID         *uint64         `gorm:"column:network_id;type:bigint unsigned"`                                                        // 资产网络主键
	SettlementAssetID *uint64         `gorm:"column:settlement_asset_id;type:bigint unsigned"`                                               // 商户结算资产主键
	Operation         string          `gorm:"column:operation;type:varchar(24);not null"`                                                    // 通道支持的操作类型
	Method            string          `gorm:"column:method;type:varchar(32);not null"`                                                       // 渠道支付或出款方式
	Region            string          `gorm:"column:region;type:char(2);not null"`                                                           // 通道适用地区代码
	RegionName        *string         `gorm:"column:region_name;type:varchar(64)"`                                                           // 地区中文名称
	CapabilityKey     string          `gorm:"column:capability_key;type:varchar(64);not null"`                                               // 通道能力编码
	Version           *uint32         `gorm:"column:version;type:int unsigned;not null;default:1"`                                           // 能力配置版本
	MinAmount         decimal.Decimal `gorm:"column:min_amount;type:decimal(36,0);not null;default:0"`                                       // 单笔金额下限
	MaxAmount         decimal.Decimal `gorm:"column:max_amount;type:decimal(36,0);not null"`                                                 // 单笔金额上限
	Constraints       *datatypes.JSON `gorm:"column:constraints;type:json"`                                                                  // 通道限制与要求
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:INACTIVE"`                                      // 通道能力启用状态
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (ChannelCapability) TableName() string { return "channel_capability" }
