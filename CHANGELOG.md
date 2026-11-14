# Changelog

## 0.1.0

First release.

- "Pay with crypto" on WHMCS invoices through a NoncePay payment link and its hosted payment page.
- One link per invoice and balance, reused on reload; a new one when the balance changes or the link expires.
- Signed callback (`x-ca-signature`, RSA-SHA256) marks the invoice paid once, with the link id as transaction id.
- Underpayment tolerance (0 to 1%), overpayments recorded as client credit, "Convert To For Processing" for
  currencies NoncePay does not price.
- English and French.
