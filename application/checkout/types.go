package checkout

// CreateSessionRequest 下游创建收银台会话。
type CreateSessionRequest struct {
	MerchantOrderNo string            `json:"merchant_order_no" binding:"required,max=128"`
	Amount          string            `json:"amount" binding:"required"` // 十进制金额，如 99.50
	Currency        string            `json:"currency" binding:"required,len=3"` // ISO 资产代码 USD/BTC
	SuccessURL      string            `json:"success_url"`
	CancelURL       string            `json:"cancel_url"`
	CustomerCountry string            `json:"customer_country"` // 可选 ISO2
	Metadata        map[string]string `json:"metadata"`
}

// CreateSessionResponse 返回收银台 URL。
type CreateSessionResponse struct {
	CheckoutSessionID string `json:"checkout_session_id"`
	PaymentOrderID    uint64 `json:"payment_order_id"`
	PublicID          string `json:"public_id"`
	MerchantOrderNo   string `json:"merchant_order_no"`
	CheckoutURL       string `json:"checkout_url"`
	ExpiresAt         string `json:"expires_at"`
	Status            string `json:"status"`
}

// PayMethodOption 收银台展示的支付方式（来自通道路由）。
type PayMethodOption struct {
	BindingID       uint64 `json:"binding_id"`
	AssetCode       string `json:"asset_code"`
	AssetKind       string `json:"asset_kind"` // FIAT | CRYPTO
	Operation       string `json:"operation"`
	Region          string `json:"region"`
	CapabilityKey   string `json:"capability_key"`
	Priority        int    `json:"priority"`
}

// PageView 收银台页面数据。
type PageView struct {
	SessionID       string
	MerchantName    string
	AccountCode     string
	OrderAmount     string
	Currency        string
	AmountDisplay   string
	Status          string
	FiatMethods     []PayMethodOption
	CryptoMethods   []PayMethodOption
	SuccessURL      string
	CancelURL       string
}