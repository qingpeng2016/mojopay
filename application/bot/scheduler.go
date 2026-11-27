package bot

import (
	"context"
	"math/rand"
	"runtime/debug"
	"sync"
	"time"

	viplevelsync "github.com/qingpeng2016/ai-agent-paper/application/bot/scripts/vip_level_sync"
	"github.com/qingpeng2016/ai-agent-paper/common/dederi/logger"
	"github.com/qingpeng2016/ai-agent-paper/common/notification"
	"github.com/qingpeng2016/ai-agent-paper/conf"

	"github.com/go-co-op/gocron"
	"go.uber.org/zap"
)

const defaultVipSyncIntervalSec = 60

// Scheduler Bot 调度器（V1 不再依赖 bot_schedule_config 表）。
type Scheduler struct {
	vipLevelSyncJob *viplevelsync.VipLevelSyncJob
	ns              *gocron.Scheduler
	vipSyncJob      *gocron.Job
	started         bool
	mu              sync.Mutex
}

// NewScheduler 创建调度器
func NewScheduler(vipLevelSyncJob *viplevelsync.VipLevelSyncJob) *Scheduler {
	return &Scheduler{
		vipLevelSyncJob: vipLevelSyncJob,
		ns:              gocron.NewScheduler(time.Local),
		started:         false,
	}
}

// Handle 注册内置任务（非阻塞，可重复调用）
func (s *Scheduler) Handle(_ ...uint) {
	ctx := context.Background()
	s.mu.Lock()
	machineName := conf.Get_MACHINE_NAME()
	logger.DebugZ(ctx, "bot scheduler-LockAcquired", zap.String("machineName", machineName))
	defer s.mu.Unlock()
	defer func() {
		if r := recover(); r != nil {
			notification.SendErrorLog(ctx, "bot scheduler-Panic",
				zap.Any("panic", r),
				zap.String("stack", string(debug.Stack())))
		}
	}()

	if !s.started {
		s.ns.StartAsync()
		s.started = true
		logger.InfoZ(ctx, "bot scheduler-Started")
	}

	if s.vipSyncJob != nil {
		return
	}

	intervalDur := time.Duration(defaultVipSyncIntervalSec) * time.Second
	jobName := viplevelsync.Module + "-" + viplevelsync.TaskVipLevelSync
	job, err := s.ns.Every(intervalDur).
		Tag(jobName).
		Name(jobName).
		WaitForSchedule().
		SingletonMode().
		Do(func() {
			defer catch()
			s.vipLevelSyncJob.Run(context.Background())
		})
	if err != nil {
		notification.SendErrorLog(ctx, "bot scheduler-FailedToCreateJob", zap.Error(err))
		return
	}
	s.vipSyncJob = job
	logger.InfoZ(ctx, "bot scheduler-JobCreated",
		zap.String("jobName", jobName),
		zap.Int("intervalSec", defaultVipSyncIntervalSec))

	jitterMs := rand.Intn(1000)
	time.Sleep(time.Duration(jitterMs) * time.Millisecond)
}

// Stop 停止调度器
func (s *Scheduler) Stop() error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.vipSyncJob != nil {
		jobName := viplevelsync.Module + "-" + viplevelsync.TaskVipLevelSync
		_ = s.ns.RemoveByTags(jobName)
		s.vipSyncJob = nil
	}
	s.started = false
	s.ns.Stop()
	return nil
}