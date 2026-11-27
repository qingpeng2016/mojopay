package entity

import (
	"time"
)

// ProviderAccount 对应：上游主账号、机构子账号和运营账号。
type ProviderAccount struct {
	ID            uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	ProviderID    uint64    `gorm:"column:provider_id;type:bigint unsigned;not null"`                                              // 上游服务商主键
	ParentID      *uint64   `gorm:"column:parent_id;type:bigint unsigned"`                                                         // 上游父账号主键
	Environment   string    `gorm:"column:environment;type:varchar(12);not null"`                                                  // 上游环境（沙盒或生产）
	ExternalID    string    `gorm:"column:external_id;type:varchar(128);not null"`                                                 // 上游账号编号
	Role          string    `gorm:"column:role;type:varchar(24);not null"`                                                         // 上游账号用途
	CredentialRef *string   `gorm:"column:credential_ref;type:varchar(255)"`                                                       // 上游凭据存储引用
	Status        string    `gorm:"column:status;type:varchar(24);not null;default:INACTIVE"`                                      // 上游账号启用状态
	CreatedAt     time.Time `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt     time.Time `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (ProviderAccount) TableName() string { return "provider_account" }
