# 線上預約系統 API（Reservation System API）

Laravel API-only 後端專案，展示 API 版本控制、Rate Limiting、併發搶位控制、金流 Webhook 冪等性處理等後端工程實踐。詳見專案企劃書（任務描述）。

## 開發環境（Docker Compose）

服務：`app`（PHP-FPM）、`nginx`、`mysql`、`redis`、`queue`（queue worker）。

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

API 預設對外服務於 `http://localhost:8000`。

## 測試

```bash
docker compose exec app php artisan test
```

## API 版本控制

路由統一走 `/api/v1/...` 前綴（見 `routes/api.php` 與 `routes/api_v1.php`）。未來新增 `/api/v2` 時，只需新增 `routes/api_v2.php` 並在 `routes/api.php` 註冊對應 prefix group，不影響 v1 既有客戶端。

## 認證與權限

- `POST /api/v1/register`、`POST /api/v1/login`：套用 `throttle:auth`（每 IP 每分鐘 10 次）
- `POST /api/v1/logout`、`GET /api/v1/me`：需 Sanctum token
- 資源／時段的讀取（`GET`）：登入即可
- 資源／時段的建立／修改／刪除：需 `admin` 角色（見 `App\Http\Middleware\EnsureUserIsAdmin`）

## 預約核心邏輯（併發控制）

`App\Services\BookingService::book()` 在 `DB::transaction` 內對該時段下 `lockForUpdate()`，同一時段的所有併發預約請求會被資料庫序列化處理，確保 `booked_count` 永遠不會超過 `capacity`（見 `tests/Feature/BookingTest.php` 的搶位測試）。

- `POST /api/v1/bookings`：套用 `throttle:booking`（每使用者每分鐘 5 次），可帶 `idempotency_key` 防止重複送出造成的重複預約
- `DELETE /api/v1/bookings/{booking}`：取消預約並釋放名額（本人或 admin 可操作，重複取消不會重複釋放名額）
- 預約成功／取消後，透過 Queue Job（`SendBookingNotification`）非同步發送 LINE Notify 通知，並寫入 `notification_logs`；未設定 `LINE_NOTIFY_TOKEN` 時退回寫 log，不影響流程

## 金流 Webhook（Stripe 測試環境）

- 預約付費項目時（`resource.price > 0`），`BookingService` 會呼叫 `App\Contracts\PaymentGateway` 產生 Stripe Checkout Session，並把付款連結存回 `bookings.payment_url`。未設定 `STRIPE_SECRET` 時自動退回 `NullPaymentGateway`（回傳假連結），本地開發／測試不需要真的申請 Stripe 金鑰
- `POST /api/v1/webhooks/stripe`：公開路由（不經 Sanctum），改用 `Stripe\Webhook::constructEvent()` 驗證 `Stripe-Signature` 標頭簽章，偽造簽章一律回 400（見 `tests/Feature/StripeWebhookTest.php`）
- 冪等性採兩層防護：
  1. `payment_webhook_logs` 對 `(provider, event_id)` 建唯一索引，同一事件重複送達會在 DB 層擋下第二次寫入
  2. `BookingService::confirmPayment()` / `failPayment()` 本身也是冪等操作（已是 `paid` 就不再變動、也不會重複派發通知）
  3. 業務邏輯與寫入 log 包在同一個 transaction 內；若寫入 log 因唯一索引衝突而失敗，整個 transaction（含付款狀態變更）會一起回滾，只回傳 200 acknowledge，不會重複扣款/通知
- 支援事件：`checkout.session.completed`（確認付款，`status=confirmed`／`payment_status=paid`）、`checkout.session.async_payment_failed` / `checkout.session.expired`（標記 `status=payment_failed`）
- 若付款完成時該筆預約已被使用者取消，只記錄款項已收到（`payment_status=paid`），不會復原已取消的預約狀態；退款流程視為後續手動處理，不在本階段範圍內
- 金流串接使用 Stripe **測試環境**金鑰（`sk_test_`/`whsec_test_` 前綴），`.env.example` 中相關欄位皆為空值

## 目前進度

- [x] Phase 1：專案初始化、Docker Compose 環境、`users` / `resources` / `time_slots` 資料表遷移
- [x] Phase 2：認證（Sanctum）、資源與時段 CRUD、API 版本前綴與 Rate Limiting
- [x] Phase 3：預約核心邏輯（併發鎖）、Queue 通知
- [x] Phase 4：金流 Webhook 整合（簽章驗證、冪等性）
- [ ] Phase 5：Pest 測試
- [ ] Phase 6：排程結算報表、API 文件
- [ ] Phase 7（選做）：部署
