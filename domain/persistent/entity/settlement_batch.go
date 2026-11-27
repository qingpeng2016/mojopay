package entity

import (
	"time"

	"github.com/shopspring/decimal"
)

// SettlementBatch 对应：商户子账号按结算周期和资产生成的结算单。
type SettlementBatch struct {
	ID                uint64          `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID          string          `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	MerchantAccountID uint64          `gorm:"column:merchant_account_id;type:bigint unsigned;not null"`                                      // 商户账号主键
	AssetID           uint64          `gorm:"column:asset_id;type:bigint unsigned;not null"`                                                 // 资产主键
	BatchNo           string          `gorm:"column:batch_no;type:varchar(64);not null"`                                                     // 结算批次号
	WindowStart       time.Time       `gorm:"column:window_start;type:datetime(6);not null"`                                                 // 结算周期开始时间
	WindowEnd         time.Time       `gorm:"column:window_end;type:datetime(6);not null"`                                                   // 结算周期结束时间
	Gross             decimal.Decimal `gorm:"column:gross;type:decimal(36,0);not null;default:0"`                                            // 结算总额
	Fee               decimal.Decimal `gorm:"column:fee;type:decimal(36,0);not null;default:0"`                                              // 手续费金额
	Reserve           decimal.Decimal `gorm:"column:reserve;type:decimal(36,0);not null;default:0"`                                          // 冻结的准备金金额
	Net               decimal.Decimal `gorm:"column:net;type:decimal(36,0);not null;default:0"`                                              // 净结算金额
	Status            string          `gorm:"column:status;type:varchar(24);not null;default:PENDING"`                                       // 结算批次状态
	CreatedAt         time.Time       `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt         time.Time       `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (SettlementBatch) TableName() string { return "settlement_batch" }
