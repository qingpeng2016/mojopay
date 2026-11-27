package repository

import (
	"context"

	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"gorm.io/gorm"
)

// BotScheduleConfigRepo 提供 bot_schedule_config 的持久化操作。
type BotScheduleConfigRepo interface {
	ReadRepo[entity.BotScheduleConfig]
	BatchCreate(ctx context.Context, tx *gorm.DB, rows []*entity.BotScheduleConfig) error
	Update(ctx context.Context, tx *gorm.DB, where map[string]interface{}, updateData map[string]interface{}) error
	Create(ctx context.Context, tx *gorm.DB, row *entity.BotScheduleConfig) (uint, error)
}
