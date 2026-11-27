package entity

import (
	"time"

	"gorm.io/datatypes"
)

// MerchantWebhookEndpoint 对应：商户回调地址与验签凭据引用。
type MerchantWebhookEndpoint struct {
	ID                uint64         `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID          string         `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	MerchantAccountID uint64         `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	EndpointURL       string         `gorm:"column:endpoint_url;type:varchar(512);not null"`                                                // 商户回调地址
	SecretRef         string         `gorm:"column:secret_ref;type:varchar(255);not null"`                                                  // 回调签名密钥存储引用
	EventTypes        datatypes.JSON `gorm:"column:event_types;type:json;not null"`                                                         // 订阅的事件类型
	Status            string         `gorm:"column:status;type:varchar(24);not null;default:INACTIVE"`                                      // 回调配置启用状态
	CreatedAt         time.Time      `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time      `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (MerchantWebhookEndpoint) TableName() string { return "merchant_webhook_endpoint" }
