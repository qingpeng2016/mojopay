package boot

import (
	"fmt"

	"github.com/qingpeng2016/ai-agent-paper/conf"
	"github.com/qingpeng2016/ai-agent-paper/infrastructure/mysql"
	"gorm.io/gorm"
)

func NewDBClient() (*gorm.DB, error) {
	if cfg := conf.GetMysqlMasterConf(); cfg == nil || cfg.Host == "" || cfg.DbName == "" {
		return nil, fmt.Errorf("bot requires db.host and db.db_name")
	}
	return mysql.GetDBClient(), nil
}
