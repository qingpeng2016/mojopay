package mysql

import (
	"context"

	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"
	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

type BotScheduleConfigImpl struct {
	modelRepo[entity.BotScheduleConfig]
}

func NewBotScheduleConfigImpl(db *gorm.DB) repository.BotScheduleConfigRepo {
	return &BotScheduleConfigImpl{modelRepo: modelRepo[entity.BotScheduleConfig]{db: db}}
}

func (r *BotScheduleConfigImpl) Create(ctx context.Context, tx *gorm.DB, row *entity.BotScheduleConfig) (uint, error) {
	if err := r.modelRepo.Create(ctx, tx, row); err != nil {
		return 0, err
	}
	return uint(row.ID), nil
}
