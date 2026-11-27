package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// TransferOrder 对应：上游主账号与子账号间划拨指令。
type TransferOrder struct {
	ID                    uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	FromProviderAccountID uint64          `gorm:"column:from_provider_account_id;type:bigint unsigned;not null"`                                 // 转出上游账号主键
	ToProviderAccountID   uint64          `gorm:"column:to_provider_account_id;type:bigint unsigned;not null"`                                   // 转入上游账号主键
	AssetID               uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	ProviderRequestNo     string          `gorm:"column:provider_request_no;type:varchar(128);not null"`                                         // 提交上游的幂等请求号
	ProviderTransferID    *string         `gorm:"column:provider_transfer_id;type:varchar(128)"`                                                 // 上游划转单号
	Amount                decimal.Decimal `gorm:"column:amount;type:decimal(36,0);not null"`                                                     // 申请划转金额
	Status                string          `gorm:"column:status;type:varchar(24);not null;default:CREATED"`                                       // 划转处理状态
	CreatedAt             time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt             time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (TransferOrder) TableName() string { return "transfer_order" }
