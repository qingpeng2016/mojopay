package entity

import (
	"time"

	"gorm.io/datatypes"
)

// MerchantAPIKey 对应：商户 API 凭据、授权与访问策略。
type MerchantAPIKey struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	MerchantAccountID uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	KeyID             string          `gorm:"column:key_id;type:varchar(64);not null"`                                                       // API 密钥编号
	SecretRef         string          `gorm:"column:secret_ref;type:varchar(255);not null"`                                                  // API 密钥存储引用
	Scopes            datatypes.JSON  `gorm:"column:scopes;type:json;not null"`                                                              // 凭据授权范围
	Environment       string          `gorm:"column:environment;type:varchar(12);not null"`                                                  // 凭据环境（沙盒或生产）
	SignatureVersion  string          `gorm:"column:signature_version;type:varchar(12);not null;default:v1"`                                 // 请求签名协议版本
	IPAllowlist       *datatypes.JSON `gorm:"column:ip_allowlist;type:json"`                                                                 // 允许访问的来源地址段，空值为未配置
	RequestsPerMinute uint32          `gorm:"column:requests_per_minute;type:int unsigned;not null"`                                         // 每分钟请求配额
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:INACTIVE"`                                      // API 凭据启用状态
	ExpiresAt         *time.Time      `gorm:"column:expires_at;type:datetime(6)"`                                                            // API 凭据到期时间
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (MerchantAPIKey) TableName() string { return "merchant_api_key" }
