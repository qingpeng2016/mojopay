package handler

import (
	"fmt"
	"html/template"
	"net/http"
	"strings"

	"github.com/gin-gonic/gin"
	"github.com/qingpeng2016/ai-agent-paper/application/checkout"
	"github.com/qingpeng2016/ai-agent-paper/common/dederi/gin/response"
	"github.com/qingpeng2016/ai-agent-paper/interfaces/rest/middleware"
)

type CheckoutHandler struct {
	svc *checkout.Service
}

func NewCheckoutHandler(svc *checkout.Service) *CheckoutHandler {
	return &CheckoutHandler{svc: svc}
}

// CreateSession POST /api/v1/payments/checkout-sessions
func (h *CheckoutHandler) CreateSession(c *gin.Context) {
	auth, ok := middleware.GetAuthMerchant(c)
	if !ok {
		response.ResponseErr(c, fmt.Errorf("unauthorized"))
		return
	}
	var req checkout.CreateSessionRequest
	if err := c.ShouldBindJSON(&req); err != nil {
		response.ResponseErr(c, err)
		return
	}
	res, err := h.svc.CreateSession(c.Request.Context(), auth, req)
	if err != nil {
		response.ResponseErr(c, err)
		return
	}
	response.ResponseSuccess(c, res)
}

// CheckoutPage GET /checkout/:sessionID
func (h *CheckoutHandler) CheckoutPage(c *gin.Context) {
	sessionID := strings.TrimSpace(c.Param("sessionID"))
	token := strings.TrimSpace(c.Query("t"))
	view, err := h.svc.LoadCheckoutPage(c.Request.Context(), sessionID, token)
	if err != nil {
		c.Data(http.StatusBadRequest, "text/html; charset=utf-8", []byte(checkoutErrorHTML(template.HTMLEscapeString(err.Error()))))
		return
	}
	c.Data(http.StatusOK, "text/html; charset=utf-8", []byte(renderCheckoutHTML(view)))
}

func checkoutErrorHTML(msg string) string {
	return fmt.Sprintf(`<!DOCTYPE html><html><body style="font-family:system-ui;padding:40px;color:#c00"><h2>无法打开收银台</h2><p>%s</p></body></html>`, msg)
}

func renderCheckoutHTML(v *checkout.PageView) string {
	fiatRows := ""
	for _, m := range v.FiatMethods {
		fiatRows += fmt.Sprintf(`<li><label><input type="radio" name="pay" disabled> %s · %s · %s</label></li>`,
			template.HTMLEscapeString(m.AssetCode), template.HTMLEscapeString(m.Region), template.HTMLEscapeString(m.CapabilityKey))
	}
	if fiatRows == "" {
		fiatRows = `<li class="muted">暂无法币通道</li>`
	}
	cryptoRows := ""
	for _, m := range v.CryptoMethods {
		cryptoRows += fmt.Sprintf(`<li><label><input type="radio" name="pay" disabled> %s · %s · %s</label></li>`,
			template.HTMLEscapeString(m.AssetCode), template.HTMLEscapeString(m.Region), template.HTMLEscapeString(m.CapabilityKey))
	}
	if cryptoRows == "" {
		cryptoRows = `<li class="muted">暂无加密货币通道</li>`
	}
	return fmt.Sprintf(`<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>MojoPay Checkout</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#f4f6f8;color:#1a1a1a}
.wrap{max-width:480px;margin:24px auto;padding:0 16px 40px}
.card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:24px;margin-top:16px}
h1{font-size:20px;margin:0 0 4px}
.muted{color:#666;font-size:13px}
.amount{font-size:32px;font-weight:600;color:#0a7c6f;margin:16px 0}
h2{font-size:14px;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:20px 0 8px}
ul{list-style:none;padding:0;margin:0}
li{padding:10px 12px;border:1px solid #e8e8e8;border-radius:8px;margin-bottom:8px;font-size:14px}
li.muted{border-style:dashed;color:#999}
.btn{display:block;width:100%%;padding:14px;border:0;border-radius:8px;background:#0a7c6f;color:#fff;font-size:16px;margin-top:20px;cursor:not-allowed;opacity:.7}
.badge{display:inline-block;background:#e8f5f3;color:#0a7c6f;font-size:12px;padding:2px 8px;border-radius:4px}
</style>
</head>
<body>
<div class="wrap">
  <p class="muted">MojoPay · Hosted Checkout</p>
  <div class="card">
    <h1>%s</h1>
    <p class="muted">账号 %s · 状态 <span class="badge">%s</span></p>
    <div class="amount">%s</div>
    <p class="muted">订单标价；请选择支付方式（演示环境，按钮未接通上游）</p>
    <h2>法币 Fiat</h2>
    <ul>%s</ul>
    <h2>加密货币 Crypto</h2>
    <ul>%s</ul>
    <button class="btn" type="button">确认支付（Demo）</button>
  </div>
</div>
</body>
</html>`,
		template.HTMLEscapeString(v.MerchantName),
		template.HTMLEscapeString(v.AccountCode),
		template.HTMLEscapeString(v.Status),
		template.HTMLEscapeString(v.AmountDisplay),
		fiatRows,
		cryptoRows,
	)
}