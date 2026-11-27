package repository

import (
	"context"

	"gorm.io/gorm"
)

// ReadRepo 查询一张表。where 的键、orderBy 和 groupBy 由服务端构造，用户输入通过参数绑定。
type ReadRepo[T any] interface {
	FindOne(ctx context.Context, tx *gorm.DB, where map[string]interface{}, orderBy string) (T, error)
	List(ctx context.Context, tx *gorm.DB, where map[string]interface{}, orderBy, groupBy string) (total int64, rows []T, err error)
}

// AppendRepo 新增记录。数据库生成的主键会填回入参。
type AppendRepo[T any] interface {
	ReadRepo[T]
	Create(ctx context.Context, tx *gorm.DB, row *T) error
	BatchCreate(ctx context.Context, tx *gorm.DB, rows []*T) error
}

// ModelRepo 提供可更新表的基本持久化操作。
type ModelRepo[T any] interface {
	AppendRepo[T]
	Update(ctx context.Context, tx *gorm.DB, where map[string]interface{}, updateData map[string]interface{}) error
}

// CleanupRepo 用于需要按条件清理的记录，例如已过期的随机数。
type CleanupRepo interface {
	Delete(ctx context.Context, tx *gorm.DB, where map[string]interface{}) error
}
