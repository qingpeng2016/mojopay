package entity

import (
	"time"
)

// MerchantWebhookDeliveryAttempt 对应：商户 Webhook 逐次投递记录。
type MerchantWebhookDeliveryAttempt struct {
	ID          uint64     `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`           // 自增主键
	OutboxID    uint64     `gorm:"column:outbox_id;type:bigint unsigned;not null"`                             // 商户出站事件主键
	AttemptNo   uint32     `gorm:"column:attempt_no;type:int unsigned;not null"`                               // 该事件的投递序号
	AttemptedAt time.Time  `gorm:"column:attempted_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6)"` // 发起投递时间
	FinishedAt  *time.Time `gorm:"column:finished_at;type:datetime(6)"`                                        // 投递结束时间
	HTTPStatus  *uint16    `gorm:"column:http_status;type:smallint unsigned"`                                  // 商户响应的 HTTP 状态码
	ErrorCode   *string    `gorm:"column:error_code;type:varchar(64)"`                                         // 投递失败原因编码
	Status      string     `gorm:"column:status;type:varchar(24);not null;default:IN_PROGRESS"`                // 本次投递状态
}

func (MerchantWebhookDeliveryAttempt) TableName() string { return "merchant_webhook_delivery_attempt" }
