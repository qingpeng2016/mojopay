package boot

import (
	"github.com/qingpeng2016/ai-agent-paper/application/bot"
	viplevelsync "github.com/qingpeng2016/ai-agent-paper/application/bot/scripts/vip_level_sync"
	botscheduleconfig "github.com/qingpeng2016/ai-agent-paper/application/core-service/bot_schedule_config"
	"github.com/qingpeng2016/ai-agent-paper/common/dederi/logger"
	"github.com/qingpeng2016/ai-agent-paper/conf"
	"github.com/qingpeng2016/ai-agent-paper/infrastructure/http"
	"github.com/qingpeng2016/ai-agent-paper/infrastructure/http/alipay"
	"github.com/qingpeng2016/ai-agent-paper/interfaces/rest"

	"go.uber.org/dig"
	"go.uber.org/zap/zapcore"
)

func init() {
	logger.NewLogger("mojopay", "./log", zapcore.DebugLevel)
}

func BuildContainer() *dig.Container {
	c := dig.New()

	cfg := conf.NewCfg()
	_ = c.Provide(func() *conf.Config { return cfg })
	_ = c.Provide(rest.NewRouter)

	_ = c.Provide(viplevelsync.NewVipLevelSyncJob)
	_ = c.Provide(bot.NewScheduler)
	_ = c.Provide(bot.NewEntry)
	_ = c.Provide(botscheduleconfig.NewBotScheduleConfigService)

	_ = c.Provide(NewDBClient)
	provideModels(c)

	_ = c.Provide(http.NewHTTPClient)
	_ = c.Provide(alipay.NewClient)

	return c
}
