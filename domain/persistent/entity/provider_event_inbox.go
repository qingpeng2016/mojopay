package entity

import (
	"time"

	"gorm.io/datatypes"
)

// ProviderEventInbox 对应：已验签的上游回调接收记录。
type ProviderEventInbox struct {
	ID                uint64         `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	ProviderAccountID uint64         `gorm:"column:provider_account_id;type:bigint unsigned;not null"`                                      // 上游账号主键
	DedupKey          string         `gorm:"column:dedup_key;type:varchar(180);not null"`                                                   // 上游回调去重键
	EventType         string         `gorm:"column:event_type;type:varchar(48);not null"`                                                   // 事件类型
	Payload           datatypes.JSON `gorm:"column:payload;type:json;not null"`                                                             // 上游回调事件数据
	Status            string         `gorm:"column:status;type:varchar(24);not null;default:PENDING"`                                       // 上游事件处理状态
	ReceivedAt        time.Time      `gorm:"column:received_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6)"`                     // 回调接收时间
	CreatedAt         time.Time      `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time      `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (ProviderEventInbox) TableName() string { return "provider_event_inbox" }
