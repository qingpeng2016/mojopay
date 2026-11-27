package entity

import (
	"time"
)

// APIIdempotency 对应：商户 API 写请求幂等与重复提交校验。
type APIIdempotency struct {
	ID                uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	MerchantAccountID uint64    `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	Operation         string    `gorm:"column:operation;type:varchar(64);not null"`                                                    // API 操作标识
	IdemKey           string    `gorm:"column:idem_key;type:varchar(128);not null"`                                                    // 请求幂等键
	RequestHash       []byte    `gorm:"column:request_hash;type:binary(32);not null"`                                                  // 请求内容指纹
	ResourceID        *uint64   `gorm:"column:resource_id;type:bigint unsigned"`                                                       // 幂等请求关联的业务主键
	ResponseRef       *string   `gorm:"column:response_ref;type:varchar(255)"`                                                         // 幂等请求响应存储引用
	Status            string    `gorm:"column:status;type:varchar(24);not null;default:PROCESSING"`                                    // 幂等请求处理状态
	ExpiresAt         time.Time `gorm:"column:expires_at;type:datetime(6);not null"`                                                   // 幂等记录到期时间
	CreatedAt         time.Time `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (APIIdempotency) TableName() string { return "api_idempotency" }
