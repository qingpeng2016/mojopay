package entity

import (
	"time"
)

// OpenAPIOperation 对应：下游 OpenAPI 接口目录和授权要求。
type OpenAPIOperation struct {
	ID                  uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	APIVersion          string    `gorm:"column:api_version;type:varchar(12);not null"`                                                  // 对外 API 版本
	OperationCode       string    `gorm:"column:operation_code;type:varchar(64);not null"`                                               // 接口操作编号
	HTTPMethod          string    `gorm:"column:http_method;type:varchar(8);not null"`                                                   // HTTP 请求方法
	PathTemplate        string    `gorm:"column:path_template;type:varchar(192);not null"`                                               // 对外接口路径模板
	RequiredScope       string    `gorm:"column:required_scope;type:varchar(64);not null"`                                               // 调用所需的权限范围
	IdempotencyRequired uint8     `gorm:"column:idempotency_required;type:tinyint unsigned;not null"`                                    // 是否要求写请求幂等键
	Status              string    `gorm:"column:status;type:varchar(24);not null;default:DRAFT"`                                         // 接口开通状态
	Description         string    `gorm:"column:description;type:varchar(200);not null"`                                                 // 接口用途
	CreatedAt           time.Time `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt           time.Time `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (OpenAPIOperation) TableName() string { return "openapi_operation" }
