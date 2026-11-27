package entity

import (
	"time"
)

// Merchant 对应：下游签约商户。
type Merchant struct {
	ID        uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID  string    `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	Code      string    `gorm:"column:code;type:varchar(48);not null"`                                                         // 商户编码
	LegalName string    `gorm:"column:legal_name;type:varchar(255);not null"`                                                  // 签约主体法定名称
	Country   string    `gorm:"column:country;type:char(2);not null"`                                                          // 商户注册国家或地区代码
	Category  string    `gorm:"column:category;type:varchar(32);not null"`                                                     // 商户经营类别
	Status    string    `gorm:"column:status;type:varchar(24);not null;default:PENDING"`                                       // 商户准入状态
	CreatedAt time.Time `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt time.Time `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (Merchant) TableName() string { return "merchant" }
