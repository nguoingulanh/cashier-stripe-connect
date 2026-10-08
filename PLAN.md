# Plan: `nguoingulanh/cashier-connect`

> Package bổ sung **Stripe Connect** cho **Laravel Cashier (Stripe)**.
> Mục tiêu: `composer require` là dùng được, không phải sửa code Cashier hay cấu hình thêm ngoài những bước bắt buộc.

| Thông tin | Giá trị |
|---|---|
| Package | `nguoingulanh/cashier-connect` |
| Namespace | `Nguoingulanh\CashierConnect` |
| Repo | `github.com/nguoingulanh/cashier-stripe-connect` |
| PHP | 8.2+ |
| Laravel | 11 / 12 / 13 |
| Laravel Cashier | 15+ |
| License | MIT |

## Quyết định đã chốt

- Hỗ trợ **Express / Standard / Custom**, mặc định **Express**.
- Hỗ trợ cả 3 mô hình thanh toán: **destination charge**, **direct charge**, **separate charges & transfers** (kèm subscription có chia phí).
- Chủ sở hữu connected account là **polymorphic**: User, Shop, Vendor... đều được, và **không bắt buộc sửa bảng `users`**.
- Mặc định mỗi model có 1 account. Bật `multiple_accounts` để cho phép nhiều account.

---

## Tiến độ (cập nhật 2026-10-08)

| Phase | Trạng thái | Ghi chú |
|---|---|---|
| P0 – Nền móng | ✅ Xong | `composer.json`, Pest + Testbench, Pint, Larastan, GitHub Actions |
| P1 – Account & Onboarding | ✅ Xong | |
| P2 – Webhook | ✅ Xong | |
| P3 – Thanh toán | ✅ Xong | |
| P4 – Balance & Payout | ✅ Xong | |
| P5 – DX | ✅ Xong | `install`, `doctor`, `sync`, `replay`, `prune`, `connect.ready`, `CashierConnect::fake()` |
| P6 – Test & Hardening | 🟡 Một phần | Xong: 88 test Unit/Feature, 5 contract test với stripe-mock, install test trên app Laravel mới, chạy thử Laravel 11 (dependency thấp nhất) và 12. Chưa làm: E2E S1–S15 trên Stripe test mode (cần Stripe test key), load test S15, mutation test |
| P7 – Docs & Release | 🟡 Một phần | Xong: README, CHANGELOG, LICENSE, SECURITY, CONTRIBUTING, workflow release. Chưa làm: push lên GitHub, đăng ký Packagist, gắn tag |

### Điều chỉnh so với thiết kế ban đầu

- **Gộp service:** `TransferService`, `PayoutService`, `BalanceService` và `RefundService` được gộp thành một `FundsService`. Mỗi phần chỉ vài dòng nên gộp lại dễ đọc hơn. Public API không đổi.
- **Chưa tách `AccountDriver`:** `StripeGateway` đã là điểm thay thế duy nhất (bản thật và bản fake). Interface `AccountDriver` cho Accounts v2 sẽ thêm khi thật sự cần, và việc thêm không làm đổi public API.
- **Larastan level 8 thay vì max:** level 9–10 bắt khai báo kiểu cho từng phần tử của payload webhook dạng mảng (khoảng 90 lỗi thuộc loại này), tốn công mà gần như không thêm an toàn thực tế. Sẽ nâng level sau.
- **Thêm event:** `ConnectAccountDeleted`, `ConnectPayoutCanceled`.
- **Event của platform:** `transfer.*` và `application_fee.*` phát sinh trên tài khoản platform, nên Stripe gửi chúng về webhook của Cashier, không về endpoint Connect. Package lắng nghe `Laravel\Cashier\Events\WebhookReceived` để chuyển chúng thành Connect event.
- **Lọc live/test:** endpoint Connect ở chế độ live cũng nhận event test-mode từ connected account. Package bỏ qua các event khác chế độ với `STRIPE_SECRET`.
- **`link_ttl` mặc định 1440 phút** (thay vì 60). Người bán có thể mất hơn 1 giờ để hoàn tất onboarding, và URL quay về đã được ký sẵn trong link onboarding. Nếu chữ ký hết hạn, người dùng được đưa về `return_url` chứ không bị lỗi 403.

---

## Mục lục

0. [Lời hứa "chỉ cần composer require"](#0-lời-hứa-chỉ-cần-composer-require)
1. [System Design: kiến trúc](#1-system-design-kiến-trúc)
2. [Senior PHP/Laravel: triển khai](#2-senior-phplaravel-triển-khai)
3. [Automation Testing](#3-automation-testing)
4. [System Testing (E2E với Stripe test mode)](#4-system-testing-e2e-với-stripe-test-mode)
5. [Lộ trình triển khai](#5-lộ-trình-triển-khai)
6. [Trải nghiệm cuối cùng](#6-trải-nghiệm-cuối-cùng)
7. [Rủi ro và cách giảm thiểu](#7-rủi-ro-và-cách-giảm-thiểu)
8. [Phát hành lên Packagist](#8-phát-hành-lên-packagist)

---

## 0. Lời hứa "chỉ cần composer require"

| Việc | Package tự lo bằng cách |
|---|---|
| Đăng ký ServiceProvider, Facade | Laravel package auto-discovery (`extra.laravel` trong `composer.json`) |
| Stripe key/secret | Dùng lại `STRIPE_KEY` / `STRIPE_SECRET` và `Cashier::stripe()`, không khai báo lại |
| Config | `mergeConfigFrom`, chạy với giá trị mặc định mà không cần publish |
| Migration | `loadMigrationsFrom`, không cần publish |
| Route webhook và onboarding | Package tự đăng ký. Route webhook **không nằm trong group `web`** nên không bị CSRF chặn và không phải sửa `bootstrap/app.php` |
| Sửa model User | Không bắt buộc. Account lưu ở bảng riêng (polymorphic). Trait `ConnectBillable` chỉ là tùy chọn để gọi cho gọn |
| Scheduler dọn dữ liệu | Tự đăng ký qua `callAfterResolving(Schedule::class)` |

### Hai bước bắt buộc (không thể tự động hóa một cách an toàn)

1. `php artisan migrate`: theo quy ước, package không được tự migrate DB của ứng dụng.
2. `STRIPE_CONNECT_WEBHOOK_SECRET` trong `.env`: secret do Stripe sinh ra. Lệnh `php artisan cashier-connect:webhook` tạo endpoint trên Stripe và in secret ra để copy.

Thiếu secret thì package vẫn chạy (tạo account, onboarding, thanh toán), chỉ endpoint webhook trả về lỗi rõ ràng. Lệnh `php artisan cashier-connect:doctor` liệt kê những gì còn thiếu.

---

## 1. System Design: kiến trúc

### 1.1 Nguyên tắc

1. **Không đụng vào Cashier.** Chỉ dùng public API của Cashier, không override class nào, nên nâng cấp Cashier không làm vỡ package.
2. **Stripe là nguồn sự thật.** DB local chỉ là cache trạng thái. Khi webhook đến, package **gọi Stripe lấy object mới nhất** chứ không tin payload, để tránh lỗi khi event đến sai thứ tự.
3. **Webhook idempotent.** Mỗi `event.id` chỉ được xử lý một lần.
4. **Một Gateway duy nhất** cho mọi gọi API Stripe, nhờ đó dễ mock, dễ fake, dễ log.
5. **Tách theo use case:** Account, Onboarding, Payment, Transfer, Payout, Balance, Refund, Webhook.
6. **Mở rộng được, không cần fork:** có thể thay model, thêm hoặc ghi đè webhook handler, tắt route.

### 1.2 Sơ đồ luồng

```
App code ──► CashierConnect (Facade) / ConnectBillable (trait, tùy chọn)
                │
                ▼
        Services (Account, Onboarding, PaymentOptions, Transfer, Payout, Balance, Refund)
                │
                ▼
        StripeGateway ──► Cashier::stripe() (StripeClient) ──► Stripe API
                │
                ▼
        ConnectedAccountRepository ──► bảng stripe_connected_accounts


Stripe ──► POST /stripe/connect/webhook
            ├─ VerifyConnectSignature (middleware)
            ├─ Ghi vào stripe_connect_webhook_events (unique stripe_event_id)
            ├─ Dispatch HandleConnectWebhook (sync hoặc queue, tùy config)
            │     ├─ Map event.type → Handler
            │     ├─ Re-fetch object từ Stripe → sync DB
            │     └─ Fire Laravel Events (ConnectAccountUpdated, ConnectPayoutFailed, ...)
            └─ 200 OK  (handler lỗi → 500 để Stripe retry)
```

### 1.3 Data model

#### `stripe_connected_accounts`

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | bigint PK | |
| `connectable_type`, `connectable_id` | morph | index; chủ sở hữu (User/Shop/...) |
| `stripe_account_id` | string | unique |
| `type` | string | `express` / `standard` / `custom` |
| `country` | string(2) | |
| `default_currency` | string(3) | |
| `charges_enabled` | bool | |
| `payouts_enabled` | bool | |
| `details_submitted` | bool | |
| `requirements` | json | `currently_due`, `past_due`, `eventually_due`, `disabled_reason`, ... |
| `capabilities` | json | |
| `deauthorized_at` | timestamp null | |
| `metadata` | json null | |
| `created_at`, `updated_at`, `deleted_at` | timestamps | soft delete |

Khi `multiple_accounts = false` (mặc định) thì có ràng buộc 1 account cho mỗi `connectable`, kiểm tra ở tầng service.

#### `stripe_connect_webhook_events` (idempotency và audit)

| Cột | Kiểu | Ghi chú |
|---|---|---|
| `id` | bigint PK | |
| `stripe_event_id` | string | unique |
| `type` | string | index |
| `stripe_account_id` | string null | index |
| `payload` | json | |
| `status` | string | `pending` / `processed` / `failed` |
| `attempts` | int | |
| `last_error` | text null | |
| `processed_at` | timestamp null | |
| `created_at`, `updated_at` | timestamps | |

Lệnh `prune` xóa event cũ, mặc định giữ 30 ngày.

### 1.4 Public API (DX)

```php
use Nguoingulanh\CashierConnect\Facades\CashierConnect;

// Không cần trait
CashierConnect::for($shop)->create(['country' => 'US']);
CashierConnect::for($shop)->onboardingUrl();

// Có trait (tùy chọn)
use Nguoingulanh\CashierConnect\Concerns\ConnectBillable;

$shop->createConnectAccount(['type' => 'express']);
$shop->hasConnectAccount();
$shop->connectAccount();                  // Eloquent model local
$shop->asStripeConnectAccount();          // \Stripe\Account
$shop->updateConnectAccount([...]);
$shop->deleteConnectAccount();
$shop->syncConnectAccount();

// Onboarding
$shop->connectOnboardingUrl();            // AccountLink type=account_onboarding, tự kèm return/refresh
$shop->connectUpdateUrl();                // AccountLink type=account_update
$shop->connectDashboardUrl();             // Express login link
$shop->connectAccountSession(['payments', 'payouts']); // client_secret cho Embedded Components

// Trạng thái
$shop->isConnectReady();                  // charges_enabled && payouts_enabled
$shop->connectRequirements();             // currently_due, past_due, ...

// Thanh toán, kết hợp với Cashier
$customer->charge(1000, $pm,
    CashierConnect::destination($shop)->fee(100)->toArray());

$customer->checkout($items,
    CashierConnect::destination($shop)->feePercent(10)->forCheckout());

$customer->newSubscription('default', $price)
    ->create($pm, [], CashierConnect::destination($shop)->feePercent(10)->forSubscription());

// Direct charge (gọi API với header Stripe-Account)
CashierConnect::onBehalfOf($shop)->paymentIntents->create([...]);

// Separate charges & transfers
$shop->transferToConnect(5000, 'usd', ['transfer_group' => 'ORDER_1']);
$shop->reverseConnectTransfer($transferId, $amount);

// Balance & payout
$shop->connectBalance();
$shop->connectPayout(5000);
$shop->connectPayouts();

// Refund
CashierConnect::refund($paymentIntentId, reverseTransfer: true, refundApplicationFee: true);
```

### 1.5 Event phát ra cho ứng dụng

| Event | Khi nào |
|---|---|
| `ConnectAccountCreated` | Tạo account thành công |
| `ConnectAccountUpdated` | Mỗi lần sync có thay đổi |
| `ConnectAccountReady` | **Một lần duy nhất** khi chuyển từ chưa sẵn sàng sang sẵn sàng |
| `ConnectAccountRestricted` | Có `disabled_reason` hoặc `past_due` |
| `ConnectAccountDeauthorized` | `account.application.deauthorized` |
| `ConnectCapabilityUpdated` | `capability.updated` |
| `ConnectPayoutPaid` / `ConnectPayoutFailed` | `payout.paid` / `payout.failed` |
| `ConnectTransferCreated` / `ConnectTransferReversed` | `transfer.created` / `transfer.reversed` |
| `ConnectApplicationFeeRefunded` | `application_fee.refunded` |
| `ConnectWebhookReceived` / `ConnectWebhookHandled` | Mọi webhook (trước và sau khi xử lý) |

### 1.6 Bảo mật

- Webhook xác thực chữ ký bằng `Stripe\Webhook::constructEvent`, tolerance 300 giây.
- Route `return`/`refresh` của onboarding dùng **signed URL có hạn** (`URL::temporarySignedRoute`), nên không phụ thuộc session hay auth.
- Không log secret hay payload chứa PII, chỉ log `event.id`, `type` và `account`.
- Có Gate tùy chọn `cashier-connect.manage` để kiểm tra ai được tạo link cho account nào.
- Tạo account, transfer và payout đều kèm `idempotency_key`.

### 1.7 Chuẩn bị cho Stripe Accounts v2

Interface `AccountDriver` với `V1AccountDriver` làm mặc định (hỗ trợ `controller` properties). Có thể thêm `V2AccountDriver` sau này mà không đổi public API.

---

## 2. Senior PHP/Laravel: triển khai

### 2.1 Cấu trúc thư mục

```
cashier-connect/
├─ composer.json
├─ config/cashier-connect.php
├─ database/migrations/
│  ├─ 2026_01_01_000001_create_stripe_connected_accounts_table.php
│  └─ 2026_01_01_000002_create_stripe_connect_webhook_events_table.php
├─ routes/connect.php
├─ src/
│  ├─ CashierConnectServiceProvider.php
│  ├─ CashierConnect.php                       # manager, singleton
│  ├─ Facades/CashierConnect.php
│  ├─ Concerns/ConnectBillable.php
│  ├─ Contracts/
│  │  ├─ StripeGateway.php
│  │  ├─ AccountDriver.php
│  │  └─ ConnectableResolver.php
│  ├─ Gateway/
│  │  ├─ CashierStripeGateway.php
│  │  └─ V1AccountDriver.php
│  ├─ Enums/{AccountType, WebhookStatus}.php
│  ├─ Models/{ConnectedAccount, ConnectWebhookEvent}.php
│  ├─ Services/
│  │  ├─ AccountService.php
│  │  ├─ OnboardingService.php
│  │  ├─ PaymentOptions.php                    # builder destination/direct/subscription/checkout
│  │  ├─ TransferService.php
│  │  ├─ PayoutService.php
│  │  ├─ BalanceService.php
│  │  └─ RefundService.php
│  ├─ Http/
│  │  ├─ Controllers/{WebhookController, OnboardingController}.php
│  │  └─ Middleware/{VerifyConnectSignature, EnsureConnectReady}.php
│  ├─ Webhooks/
│  │  ├─ WebhookProcessor.php
│  │  ├─ Jobs/HandleConnectWebhook.php
│  │  └─ Handlers/
│  │     ├─ AccountUpdated.php
│  │     ├─ AccountDeauthorized.php
│  │     ├─ CapabilityUpdated.php
│  │     ├─ PersonUpdated.php
│  │     ├─ PayoutEvent.php
│  │     ├─ TransferEvent.php
│  │     └─ ApplicationFeeEvent.php
│  ├─ Events/*.php
│  ├─ Exceptions/
│  │  ├─ AccountNotFound.php
│  │  ├─ AccountAlreadyExists.php
│  │  ├─ AccountNotReady.php
│  │  ├─ InvalidConnectWebhook.php
│  │  └─ StripeConnectException.php
│  ├─ Console/
│  │  ├─ InstallCommand.php
│  │  ├─ WebhookCommand.php
│  │  ├─ SyncCommand.php
│  │  ├─ DoctorCommand.php
│  │  ├─ PruneEventsCommand.php
│  │  └─ ReplayEventCommand.php
│  └─ Testing/
│     ├─ FakeStripeGateway.php
│     └─ CashierConnectFake.php
├─ tests/{Unit, Feature, Contract, Install, E2E}/
├─ workbench/                                  # app Laravel mẫu cho E2E
├─ .github/workflows/{tests.yml, static.yml, e2e-nightly.yml}
├─ README.md
├─ CHANGELOG.md
└─ UPGRADE.md
```

### 2.2 `composer.json` (phần chính)

```json
{
  "name": "nguoingulanh/cashier-connect",
  "description": "Stripe Connect for Laravel Cashier",
  "type": "library",
  "license": "MIT",
  "require": {
    "php": "^8.2",
    "illuminate/support": "^11.0|^12.0|^13.0",
    "laravel/cashier": "^15.0|^16.0"
  },
  "require-dev": {
    "orchestra/testbench": "^9.0|^10.0|^11.0",
    "pestphp/pest": "^3.0",
    "larastan/larastan": "^3.0",
    "laravel/pint": "^1.0",
    "rector/rector": "^2.0",
    "infection/infection": "^0.29"
  },
  "autoload": { "psr-4": { "Nguoingulanh\\CashierConnect\\": "src/" } },
  "autoload-dev": { "psr-4": { "Nguoingulanh\\CashierConnect\\Tests\\": "tests/" } },
  "extra": {
    "laravel": {
      "providers": ["Nguoingulanh\\CashierConnect\\CashierConnectServiceProvider"],
      "aliases": { "CashierConnect": "Nguoingulanh\\CashierConnect\\Facades\\CashierConnect" }
    }
  }
}
```

> Ràng buộc phiên bản chính xác (Cashier 16, Testbench 11...) sẽ được kiểm tra lại với Packagist lúc dựng P0.

### 2.3 Config mặc định (chạy được không cần publish)

```php
return [
    'model'             => env('CASHIER_CONNECT_MODEL', App\Models\User::class),
    'default_type'      => env('CASHIER_CONNECT_TYPE', 'express'),
    'default_country'   => env('CASHIER_CONNECT_COUNTRY', 'US'),
    'capabilities'      => ['card_payments', 'transfers'],
    'multiple_accounts' => false,

    'webhook' => [
        'secret'           => env('STRIPE_CONNECT_WEBHOOK_SECRET'),
        'tolerance'        => 300,
        'path'             => 'stripe/connect/webhook',
        'queue'            => env('CASHIER_CONNECT_QUEUE'),   // null = xử lý sync
        'events'           => [
            'account.updated',
            'account.application.deauthorized',
            'capability.updated',
            'person.updated',
            'payout.paid',
            'payout.failed',
            'transfer.created',
            'transfer.reversed',
            'application_fee.refunded',
        ],
        'prune_after_days' => 30,
    ],

    'onboarding' => [
        'path_prefix' => 'stripe/connect',
        'return_url'  => env('CASHIER_CONNECT_RETURN_URL', '/'),  // redirect sau khi xong
        'refresh_url' => null,                                    // null = route của package
        'link_ttl'    => 60,                                      // phút, cho signed URL
    ],

    'routes'      => true,   // false nếu muốn tự đăng ký route
    'log_channel' => env('CASHIER_CONNECT_LOG_CHANNEL'),

    'tables' => [
        'accounts' => 'stripe_connected_accounts',
        'events'   => 'stripe_connect_webhook_events',
    ],
];
```

### 2.4 Route do package đăng ký

| Method | URI | Middleware | Mục đích |
|---|---|---|---|
| POST | `/stripe/connect/webhook` | `VerifyConnectSignature` (không dùng `web`) | Nhận Connect webhook |
| GET | `/stripe/connect/{account}/return` | `web`, `signed` | Sync trạng thái rồi redirect về `return_url` |
| GET | `/stripe/connect/{account}/refresh` | `web`, `signed` | Sinh AccountLink mới rồi redirect sang Stripe |

### 2.5 Các điểm kỹ thuật quan trọng

- **ServiceProvider:** `mergeConfigFrom`, `loadMigrationsFrom`, `loadRoutesFrom` (khi `routes = true`), `publishes` theo tag (`cashier-connect-config`, `cashier-connect-migrations`), đăng ký command, đăng ký schedule cho lệnh `prune`.
- **Gateway:** wrap `Cashier::stripe()`, gói option `['stripe_account' => $id]` và `idempotency_key`, chuyển `Stripe\Exception\*` thành `StripeConnectException` kèm ngữ cảnh.
- **Chống tạo trùng account:** `Cache::lock("cashier-connect:create:{type}:{id}")` khi người dùng bấm 2 lần.
- **Webhook processor:**
  - `insertOrIgnore` theo `stripe_event_id`. Nếu event đã `processed` thì trả 200 ngay.
  - Handler lỗi thì đánh dấu `failed`, ghi `last_error`, tăng `attempts` và trả 500 để Stripe retry.
  - Chế độ queue: trả 200 sau khi lưu, job tự `retry` với `backoff`.
- **Phát hiện chuyển trạng thái:** so sánh `isReady()` trước và sau khi sync để phát `ConnectAccountReady` / `ConnectAccountRestricted` đúng một lần.
- **Mở rộng:**
  - `CashierConnect::handleWebhookUsing('event.type', Handler::class)` để thêm hoặc ghi đè handler.
  - `CashierConnect::useAccountModel(MyAccount::class)` để thay model.
  - `CashierConnect::ignoreRoutes()` để tắt route mặc định.
- **Code style:** `declare(strict_types=1)`, readonly DTO, enum, final class ở những chỗ không cần kế thừa.
- **Chất lượng:** Pint (preset laravel), Larastan level max, Rector.
- **Versioning:** SemVer, có `CHANGELOG.md` và `UPGRADE.md`.

### 2.6 Artisan commands

| Lệnh | Tác dụng |
|---|---|
| `cashier-connect:install` | Tùy chọn: publish config, chạy migrate, tạo webhook. Mọi bước đều hỏi xác nhận |
| `cashier-connect:webhook` | Tạo Connect webhook endpoint trên Stripe (`connect: true`) và in secret |
| `cashier-connect:sync {account?} {--all}` | Đồng bộ trạng thái từ Stripe về DB |
| `cashier-connect:doctor` | Kiểm tra key, secret, migration, route, kết nối Stripe, endpoint đã đăng ký chưa |
| `cashier-connect:prune` | Xóa webhook event cũ hơn `prune_after_days` |
| `cashier-connect:replay {event_id}` | Xử lý lại một event bị `failed` |

### 2.7 Middleware cho ứng dụng

```php
Route::middleware(['auth', 'connect.ready'])->group(...);
// Chưa onboard xong: redirect sang onboarding link hoặc trả 403 (cấu hình được)
```

### 2.8 Testing helper cho người dùng package

```php
CashierConnect::fake();

// ... chạy code của ứng dụng

CashierConnect::assertAccountCreatedFor($shop);
CashierConnect::assertTransferred($shop, 5000);
CashierConnect::assertPayoutCreated($shop);

// Sinh payload webhook có chữ ký hợp lệ
$this->postJson(...CashierConnect::webhookRequest('account.updated', [...]));
```

---

## 3. Automation Testing

### 3.1 Công cụ

Pest, Orchestra Testbench, Mockery, `stripe/stripe-mock` (Docker), Infection (mutation testing), GitHub Actions.

### 3.2 Các tầng test

| Tầng | Phạm vi | Ví dụ test case |
|---|---|---|
| **Unit** | Service, `PaymentOptions`, handler, DTO, enum | `destination($shop)->feePercent(10)->forSubscription()` sinh đúng `application_fee_percent` + `transfer_data.destination`; `forCheckout()` sinh đúng `payment_intent_data`; handler `account.updated` phát `ConnectAccountReady` đúng một lần |
| **Feature** (Testbench) | Route, middleware, controller, DB, event, command | Chữ ký đúng/sai/hết hạn/thiếu secret; event trùng chỉ xử lý 1 lần; handler lỗi thì trả 500 và status = `failed`; chế độ queue dispatch job; signed URL hết hạn trả 403; `refresh` sinh link mới; `EnsureConnectReady` chặn đúng; lock chống tạo trùng account; các command `doctor`/`sync`/`prune`/`replay` |
| **Contract** | Gateway gọi tới `stripe-mock` | Mọi request khớp schema OpenAPI của Stripe, phát hiện sớm khi Stripe đổi API |
| **Install** | Đúng lời hứa zero-config | CI tạo app Laravel mới bằng `composer create-project`, `composer require` qua path repo, chạy `migrate`, gọi route webhook và onboarding, chạy `doctor` mà không cấu hình thêm gì |
| **Static** | Larastan max, Pint `--test`, Rector `--dry-run` | Chặn merge nếu lỗi |
| **Mutation** | Infection | MSI ≥ 80% cho `Services/` và `Webhooks/` |

### 3.3 Ma trận CI

- PHP **8.2 / 8.3 / 8.4** × Laravel **11 / 12 / 13** × Cashier **15 / 16** (loại các tổ hợp không tương thích).
- Chạy cả `--prefer-lowest` và `--prefer-stable`.
- DB: **SQLite** (nhanh), **MySQL 8**, **PostgreSQL 16** để kiểm tra kiểu json và unique index.

### 3.4 Definition of Done cho mỗi PR

- [ ] Coverage ≥ 90% line, ≥ 85% branch
- [ ] Không lỗi Larastan level max
- [ ] Pint và Rector sạch
- [ ] Install test xanh trên mọi tổ hợp trong ma trận
- [ ] Có test cho mọi nhánh lỗi mới thêm

---

## 4. System Testing (E2E với Stripe test mode)

### 4.1 Môi trường

- App Laravel mẫu trong `workbench/` cài package.
- Stripe test mode account riêng có bật Connect.
- Stripe CLI:
  ```bash
  stripe listen --forward-connect-to localhost:8000/stripe/connect/webhook
  ```
- **Playwright** để đi qua trang hosted onboarding (Express/Standard) bằng dữ liệu test của Stripe.
- **Custom account** được onboard hoàn toàn qua API bằng dữ liệu test, không cần browser, nên chạy được trong CI nightly.

### 4.2 Kịch bản

| # | Kịch bản | Kết quả mong đợi |
|---|---|---|
| S1 | Tạo Express account, mở onboarding link, điền dữ liệu test, quay về `return` | `details_submitted = true`, nhận `account.updated`, `ConnectAccountReady` được phát |
| S2 | Link onboarding hết hạn, vào `refresh` | Sinh link mới và redirect sang Stripe |
| S3 | Onboarding dở dang | `requirements.currently_due` có dữ liệu, `isConnectReady() = false`, middleware chặn |
| S4 | Destination charge 100$ với fee 10$ | Connected account nhận 90$ (trước phí Stripe), platform nhận application fee 10$ |
| S5 | Direct charge trên connected account | PaymentIntent nằm ở connected account, application fee về platform |
| S6 | Separate charge, transfer rồi reversal | Số dư 2 bên khớp sau khi reverse |
| S7 | Subscription với `application_fee_percent` | Mỗi invoice tạo application fee đúng tỉ lệ |
| S8 | Refund với `reverse_transfer` + `refund_application_fee` | Tiền rút về đúng từ connected account, fee được hoàn |
| S9 | Payout lỗi (dùng tài khoản ngân hàng test làm payout thất bại) | `ConnectPayoutFailed` được phát |
| S10 | Deauthorize (Standard) | `deauthorized_at` có giá trị, account không dùng được nữa |
| S11 | Gửi trùng event (`stripe events resend`) | Chỉ xử lý 1 lần |
| S12 | Event đến sai thứ tự | Nhờ re-fetch, trạng thái cuối vẫn đúng |
| S13 | Handler lỗi, Stripe retry | Lần retry xử lý thành công, `attempts` tăng |
| S14 | Webhook secret sai hoặc thiếu | Trả 400/500 kèm thông báo rõ, không có dữ liệu nào bị ghi |
| S15 | Load test 500 webhook/phút (k6) | Không deadlock, không mất event, p95 < 300ms khi bật queue |

### 4.3 Tiêu chí release

- [ ] S1–S14 pass trên Stripe test mode
- [ ] S15 đạt ngưỡng
- [ ] Install test pass trên app Laravel sạch
- [ ] README đã được đối chiếu với hành vi thực tế (mỗi ví dụ trong README có test tương ứng)

---

## 5. Lộ trình triển khai

| Phase | Nội dung | Deliverable | Ước lượng |
|---|---|---|---|
| **P0 – Nền móng** | Skeleton, `composer.json`, CI matrix, Pint/Larastan/Rector, Testbench, Gateway + fake | Repo chạy CI xanh | 1–2 ngày |
| **P1 – Account & Onboarding** | Migration, model, trait/facade, create/sync/delete, AccountLink, login link, AccountSession, route `return`/`refresh` dùng signed URL | Tạo account và onboarding chạy được | 3–4 ngày |
| **P2 – Webhook** | Middleware chữ ký, bảng event, processor idempotent, handler (account, capability, person, deauthorize), Events, lệnh `webhook`/`replay`/`prune` | Trạng thái tự đồng bộ (**MVP**) | 3 ngày |
| **P3 – Thanh toán** | `PaymentOptions` (destination/direct/subscription/checkout), transfer/reversal, refund | Tích hợp đủ 3 mô hình với Cashier | 3–4 ngày |
| **P4 – Balance & Payout** | Balance, payout, handler cho payout/transfer/application_fee | Đủ tính năng tài chính | 2 ngày |
| **P5 – DX** | `install`, `doctor`, `sync`, middleware `EnsureConnectReady`, `CashierConnect::fake()` + assertions | Trải nghiệm zero-config | 2 ngày |
| **P6 – Test & Hardening** | Contract test (stripe-mock), install test, mutation, E2E S1–S15, load test | Báo cáo test | 4–5 ngày |
| **P7 – Docs & Release** | README, docs từng mô hình thanh toán, UPGRADE, CHANGELOG, release workflow, beta trên Packagist, rồi `v1.0.0` (chi tiết ở [mục 8](#8-phát-hành-lên-packagist)) | Package có trên Packagist, `composer require` được | 2–3 ngày |

**Tổng: khoảng 4 tuần cho 1 người.** Hết P2 đã có MVP dùng được cho production nếu chỉ cần onboarding và đồng bộ trạng thái.

---

## 6. Trải nghiệm cuối cùng

```bash
composer require nguoingulanh/cashier-connect
php artisan migrate
php artisan cashier-connect:webhook   # copy secret vào .env
```

```php
use Nguoingulanh\CashierConnect\Facades\CashierConnect;

// Gửi người bán sang Stripe để onboarding
return redirect(CashierConnect::for(auth()->user())->onboardingUrl());

// Lắng nghe khi người bán sẵn sàng
Event::listen(ConnectAccountReady::class, fn ($e) => $e->account->connectable->notify(...));

// Thu tiền và chia cho người bán
$customer->charge(10000, $pm, CashierConnect::destination($shop)->fee(1000)->toArray());
```

---

## 7. Rủi ro và cách giảm thiểu

| Rủi ro | Ảnh hưởng | Giảm thiểu |
|---|---|---|
| Cashier đổi API ở major mới | Package vỡ | Chỉ dùng public API của Cashier; CI matrix có nhiều version Cashier; chạy CI hằng tuần với `dev-master` của Cashier |
| Stripe đổi API / chuyển sang Accounts v2 | Hành vi sai | Contract test với stripe-mock; `AccountDriver` trừu tượng; pin `api_version` theo Cashier |
| Event đến sai thứ tự hoặc trùng | Trạng thái sai | Re-fetch từ Stripe và idempotency theo `event.id` |
| Webhook bị giả mạo | Ghi dữ liệu sai | Xác thực chữ ký và tolerance; không có secret thì từ chối toàn bộ |
| Tạo trùng account hoặc transfer | Mất tiền / dữ liệu rác | `Cache::lock` và `idempotency_key` |
| Hosted onboarding thay đổi UI | E2E Playwright fail | Tách E2E browser ra nightly, không chặn PR; Custom account test qua API làm lớp bảo vệ chính |
| Người dùng quên cấu hình webhook secret | Trạng thái không đồng bộ | Lệnh `doctor`, log warning khi boot ở môi trường production, README nêu rõ |

---

## 8. Phát hành lên Packagist

Mục tiêu: bất kỳ ai cũng chạy được `composer require nguoingulanh/cashier-connect` mà không phải khai báo `repositories`.

### 8.1 Chuẩn bị repo GitHub

- [ ] Tạo repo **public** `github.com/nguoingulanh/cashier-stripe-connect`, nhánh mặc định là `main`.
- [ ] File bắt buộc hoặc nên có ở root:

| File | Mục đích |
|---|---|
| `LICENSE` | MIT (Packagist hiển thị license từ `composer.json`, file này để hợp lệ về pháp lý) |
| `README.md` | Hiển thị trên trang Packagist và GitHub: cài đặt, cấu hình, ví dụ, badges |
| `CHANGELOG.md` | Theo chuẩn [Keep a Changelog](https://keepachangelog.com) |
| `UPGRADE.md` | Hướng dẫn nâng cấp giữa các major |
| `SECURITY.md` | Kênh báo lỗi bảo mật riêng (GitHub Private Vulnerability Reporting) |
| `CONTRIBUTING.md` | Quy trình đóng góp, chạy test local |
| `.gitattributes` | Loại file không cần thiết khỏi bản cài đặt |
| `.editorconfig`, `pint.json`, `phpstan.neon.dist`, `rector.php`, `phpunit.xml.dist` | Công cụ chất lượng |

- [ ] `.gitattributes` để bản cài qua Composer (dist zip) nhẹ, chỉ chứa `src/`, `config/`, `database/`, `routes/`:

```gitattributes
* text=auto eol=lf

/.github            export-ignore
/tests              export-ignore
/workbench          export-ignore
/.editorconfig      export-ignore
/.gitattributes     export-ignore
/.gitignore         export-ignore
/phpunit.xml.dist   export-ignore
/phpstan.neon.dist  export-ignore
/pint.json          export-ignore
/rector.php         export-ignore
/infection.json5    export-ignore
/CONTRIBUTING.md    export-ignore
/PLAN.md            export-ignore
```

### 8.2 Hoàn thiện `composer.json` cho Packagist

Bổ sung vào phần ở [mục 2.2](#22-composerjson-phần-chính):

```json
{
  "name": "nguoingulanh/cashier-connect",
  "description": "Stripe Connect (onboarding, payouts, transfers, webhooks) for Laravel Cashier.",
  "keywords": ["laravel", "cashier", "stripe", "stripe-connect", "marketplace", "payouts", "billing"],
  "homepage": "https://github.com/nguoingulanh/cashier-stripe-connect",
  "license": "MIT",
  "authors": [
    { "name": "nguoingulanh", "homepage": "https://github.com/nguoingulanh", "role": "Developer" }
  ],
  "support": {
    "issues": "https://github.com/nguoingulanh/cashier-stripe-connect/issues",
    "source": "https://github.com/nguoingulanh/cashier-stripe-connect",
    "security": "https://github.com/nguoingulanh/cashier-stripe-connect/security/policy"
  },
  "config": { "sort-packages": true, "allow-plugins": { "pestphp/pest-plugin": true, "infection/extension-installer": true } },
  "extra": {
    "branch-alias": { "dev-main": "1.x-dev" }
  },
  "minimum-stability": "stable",
  "prefer-stable": true
}
```

Ghi chú:
- **Không khai báo `version`** trong `composer.json`. Packagist lấy version từ git tag.
- `authors[].email` là tùy chọn và sẽ hiển thị công khai trên Packagist, nên chỉ thêm khi bạn muốn.
- `branch-alias` giúp người dùng thử bản chưa phát hành bằng `composer require nguoingulanh/cashier-connect:1.x-dev`.

Kiểm tra trước khi phát hành:

```bash
composer validate --strict
composer normalize --dry-run          # (ergebnis/composer-normalize) chuẩn hóa thứ tự key
git archive HEAD | tar -t             # xác nhận tests/, workbench/ không lọt vào bản cài
```

### 8.3 Quy ước version và tag

- Theo **SemVer**: `MAJOR.MINOR.PATCH`, tag có tiền tố `v` (ví dụ `v1.0.0`).
- Lộ trình tag:

| Tag | Khi nào | Người dùng cài bằng |
|---|---|---|
| `v0.1.0` … `v0.x` | Sau P2 (MVP), API còn có thể đổi | `composer require nguoingulanh/cashier-connect:^0.1` |
| `v1.0.0-beta.1` | Sau P5, đủ tính năng, đang chạy E2E | `composer require nguoingulanh/cashier-connect:^1.0@beta` |
| `v1.0.0-rc.1` | Sau P6, đã pass S1–S15 | `...:^1.0@RC` |
| `v1.0.0` | Sau P7 | `composer require nguoingulanh/cashier-connect` |

- Thay đổi phá vỡ API thì tăng MAJOR và ghi vào `UPGRADE.md`.
- Thêm hỗ trợ Laravel/Cashier mới thì tăng MINOR. Bỏ hỗ trợ version cũ cũng chỉ tăng MINOR (Composer tự chọn đúng bản cho môi trường cũ), nhưng phải ghi rõ trong CHANGELOG.

### 8.4 Đăng ký trên Packagist (làm một lần)

1. Đăng nhập [packagist.org](https://packagist.org) bằng tài khoản GitHub `nguoingulanh`.
2. Vào **Submit**, dán URL `https://github.com/nguoingulanh/cashier-stripe-connect` rồi bấm **Check** và **Submit**.
3. **Bật auto-update** để mỗi lần push tag là Packagist cập nhật ngay:
   - Đăng nhập Packagist bằng GitHub thì Packagist tự cài GitHub hook cho repo. Kiểm tra trên trang package: nếu **không** còn cảnh báo *"This package is not auto-updated"* là xong.
   - Nếu vẫn còn cảnh báo, thêm webhook thủ công trong GitHub repo → **Settings → Webhooks**:
     - Payload URL: `https://packagist.org/api/github?username=nguoingulanh`
     - Content type: `application/json`
     - Secret: API Token lấy ở trang profile Packagist
     - Event: *Just the push event*
4. Kiểm tra trang `https://packagist.org/packages/nguoingulanh/cashier-connect` hiển thị đúng description, license, version.

### 8.5 Release workflow tự động (GitHub Actions)

`.github/workflows/release.yml`, chạy khi push tag `v*`:

```yaml
name: release
on:
  push:
    tags: ['v*']

permissions:
  contents: write

jobs:
  tests:
    uses: ./.github/workflows/tests.yml          # tái sử dụng toàn bộ ma trận CI

  release:
    needs: tests
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Validate composer.json
        run: composer validate --strict
      - name: Extract changelog for this version
        id: changelog
        run: |
          VERSION="${GITHUB_REF_NAME#v}"
          awk "/^## \\[$VERSION\\]/{flag=1;next}/^## \\[/{flag=0}flag" CHANGELOG.md > notes.md
      - name: Create GitHub Release
        uses: softprops/action-gh-release@v2
        with:
          body_path: notes.md
          prerelease: ${{ contains(github.ref_name, '-') }}   # beta/rc đánh dấu pre-release
```

Packagist nhận tag qua hook ở bước 8.4, không cần bước publish riêng.

> `tests.yml` cần khai báo `on: workflow_call` để được tái sử dụng.

### 8.6 Quy trình phát hành mỗi version (checklist)

- [ ] CI xanh trên `main` (ma trận đầy đủ, static analysis, install test)
- [ ] E2E nightly gần nhất xanh (bắt buộc với MINOR/MAJOR)
- [ ] Cập nhật `CHANGELOG.md`: chuyển `[Unreleased]` thành `[x.y.z] - YYYY-MM-DD`
- [ ] Cập nhật `UPGRADE.md` nếu là MAJOR
- [ ] Cập nhật bảng tương thích PHP/Laravel/Cashier trong README
- [ ] `composer validate --strict`
- [ ] Tạo tag và push:
  ```bash
  git tag -a v1.0.0 -m "v1.0.0"
  git push origin v1.0.0
  ```
- [ ] Workflow `release` xanh, GitHub Release đã được tạo
- [ ] Trang Packagist hiển thị version mới (thường trong vòng 1 phút)
- [ ] **Smoke test sau phát hành** trên app Laravel sạch, cài từ Packagist thật (không dùng path repo):
  ```bash
  composer create-project laravel/laravel smoke && cd smoke
  composer require laravel/cashier nguoingulanh/cashier-connect
  php artisan migrate
  php artisan cashier-connect:doctor
  php artisan route:list --path=stripe/connect
  ```

> Smoke test này có thể tự động hóa thành job `post-release` chạy sau `release` khoảng 5 phút, có retry vì Packagist cần thời gian cập nhật.

### 8.7 Sau khi phát hành

- **Badges** trên README: version, downloads (Packagist), tests, PHPStan level, license.
- **Chính sách hỗ trợ** ghi trong README:

| Version | PHP | Laravel | Cashier | Bug fix đến | Security fix đến |
|---|---|---|---|---|---|
| 1.x | 8.2+ | 11–13 | 15–16 | Khi ra 2.0 + 6 tháng | Khi ra 2.0 + 12 tháng |

- **Dependabot / Renovate** để cập nhật GitHub Actions và dev dependencies.
- **Workflow tuần** chạy test với `laravel/cashier:dev-master` và Laravel bản mới nhất để phát hiện sớm khi upstream đổi API.
- Báo lỗi bảo mật qua **GitHub Security Advisories**. Advisory được công bố sẽ được Packagist đồng bộ để `composer audit` của người dùng cảnh báo.
- Nếu một ngày ngừng bảo trì: đánh dấu **Abandoned** trên Packagist và chỉ định package thay thế (nếu có).
