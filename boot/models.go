package boot

import (
	"github.com/qingpeng2016/ai-agent-paper/infrastructure/mysql"
	"go.uber.org/dig"
)

func provideModels(c *dig.Container) {
	for _, constructor := range []any{
		mysql.NewAssetImpl,
		mysql.NewAssetNetworkImpl,
		mysql.NewProviderImpl,
		mysql.NewProviderAccountImpl,
		mysql.NewChannelCapabilityImpl,
		mysql.NewMerchantImpl,
		mysql.NewMerchantAccountImpl,
		mysql.NewMerchantUserImpl,
		mysql.NewMerchantAPIKeyImpl,
		mysql.NewMerchantWebhookEndpointImpl,
		mysql.NewChannelBindingImpl,
		mysql.NewComplianceCaseImpl,
		mysql.NewBeneficiaryImpl,
		mysql.NewQuoteImpl,
		mysql.NewPaymentOrderImpl,
		mysql.NewPaymentAttemptImpl,
		mysql.NewPaymentReceiptImpl,
		mysql.NewConversionImpl,
		mysql.NewRefundOrderImpl,
		mysql.NewLedgerAccountImpl,
		mysql.NewJournalImpl,
		mysql.NewJournalEntryImpl,
		mysql.NewSettlementBatchImpl,
		mysql.NewPayoutOrderImpl,
		mysql.NewPayoutAttemptImpl,
		mysql.NewAPIIdempotencyImpl,
		mysql.NewProviderEventInboxImpl,
		mysql.NewMerchantEventOutboxImpl,
		mysql.NewAsyncJobImpl,
		mysql.NewOpenAPIRequestNonceImpl,
		mysql.NewOpenAPIRequestLogImpl,
		mysql.NewMerchantWebhookDeliveryAttemptImpl,
		mysql.NewTransactorImpl,
	} {
		if err := c.Provide(constructor); err != nil {
			panic(err)
		}
	}
}
