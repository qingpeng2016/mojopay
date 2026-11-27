package entity

import (
	"time"
)

// OpenAPIRequestNonce 对应：商户 API 请求签名防重放。
type OpenAPIRequestNonce struct {
	APIKeyID   uint64    `gorm:"column:api_key_id;type:bigint unsigned;not null;primaryKey;autoIncrement:false"` // 商户 API 凭据主键
	NonceHash  []byte    `gorm:"column:nonce_hash;type:binary(32);not null;primaryKey"`                          // 已核验随机数的摘要
	AcceptedAt time.Time `gorm:"column:accepted_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6)"`      // 首次验签通过时间
	ExpiresAt  time.Time `gorm:"column:expires_at;type:datetime(6);not null"`                                    // 防重放记录到期时间
}

func (OpenAPIRequestNonce) TableName() string { return "openapi_request_nonce" }
