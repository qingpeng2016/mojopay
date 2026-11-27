package entity

import (
	"time"
)

// Journal 对应：单资产记账凭证及冲正引用。
type Journal struct {
	ID                uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`        // 自增主键
	AssetID           uint64    `gorm:"column:asset_id;type:bigint unsigned;not null"`                           // 凭证记账资产主键
	ReversedJournalID *uint64   `gorm:"column:reversed_journal_id;type:bigint unsigned"`                         // 被冲正的凭证主键
	EventKey          string    `gorm:"column:event_key;type:varchar(160);not null"`                             // 记账事件幂等键
	BusinessType      string    `gorm:"column:business_type;type:varchar(24);not null"`                          // 关联业务类型
	BusinessID        uint64    `gorm:"column:business_id;type:bigint unsigned;not null"`                        // 业务记录主键
	PostedAt          time.Time `gorm:"column:posted_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6)"` // 记账时间
}

func (Journal) TableName() string { return "journal" }
