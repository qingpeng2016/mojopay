package repository

import "github.com/qingpeng2016/ai-agent-paper/domain/persistent/entity"

// PayoutAttemptRepo 提供 payout_attempt 的持久化操作。
type PayoutAttemptRepo interface {
	ModelRepo[entity.PayoutAttempt]
}
