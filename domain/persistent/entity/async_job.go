package entity

import (
	"time"

	"gorm.io/datatypes"
)

// AsyncJob 对应：可恢复的查单、账单、对账及结算任务。
type AsyncJob struct {
	ID           uint64         `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	JobType      string         `gorm:"column:job_type;type:varchar(32);not null"`                                                     // 异步任务类型
	JobKey       string         `gorm:"column:job_key;type:varchar(180);not null"`                                                     // 任务幂等键
	Payload      datatypes.JSON `gorm:"column:payload;type:json;not null"`                                                             // 异步任务执行参数
	Status       string         `gorm:"column:status;type:varchar(24);not null;default:PENDING"`                                       // 异步任务状态
	AttemptCount uint32         `gorm:"column:attempt_count;type:int unsigned;not null;default:0"`                                     // 已尝试次数
	NextRunAt    time.Time      `gorm:"column:next_run_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6)"`                     // 下次执行时间
	LeaseOwner   *string        `gorm:"column:lease_owner;type:varchar(96)"`                                                           // 当前任务领取者
	LeaseUntil   *time.Time     `gorm:"column:lease_until;type:datetime(6)"`                                                           // 任务租约到期时间
	CreatedAt    time.Time      `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt    time.Time      `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (AsyncJob) TableName() string { return "async_job" }
