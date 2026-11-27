package entity

import (
	"time"

	"gorm.io/datatypes"
)

// BotScheduleConfig 对应：Bot 脚本调度。
type BotScheduleConfig struct {
	ID                    uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                        // 任务 ID
	Module                string          `gorm:"column:module;type:varchar(50);not null"`                                                 // 模块名
	TaskName              string          `gorm:"column:task_name;type:varchar(100);not null"`                                             // 任务名
	IntervalSeconds       float64         `gorm:"column:interval_seconds;type:float;not null;default:60"`                                  // 执行间隔（秒）
	ExeSort               *int32          `gorm:"column:exe_sort;type:int"`                                                                // 同模块内执行顺序
	Concurrency           *int32          `gorm:"column:concurrency;type:int"`                                                             // 并发度
	Description           *string         `gorm:"column:description;type:varchar(500)"`                                                    // 说明
	IsEnabled             *int8           `gorm:"column:is_enabled;type:tinyint(1);not null;default:1"`                                    // 是否启用
	IsStrategyEnabled     *int8           `gorm:"column:is_strategy_enabled;type:tinyint(1);default:1"`                                    // 策略是否启用
	LastLiveTime          *string         `gorm:"column:last_live_time;type:varchar(255)"`                                                 // 上次存活时间
	LastLiveTimeByMachine *datatypes.JSON `gorm:"column:last_live_time_by_machine;type:json"`                                              // 各机器上次存活
	IsPrimaryMachineRun   int32           `gorm:"column:is_primary_machine_run;type:int;not null;default:0"`                               // 是否主机器执行
	CreatedAt             time.Time       `gorm:"column:created_at;type:datetime;not null;default:CURRENT_TIMESTAMP;autoCreateTime:false"` // 创建时间
	UpdatedAt             time.Time       `gorm:"column:updated_at;type:datetime;not null;default:CURRENT_TIMESTAMP;autoUpdateTime:false"` // 更新时间
}

func (BotScheduleConfig) TableName() string { return "bot_schedule_config" }
