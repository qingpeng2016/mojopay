package entity

import (
	"time"
)

// ComplianceCase 对应：商户 KYB、最终用户 KYC 与交易验证。
type ComplianceCase struct {
	ID             uint64     `gorm:"column:id;type:bigint unsigned;not null;primaryKey;autoIncrement"`                              // 自增主键
	PublicID       string     `gorm:"column:public_id;type:varchar(64);not null"`                                                    // 对外资源编号
	MerchantID          uint64     `gorm:"column:merchant_id;type:bigint unsigned;not null"`                                              // 所属商户主键
	MerchantAccountID   *uint64    `gorm:"column:merchant_account_id;type:bigint unsigned"`                                                 // 关联商户主/子账号
	MerchantUserID      *uint64    `gorm:"column:merchant_user_id;type:bigint unsigned"`                                                  // 商户最终用户主键
	CapabilityID   *uint64    `gorm:"column:capability_id;type:bigint unsigned"`                                                     // 通道能力主键
	SubjectType    string     `gorm:"column:subject_type;type:varchar(16);not null"`                                                 // 核验对象类型（商户或最终用户）
	Purpose        string     `gorm:"column:purpose;type:varchar(24);not null"`                                                      // 资质核验用途
	Country        string     `gorm:"column:country;type:char(2);not null"`                                                          // 国家或地区代码
	Status         string     `gorm:"column:status;type:varchar(24);not null;default:PENDING"`                                       // 资质审核状态
	EvidenceRef    *string    `gorm:"column:evidence_ref;type:varchar(255)"`                                                         // 资质审核材料存储引用
	ExpiresAt      *time.Time `gorm:"column:expires_at;type:datetime(6)"`                                                            // 审核结果有效期截止时间
	CreatedAt      time.Time  `gorm:"column:created_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoCreateTime:false"` // 创建时间
	UpdatedAt      time.Time  `gorm:"column:updated_at;type:datetime(6);not null;default:CURRENT_TIMESTAMP(6);autoUpdateTime:false"` // 更新时间
}

func (ComplianceCase) TableName() string { return "compliance_case" }
