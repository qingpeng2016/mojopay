package entity

import (
	"time"

	"gorm.io/datatypes"
)

// MerchantAccount 对应：下游商户主账号和子账号。
type MerchantAccount struct {
	ID          uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID    string          `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	MerchantID  uint64          `gorm:"column:merchant_id;type:bigint unsigned;not null"`                                              // 所属商户主键
	ParentID    *uint64         `gorm:"column:parent_id;type:bigint unsigned"`                                                         // 商户父账号主键
	Code        string          `gorm:"column:code;type:varchar(64);not null"`                                                         // 商户账号编码
	Status      string          `gorm:"column:status;type:varchar(24);not null;default:INACTIVE"`                                      // 商户账号启用状态
	BrandConfig *datatypes.JSON `gorm:"column:brand_config;type:json"`                                                                 // 收银台品牌配置
	CreatedAt   time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt   time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (MerchantAccount) TableName() string { return "merchant_account" }
