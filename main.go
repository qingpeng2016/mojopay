package main

import (
	"context"
	"flag"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/qingpeng2016/ai-agent-paper/application/bot"
	"github.com/qingpeng2016/ai-agent-paper/boot"
	"github.com/qingpeng2016/ai-agent-paper/common/constants"
	"github.com/qingpeng2016/ai-agent-paper/common/dederi/logger"
	"github.com/qingpeng2016/ai-agent-paper/common/notification"
	"github.com/qingpeng2016/ai-agent-paper/conf"
	"github.com/qingpeng2016/ai-agent-paper/interfaces/rest"
	"go.uber.org/dig"
	"go.uber.org/zap"
)

func main() {
	flag.Parse()
	time.Local = constants.AppLocation

	container := boot.BuildContainer()
	signals := make(chan os.Signal, 1)
	signal.Notify(signals, syscall.SIGINT, syscall.SIGTERM)
	defer signal.Stop(signals)

	if conf.IsRunBot() {
		if err := container.Invoke(func(entry *bot.Entry) error {
			return entry.Start()
		}); err != nil {
			notification.SendErrorLog(context.Background(), "start bot failed", zap.Error(err))
			panic(err)
		}
	} else {
		if err := container.Invoke(func(router *rest.Router) error {
			return router.Run(conf.GetServeConf())
		}); err != nil {
			notification.SendErrorLog(context.Background(), "run http server failed", zap.Error(err))
			panic(err)
		}
	}

	gracefulExit(container, signals)
}

func gracefulExit(container *dig.Container, signals <-chan os.Signal) {
	<-signals
	ctx := context.Background()
	logger.InfoZ(ctx, "graceful-shutdown-start")

	if conf.IsRunBot() {
		if err := container.Invoke(func(entry *bot.Entry) error {
			return entry.Stop()
		}); err != nil {
			notification.SendErrorLog(ctx, "stop bot failed", zap.Error(err))
		}
	} else {
		if err := container.Invoke(func(router *rest.Router) {
			router.Close()
		}); err != nil {
			notification.SendErrorLog(ctx, "stop http server failed", zap.Error(err))
		}
	}

	logger.InfoZ(ctx, "graceful-shutdown-completed")
}
