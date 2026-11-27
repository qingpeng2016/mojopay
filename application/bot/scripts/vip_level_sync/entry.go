package viplevelsync

import (
	"context"

	"github.com/qingpeng2016/ai-agent-paper/common/dederi/logger"
)

const (
	Module           = "ai_agent_paper"
	TaskVipLevelSync = "vip_level_sync"
)

type VipLevelSyncJob struct {
}

func NewVipLevelSyncJob() *VipLevelSyncJob {
	return &VipLevelSyncJob{}
}

func (j *VipLevelSyncJob) Run(ctx context.Context) {
	logger.InfoZ(ctx, "vip-level-sync-finished")
}
