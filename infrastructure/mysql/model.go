package mysql

import (
	"context"
	"sort"

	"github.com/qingpeng2016/ai-agent-paper/domain/persistent/repository"
	"gorm.io/gorm"
)

const modelInsertBatchSize = 100

type modelRepo[T any] struct {
	db *gorm.DB
}

func (r *modelRepo[T]) query(ctx context.Context, tx *gorm.DB, where map[string]interface{}) *gorm.DB {
	db := repository.GormDB(ctx, r.db, tx).Model(new(T))
	conditions := make([]string, 0, len(where))
	for condition := range where {
		conditions = append(conditions, condition)
	}
	sort.Strings(conditions)
	for _, condition := range conditions {
		db = db.Where(condition, where[condition])
	}
	return db
}

func (r *modelRepo[T]) FindOne(ctx context.Context, tx *gorm.DB, where map[string]interface{}, orderBy string) (T, error) {
	db := r.query(ctx, tx, where)
	if orderBy != "" {
		db = db.Order(orderBy)
	}
	var row T
	err := db.First(&row).Error
	return row, err
}

func (r *modelRepo[T]) List(ctx context.Context, tx *gorm.DB, where map[string]interface{}, orderBy, groupBy string) (int64, []T, error) {
	db := r.query(ctx, tx, where)
	if groupBy != "" {
		db = db.Group(groupBy)
	}

	var total int64
	if err := db.Session(&gorm.Session{}).Count(&total).Error; err != nil {
		return 0, nil, err
	}
	if orderBy != "" {
		db = db.Order(orderBy)
	}
	var rows []T
	if err := db.Find(&rows).Error; err != nil {
		return total, nil, err
	}
	return total, rows, nil
}

func (r *modelRepo[T]) Create(ctx context.Context, tx *gorm.DB, row *T) error {
	return repository.GormDB(ctx, r.db, tx).Create(row).Error
}

func (r *modelRepo[T]) BatchCreate(ctx context.Context, tx *gorm.DB, rows []*T) error {
	if len(rows) == 0 {
		return nil
	}
	return repository.GormDB(ctx, r.db, tx).CreateInBatches(rows, modelInsertBatchSize).Error
}

func (r *modelRepo[T]) Update(ctx context.Context, tx *gorm.DB, where map[string]interface{}, updateData map[string]interface{}) error {
	if len(where) == 0 {
		return gorm.ErrMissingWhereClause
	}
	if len(updateData) == 0 {
		return nil
	}
	return r.query(ctx, tx, where).Updates(updateData).Error
}

func (r *modelRepo[T]) Delete(ctx context.Context, tx *gorm.DB, where map[string]interface{}) error {
	if len(where) == 0 {
		return gorm.ErrMissingWhereClause
	}
	return r.query(ctx, tx, where).Delete(new(T)).Error
}
