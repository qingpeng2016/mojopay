package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// PaymentAttemptRepo 提供 payment_attempt 的持久化操作。
type PaymentAttemptRepo interface {
	ModelRepo[entity.PaymentAttempt]
}
