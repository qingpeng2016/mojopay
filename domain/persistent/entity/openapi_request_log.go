package entity

import (
	"time"
)

// OpenAPIRequestLog 对应：脱敏的商户 API 请求审计。
type OpenAPIRequestLog struct {
	ID          uint64    `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"` // 自增主键
	RequestID   string    `gorm:"column:request_id;type:varchar(64);not null"`                      // 平台请求追踪编号
	APIKeyID    *uint64   `gorm:"column:api_key_id;type:bigint unsigned"`                           // 已验签的商户 API 凭据主键
	OperationID *uint64   `gorm:"column:operation_id;type:bigint unsigned"`                         // 匹配的接口目录主键
	HTTPStatus  uint16    `gorm:"column:http_status;type:smallint unsigned;not null"`               // 返回给商户的 HTTP 状态码
	ErrorCode   *string   `gorm:"column:error_code;type:varchar(64)"`                               // 返回给商户的业务错误码
	DurationMS  uint32    `gorm:"column:duration_ms;type:int unsigned;not null"`                    // 请求处理耗时（毫秒）
	ReceivedAt  time.Time `gorm:"column:received_at;type:datetime(6);not null"`                     // 请求接收时间
}

func (OpenAPIRequestLog) TableName() string { return "openapi_request_log" }
