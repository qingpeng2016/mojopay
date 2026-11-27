package entity

import (
	"time"
)

// Provider 对应：上游商家（渠道）档案与接入配置。
type Provider struct {
	ID                   uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	Code                 string    `gorm:"column:code;type:varchar(32);not null"`                                                         // 上游商家编码
	Name                 string    `gorm:"column:name;type:varchar(128);not null"`                                                        // 上游商家名称
	OfficialURL          *string   `gorm:"column:official_url;type:varchar(512)"`                                                         // 官网地址
	APIBaseURLSandbox    *string   `gorm:"column:api_base_url_sandbox;type:varchar(512)"`                                                 // 沙盒 API 根地址
	APIBaseURLProduction *string   `gorm:"column:api_base_url_production;type:varchar(512)"`                                              // 生产 API 根地址
	DocsURL              *string   `gorm:"column:docs_url;type:varchar(512)"`                                                             // 开发者文档地址
	Remark               *string   `gorm:"column:remark;type:varchar(500)"`                                                               // 运营备注
	Status               string    `gorm:"column:status;type:varchar(24);not null;default:INACTIVE"`                                      // 上游商家启用状态
	CreatedAt time.Time `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt time.Time `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (Provider) TableName() string { return "provider" }
