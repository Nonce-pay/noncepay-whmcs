# NoncePay for WHMCS

Accept crypto payments on your WHMCS invoices: USDT, USDC, Bitcoin, Ethereum, Solana and more, forwarded straight to
your own wallets. NoncePay is non-custodial: there is no account, no API key, no monthly fee and no withdrawal step.

The module adds a **Pay with crypto** button to your invoices. The customer is sent to the NoncePay hosted payment
page, picks a coin, pays, and comes back to the invoice. The invoice is marked paid as soon as NoncePay confirms the
payment with a signed callback.

## Requirements

- WHMCS 8.x
- PHP 7.4 to 8.3 with the `curl`, `json` and `openssl` extensions (`bcmath` is used when present, not required)
- Your WHMCS site reachable over the internet, so NoncePay can call
  `https://your-whmcs/modules/gateways/callback/noncepay.php`

## Install

1. Download `noncepay-whmcs.zip` from the [latest release](https://github.com/Nonce-pay/noncepay-whmcs/releases/latest)
   and unzip it into the root of your WHMCS installation. It adds:
   ```
   modules/gateways/noncepay.php
   modules/gateways/noncepay/               (library, language files)
   modules/gateways/callback/noncepay.php
   ```
2. In the WHMCS admin, go to **Configuration > System Settings > Payment Gateways** (Setup > Payments > Payment
   Gateways on older themes), open **All Payment Gateways** and click **NoncePay (crypto)**.
3. Enter a receiving wallet for each family of coins you want to accept, then **Save Changes**.

That is all. The module creates its two tables (`mod_noncepay_links`, `mod_noncepay_values`) the first time it runs.

## Settings

| Setting                                                         | Default                    | What it does                                                                                           |
| --------------------------------------------------------------- | -------------------------- | ------------------------------------------------------------------------------------------------------ |
| EVM wallet                                                      | empty                      | One `0x…` address for Ethereum, BNB Smart Chain, Polygon, Base, Arbitrum, Optimism and Avalanche coins |
| Tron, Bitcoin, Litecoin, Bitcoin Cash, Dogecoin, Solana wallets | empty                      | One address per chain family; a family without a wallet is not offered                                 |
| Coins offered                                                   | every coin of your wallets | Optional comma-separated tickers such as `trc20/usdt,bep20/usdt,btc`                                   |
| Merchant name                                                   | your company name          | Shown on the payment page, 64 characters max                                                           |
| Logo URL                                                        | none                       | An `https` image shown beside your name, on a dark background, at most 40 px tall                      |
| Underpayment tolerance (%)                                      | 0                          | 0 to 1. With 0.5, an invoice of 100 counts as paid from 99.50, which covers exchange withdrawal fees   |
| API base URL                                                    | `https://api.noncepay.app` | Change only to test against another NoncePay instance                                                  |
| Pinned public key                                               | empty                      | Optional PEM key that signs callbacks; empty fetches it from `/pubkey/` and caches it                  |
| Test mode                                                       | off                        | Allows an `http` API base URL (a local or testnet instance)                                            |

Every wallet accepts the split syntax `0.7@addressA|0.3@addressB`. Wallets are checked when you save and again by
NoncePay when the first link is created.

## How it works

1. The invoice shows **Pay with crypto**. Nothing is sent to NoncePay until the customer clicks it.
2. On click, the module creates a NoncePay [payment link](https://noncepay.app/docs/guides/payment-links/) for the
   invoice balance in the invoice currency, valid 7 days, and redirects the customer to its payment page. A reload or
   a second click reuses the same link; a new one is created only when the balance changes or the link is about to
   expire.
3. The customer picks a coin and pays. Each payment is forwarded to your wallet as soon as it confirms, minus the
   NoncePay fee (1.25%, lower with volume) and the network cost.
4. Once the confirmed payments cover the invoice, NoncePay sends a signed callback. The module verifies its
   `x-ca-signature` (RSA-SHA256) against the NoncePay public key, checks the link and the amount against what it
   stored, and records the payment with `addInvoicePayment`. The transaction id is the link id, so a repeated callback
   is recorded once.
5. The customer is sent back to the invoice, which says the payment is being confirmed. The invoice is never marked
   paid from this redirect, only from the signed callback.

Every callback, accepted or not, is in **Billing > Gateway Log**. The API calls made when a link is created are in
**System Logs > Module Log** when module logging is on.

## Amounts

- **Underpayment within the tolerance**: the invoice amount is recorded and the invoice is paid; the gateway log shows
  what was actually received.
- **Underpayment beyond the tolerance**: the payment page asks the customer for the rest; nothing is recorded until
  the link is paid.
- **Overpayment**: the full amount received is recorded, and WHMCS adds the surplus to the client's credit balance.
- **Fees**: recorded as 0. The NoncePay fee is deducted from the crypto forwarded to your wallet, not charged
  separately.

## FAQ

**Which currencies can an invoice be in?** Those NoncePay prices: USD, EUR, GBP, CAD, AUD, CHF, JPY, BRL, INR and
about thirty others. For another currency, set **Convert To For Processing** on the gateway to one of them: the link
is priced in that currency and the invoice balance is recorded when it is paid. Otherwise the invoice says that it
cannot be paid in crypto.

**A customer paid after the link expired, or paid a link I replaced.** A payment to an expired link is still forwarded
to your wallet and reported by a per-deposit callback, which appears in the gateway log as "Deposit notification", but
it does not mark the invoice paid: add the payment by hand. A link replaced because the balance changed stays valid
until it expires, and a payment that completes it is recorded on the invoice.

**The callback is rejected with "Invalid signature".** Check that nothing in front of WHMCS rewrites the request body.
If your server cannot reach `api.noncepay.app` to fetch the public key, paste the key from
[`/pubkey/`](https://api.noncepay.app/pubkey/) into **Pinned public key**.

**The invoice was cancelled or paid another way before the crypto payment arrived.** The payment is not recorded; the
gateway log shows "review manually" with the transaction ids, and the funds are in your wallet.

**Refunds?** NoncePay never holds funds, so there is nothing to refund from: send a refund from your wallet.

## Development

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit
./bin/build.sh   # dist/noncepay-whmcs.zip
```

The tests run the link and callback flows against in-memory fakes of WHMCS and the NoncePay API, with a test RSA key
in `tests/fixtures/`.

## Links

- [NoncePay](https://noncepay.app/) and its [documentation](https://noncepay.app/docs/)
- [Payment links](https://noncepay.app/docs/guides/payment-links/) and [callbacks](https://noncepay.app/docs/callbacks/)

## License

MIT, see [LICENSE](LICENSE).
