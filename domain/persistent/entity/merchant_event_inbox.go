package entity

import (
	"time"

	"gorm.io/datatypes"
)

// MerchantEventInbox 对应：商户余额快照与变动接收记录。
type MerchantEventInbox struct {
	ID                uint64         `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	MerchantAccountID uint64         `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	DedupKey          string         `gorm:"column:dedup_key;type:varchar(180);not null"`                                                   // 余额/流水事件去重键
	EventType         string         `gorm:"column:event_type;type:varchar(48);not null"`                                                   // 事件类型
	Payload           datatypes.JSON `gorm:"column:payload;type:json;not null"`                                                             // 余额流水事件数据
	Status            string         `gorm:"column:status;type:varchar(24);not null;default:PENDING"`                                       // 事件处理状态
	ReceivedAt        time.Time      `gorm:"column:received_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6)"`                     // 接收时间
	CreatedAt         time.Time      `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time      `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (MerchantEventInbox) TableName() string { return "merchant_event_inbox" }