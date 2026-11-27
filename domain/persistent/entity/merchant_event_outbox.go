package entity

import (
	"time"

	"gorm.io/datatypes"
)

// MerchantEventOutbox 对应：按收件地址投递的商户出站事件。
type MerchantEventOutbox struct {
	ID                uint64         `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	MerchantAccountID uint64         `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	EndpointID        uint64         `gorm:"column:endpoint_id;type:bigint unsigned;not null"`                                              // 商户回调地址主键
	EventID           string         `gorm:"column:event_id;type:varchar(64);not null"`                                                     // 商户事件编号
	EventType         string         `gorm:"column:event_type;type:varchar(48);not null"`                                                   // 事件类型
	AggregateID       uint64         `gorm:"column:aggregate_id;type:bigint unsigned;not null"`                                             // 关联业务对象主键
	EventVersion      uint32         `gorm:"column:event_version;type:int unsigned;not null"`                                               // 事件版本号
	Payload           datatypes.JSON `gorm:"column:payload;type:json;not null"`                                                             // 投递给商户的事件数据
	Status            string         `gorm:"column:status;type:varchar(24);not null;default:PENDING"`                                       // 商户事件投递状态
	AttemptCount      uint32         `gorm:"column:attempt_count;type:int unsigned;not null;default:0"`                                     // 已尝试次数
	NextAttemptAt     time.Time      `gorm:"column:next_attempt_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6)"`                 // 下次投递时间
	CreatedAt         time.Time      `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time      `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (MerchantEventOutbox) TableName() string { return "merchant_event_outbox" }
