# BroCode_CustomerMigrationMail

Stop Magento emailing every customer the moment an ERP or migration script creates them, then send each one the mail they actually need — once the shop is ready for them to receive it.

## The problem

Creating a customer through the REST API always sends mail. There is no quiet path:

- `POST /V1/customers` maps to `AccountManagementInterface::createAccount`, which notifies unconditionally.
- The only method that saves a customer *without* notifying is `CustomerRepositoryInterface::save`, and its sole REST route is the `PUT` for a customer that already exists.
- Omitting the password does not suppress the mail. It selects a different one — `customer/create_account/email_no_password_template`, the "set your password" variant — because `AccountManagement` branches on the password hash being empty.

So a migration that loads 40,000 customers sends 40,000 emails, from a domain with no sending reputation, pointing at a shop that is not live yet.

Magento's own switch does not solve it either. `system/smtp/disable` is an `aroundSendMessage` plugin that simply does not call `$proceed()` — **the message is discarded, not queued**. There is no spool to flush afterwards and no command to send them later; `async_sending` exists only in `Magento_Sales`. It also silences *all* outbound mail, order confirmations included.

## What this module does

Two things, and deliberately nothing else.

**1. Suppresses the new-account mail** while you are importing, via a plugin on `EmailNotificationInterface::newAccount` — the single funnel all account templates pass through. Only the welcome and set-your-password mails are affected. Order and admin mail keep working, which is the difference that matters when customers are being loaded into a shop that is already live.

**2. Sends the right follow-up later**, from the CLI, throttled, scoped.

It stores nothing. See *Why there is no table*.

## Usage

### During the import

```
Stores > Configuration > Customers > Customer Mail Deferral > Migration
    Suppress New Account Emails: Yes
```

Store-scoped, so a store view being migrated can be silent while a live one keeps sending. Run your import. No welcome mail is sent.

**Turn it off again when the import finishes.** The module does not do this for you on purpose — an automatic re-enable would fire in the middle of a re-run.

### At go-live

```bash
# see who is waiting, send nothing
bin/magento brocode:customer-mail:release --dry-run

# prove it on one customer first
bin/magento brocode:customer-mail:release --customer=1234

# then the run, confined and paced
bin/magento brocode:customer-mail:release --website=1 --created-after=2026-08-01 --sleep=200
```

| Option | Purpose |
|---|---|
| `--dry-run` | Counts, plus the first ten recipients. Sends nothing |
| `--customer=<id>` | A single customer, to check the template before a bulk run |
| `--website=<id>` | One website at a time |
| `--created-after=<date>` | Confine the run to the migration window |
| `--limit=<n>` | Cap a single run |
| `--sleep=<ms>` | Pause between sends |
| `--confirmation` | Target unconfirmed customers instead — see below |

`--dry-run`, `--limit` and `--sleep` are not conveniences. Forty thousand mails through one relay is how a domain gets blocked on go-live morning.

### Two populations, two mails

The command works on **derived state**, so which mail a customer gets is decided by their record rather than by anything the module remembered:

| State | Detected by | Mail sent |
|---|---|---|
| No password | `password_hash` empty | Password setup (fresh reset token) |
| Not confirmed | `confirmation` not null | `resendConfirmation` — run with `--confirmation` |

**Do not send password resets to unconfirmed customers.** With confirmation required, an unconfirmed customer is refused at login with *"This account isn't confirmed"*, and `initiatePasswordReset` does not clear the confirmation flag. They would receive a mail about resetting a password they never had, while the actual blocker goes unmentioned. (They are not permanently stuck — opening the reset link does clear confirmation, because `CreatePassword` calls `resetCustomerConfirmation` — but the message is the wrong one and the account looks broken until they act on it.) `resendConfirmation` is the mail that matches the problem.

## A migration-specific template

Requested often enough to be built in: *"the new shop is live and your account came with you"* reads very differently from *"welcome to the shop"*, and by default a released customer gets Magento's ordinary forgot-password mail.

```
Stores > Configuration > Customers > Customer Mail Deferral > Release
    Password Setup Email Template: <your template>
```

**Leave it empty and the module delegates to core** — `AccountManagementInterface::initiatePasswordReset`, exactly the mail Magento would have sent. The override is the only thing that changes behaviour, so an install that configures nothing behaves like an install without the module.

Set it, and the module mints the reset token the same way core does and sends your template instead. Base it on `customer_password_forgot_email_template` so the create-password link is already correct:

```
{{trans 'Set your password: <a href="%url">%url</a>'
    url="$this.getUrl($store,'customer/account/createPassword/',
         [_query:[id:$customer.id,token:$customer.rp_token],_nosid:1])" |raw}}
```

Both `id` and `token` are required. `CreatePassword` calls `validateResetPasswordLinkToken($customerId, $token)`, so a link missing `id` fails validation — and it fails *silently from the customer's point of view*, because the mail itself looks perfectly fine.

The sender identity and the template both resolve from the customer's own store, under store emulation. That is the point of the emulation: a CLI run has no store context, and without it every migrated customer would be mailed in the default store's language regardless of where they were created.

## Core throttles password resets, and a batch runs into it

The single most likely thing to go wrong on go-live morning, so the command checks for it before sending anything.

Magento throttles password resets in `Magento_Security`, and the shipped defaults are:

| Setting | Default |
|---|---|
| `customer/password/password_reset_protection_type` | `1` — By IP and Email |
| `customer/password/max_number_password_reset_requests` | `5` |
| `customer/password/min_time_between_password_reset_requests` | `10` (minutes) |

The checker matches on **IP *or* account reference**. Every send from the CLI is recorded with an *empty* IP — `RemoteAddress::getRemoteAddress()` returns `false` outside a web request — so the whole batch shares one grouping key and the quantity cap applies across the entire run rather than per customer. Measured on 2.4.8-p5: a batch of six sent one and failed five.

`--sleep` does not help. The limit is a count inside a time window, not a rate.

**The command refuses the batch rather than discovering this one customer at a time**, and prints the fix:

```bash
bin/magento config:set customer/password/password_reset_protection_type 3   # By Email
bin/magento cache:flush
bin/magento brocode:customer-mail:release --website=1 --created-after=2026-08-01
bin/magento config:set customer/password/password_reset_protection_type 1   # put it back
bin/magento cache:flush
```

With **By Email**, each customer is its own grouping key and a batch of distinct addresses goes through — verified, five of six sent. The sixth failed correctly: it had been mailed minutes earlier, and the 10-minute per-account frequency check still applies. That is the same rule that makes a re-run within ten minutes fail for anyone already mailed, which is worth knowing alongside the idempotency note above.

`--ignore-reset-throttle` proceeds anyway if you want the failures.

**The migration-template path does not go through this.** When a template override is configured the module mints the token itself and sends directly, so no reset event is recorded and core's throttling never applies. That is convenient for a large release and it is a genuine divergence from core's behaviour — it is called out here rather than left to be discovered, and it is a reason to keep the override unset unless the migration wording is actually wanted.


## Why there is no table

The first design recorded who had been suppressed. Executing the states showed it was unnecessary — both populations are already expressed on `customer_entity`:

- needs a password → `password_hash IS NULL OR password_hash = ''`
- needs confirming → `confirmation IS NOT NULL`

(`rp_token` is *not* a discriminator. It is minted for every account at creation, whether or not a password was supplied.)

Deriving means the command works on customers created before the module was installed, survives the module being removed and reinstalled, and cannot drift from reality the way a shadow table can.

**The cost, stated plainly:** without a record the command cannot tell "we suppressed this one" from "this customer legitimately never set a password". That is why `--created-after`, `--website` and `--dry-run` matter — they supply the intent the database cannot. Check the dry run before every real run.

**And the run is not idempotent.** A customer stays in the "needs a password" set until they actually set one, so a second run mails everybody who has not clicked yet. That is sometimes what you want — a reminder pass a week later — and sometimes not. It is never automatic, which is deliberate: there is no cron, and every send is a command someone chose to type. If you want a single pass only, keep the customer ids from the first run, or narrow `--created-after` on the second.

A record table would have solved this and was still not worth it — it would have to be reconciled against customers who set a password through the storefront in the meantime, and a stale "already sent" flag is a worse failure than a duplicate reminder.

## Why the confirmation mail is never suppressed

Suppressing it would leave the customer unable to log in rather than merely uninformed, and that failure surfaces days later as a support ticket nobody connects to a migration setting. The plugin passes it straight through even while suppression is on.

## Requirements

Magento Open Source 2.4.8-p5 or later, PHP 8.3+. Verified against 2.4.8-p5.

## Install

```bash
composer require brocode/module-customer-migration-mail
bin/magento module:enable BroCode_CustomerMigrationMail
bin/magento setup:upgrade
```

## Licence

MIT — see [LICENSE](LICENSE).

---

Docs, background and related modules: [brocode.at](https://brocode.at/modules/module-customer-migration-mail/)
