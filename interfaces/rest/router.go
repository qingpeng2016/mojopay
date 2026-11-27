package rest

import (
	"context"
	"errors"
	"fmt"
	"net"
	"net/http"
	"strings"
	"time"

	"github.com/gin-gonic/gin"
	"github.com/qingpeng2016/ai-agent-paper/application/checkout"
	"github.com/qingpeng2016/ai-agent-paper/common/dederi/gin/middleware"
	"github.com/qingpeng2016/ai-agent-paper/common/dederi/gin/response"
	"github.com/qingpeng2016/ai-agent-paper/common/notification"
	"github.com/qingpeng2016/ai-agent-paper/conf"
	openapiMiddleware "github.com/qingpeng2016/ai-agent-paper/interfaces/rest/middleware"
	"github.com/qingpeng2016/ai-agent-paper/interfaces/rest/handler"
	"go.uber.org/zap"
	"gorm.io/gorm"
)

type Router struct {
	httpServer *http.Server
	engine     *gin.Engine
	setting    *conf.Config
}

func NewRouter(setting *conf.Config, db *gorm.DB) (*Router, error) {
	if db == nil {
		return nil, fmt.Errorf("database client is required for REST router")
	}
	svc := checkout.NewService(db, setting)
	ch := handler.NewCheckoutHandler(svc)
	r := &Router{setting: setting}
	r.engine = r.buildEngine(svc, ch)
	return r, nil
}

func (r *Router) buildEngine(svc *checkout.Service, ch *handler.CheckoutHandler) *gin.Engine {
	engine := gin.Default()
	engine.Use(middleware.TraceRequestLog, middleware.CORSMiddleware)

	engine.GET("/api/v1/test", func(c *gin.Context) {
		response.ResponseSuccess(c, gin.H{"status": "ok"})
	})

	// 宿主收银台（付款 URL 指向此页）
	engine.GET("/checkout/:sessionID", ch.CheckoutPage)

	v1 := engine.Group("/api/v1")
	{
		pay := v1.Group("/payments")
		pay.Use(openapiMiddleware.MerchantOpenAPIAuth(svc))
		pay.POST("/checkout-sessions", ch.CreateSession)
	}
	return engine
}

func (r *Router) Run(setting *conf.Server) error {
	if setting == nil && r.setting != nil {
		setting = r.setting.ServerConf
	}
	if setting == nil || setting.Port == "" {
		return errors.New("server.port is required")
	}
	mode := strings.ToLower(strings.TrimSpace(setting.RunMode))
	switch mode {
	case "":
		mode = gin.ReleaseMode
	case gin.DebugMode, gin.ReleaseMode, gin.TestMode:
	default:
		return errors.New("server.run_mode must be debug, release, or test")
	}
	gin.SetMode(mode)

	listener, err := net.Listen("tcp", setting.Port)
	if err != nil {
		return err
	}
	r.httpServer = &http.Server{
		Addr:              setting.Port,
		Handler:           r.engine,
		ReadHeaderTimeout: 5 * time.Second,
	}
	go func(server *http.Server) {
		if err := server.Serve(listener); err != nil && !errors.Is(err, http.ErrServerClosed) {
			notification.SendErrorLog(context.Background(), "run http server failed", zap.Error(err))
			panic(err)
		}
	}(r.httpServer)
	return nil
}

func (r *Router) Close() {
	if r.httpServer == nil {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := r.httpServer.Shutdown(ctx); err != nil {
		notification.SendErrorLog(ctx, "stop http server failed", zap.Error(err))
		_ = r.httpServer.Close()
	}
}