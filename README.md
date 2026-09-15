# 線上預約系統 API（Reservation System API）

Laravel API-only 後端作品集專案。以「工程細節深度」為核心：真實的併發搶位控制、Webhook 簽章驗證與冪等性處理、API 版本控制、Rate Limiting、非同步任務、容器化開發環境與自動產生的 API 文件。詳細規劃見專案企劃書。

## 技術棧

| 項目 | 選型 |
|---|---|
| 框架 | Laravel 13（純 API 模式） |
| 資料庫 | MySQL 8.4 |
| 快取／Session／Queue | Redis（Predis） |
| API 認證 | Laravel Sanctum（Token-based） |
| 測試 | Pest v4（含 architecture testing） |
| 容器化 | Docker Compose（app / nginx / mysql / redis / queue / scheduler） |
| API 文件 | Scribe（靜態產生，`public/docs/`） |
| 金流 | Stripe Checkout（測試環境金鑰） |
| 通知 | LINE Notify（透過 Queue Job 非同步發送） |
| 靜態分析 | Larastan（PHPStan + Laravel 規則），CI 強制執行 |
| 可觀測性 | Laravel Telescope（本地）、Sentry（正式環境錯誤／慢查詢追蹤） |

## 快速開始

```bash
cp .env.example .env
docker compose build
docker compose up -d
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

- API：`http://localhost:8000/api/v1/...`
- API 文件：`http://localhost:8000/docs`（靜態頁面，不需額外指令即可瀏覽）

Docker Compose 服務：

| Service | 用途 |
|---|---|
| `app` | PHP-FPM，處理 API 請求 |
| `nginx` | 對外服務、轉發至 `app` |
| `mysql` | 主資料庫 |
| `redis` | 快取／Session／Queue |
| `queue` | `php artisan queue:work`，處理通知等非同步任務 |
| `scheduler` | `php artisan schedule:work`，觸發每日結算報表 |

## 專案結構重點

```
app/
  Console/Commands/GenerateDailyReport.php   # 每日結算報表
  Contracts/PaymentGateway.php               # 金流閘道介面
  Exceptions/TimeSlotFullException.php       # 額滿例外（渲染 409）
  Http/Controllers/Api/V1/                   # 各版本 API controller
  Http/Middleware/EnsureUserIsAdmin.php      # 角色權限
  Jobs/SendBookingNotification.php           # 通知 Queue Job
  Models/                                    # User / Resource / TimeSlot / Booking / NotificationLog / PaymentWebhookLog
  Services/BookingService.php                # 預約核心邏輯（併發鎖、冪等性）
  Services/Notifications/LineNotifier.php    # LINE Notify 串接
  Services/Payments/                         # Stripe / Null 金流閘道實作
routes/
  api.php      # 掛載版本 prefix
  api_v1.php   # v1 實際路由
  console.php  # artisan 排程
tests/
  Feature/     # 端到端測試（含併發、冪等性、簽章驗證、rate limit）
  Arch/        # 架構測試
```

## API 版本控制

路由統一走 `/api/v1/...` 前綴（見 `routes/api.php` 與 `routes/api_v1.php`）。未來新增 `/api/v2` 時，只需新增 `routes/api_v2.php` 並在 `routes/api.php` 註冊對應 prefix group，不影響 v1 既有客戶端。

## 認證與權限

- `POST /api/v1/register`、`POST /api/v1/login`：套用 `throttle:auth`（每 IP 每分鐘 10 次），回傳 Sanctum token
- `POST /api/v1/logout`、`GET /api/v1/me`：需帶 `Authorization: Bearer {token}`
- 資源／時段的讀取（`GET`）：任何已登入使用者
- 資源／時段／預約的建立／修改／刪除：讀取端點對所有登入者開放；resources 與 time-slots 的寫入端點需 `admin` 角色（見 `App\Http\Middleware\EnsureUserIsAdmin`）；預約（bookings）的建立/取消對所有登入者開放（取消僅限本人或 admin）

## 核心功能

### 資源與時段管理
CRUD API：`resources`、`resources/{resource}/time-slots`。時段列表支援 `?available=1` 篩掉已額滿的時段（`booked_count < capacity`）。

### 預約與併發搶位控制（本專案核心賣點）
`App\Services\BookingService::book()` 在 `DB::transaction` 內對目標時段執行 `lockForUpdate()`，同一時段的所有併發請求會被資料庫序列化，確保 `booked_count` 永遠不會超過 `capacity`。

- `POST /api/v1/bookings`：套用 `throttle:booking`（每使用者每分鐘 5 次），可帶 `idempotency_key` 防止重複送出造成的重複預約
- `DELETE /api/v1/bookings/{booking}`：取消預約並釋放名額（本人或 admin 可操作，重複取消不會重複釋放名額）
- 預約成功／取消後，透過 Queue Job（`SendBookingNotification`）非同步發送 LINE Notify 通知並寫入 `notification_logs`；未設定 `LINE_NOTIFY_TOKEN` 時退回寫 log，不影響流程

### 金流 Webhook（Stripe 測試環境，本專案另一核心賣點）
- 預約付費項目時（`resource.price > 0`），`BookingService` 會呼叫 `App\Contracts\PaymentGateway` 產生 Stripe Checkout Session，並把付款連結存回 `bookings.payment_url`。未設定 `STRIPE_SECRET` 時自動退回 `NullPaymentGateway`（回傳假連結），本地開發／測試不需要真的申請 Stripe 金鑰
- `POST /api/v1/webhooks/stripe`：公開路由（不經 Sanctum），改用 `Stripe\Webhook::constructEvent()` 驗證 `Stripe-Signature` 標頭簽章，偽造或缺少簽章一律回 400
- 冪等性採三層防護：
  1. `payment_webhook_logs` 對 `(provider, event_id)` 建唯一索引，同一事件重複送達會在 DB 層擋下第二次寫入
  2. `BookingService::confirmPayment()` / `failPayment()` 本身也是冪等操作（已是 `paid` 就不再變動、也不會重複派發通知）
  3. 業務邏輯與寫入 log 包在同一個 transaction 內；若寫入 log 因唯一索引衝突而失敗，整個 transaction（含付款狀態變更）會一起回滾，只回傳 200 acknowledge，不會重複扣款/通知
- 支援事件：`checkout.session.completed`（確認付款）、`checkout.session.async_payment_failed` / `.expired`（標記 `payment_failed`）
- 若付款完成時該筆預約已被使用者取消，只記錄款項已收到（`payment_status=paid`），不會復原已取消的預約狀態；退款流程視為後續手動處理，不在本專案範圍內
- 金流串接一律使用 Stripe **測試環境**金鑰（`sk_test_`/`whsec_test_` 前綴），`.env.example` 中相關欄位皆為空值

### 每日結算報表（Scheduler）
- `php artisan report:daily {date?}`：統計指定日期（預設為昨天）每個 resource 的預約數（total/confirmed/pending/cancelled/payment_failed）與已付款金額，輸出 CSV 到 `storage/app/reports/daily-{date}.csv`
- 已在 `routes/console.php` 註冊 `Schedule::command('report:daily')->dailyAt('00:10')`；由獨立的 `scheduler` container（`php artisan schedule:work`）觸發，與 `queue` worker 分離成不同 process
- 手動測試：
  ```bash
  docker compose exec app php artisan report:daily 2026-09-14
  docker compose exec app cat storage/app/reports/daily-2026-09-14.csv
  ```

## API 文件

已產生為靜態頁面（`public/docs/index.html` + `openapi.yaml` + `collection.json`），啟動服務後開啟 `http://localhost:8000/docs` 即可瀏覽，不需額外指令。API 有變更時重新產生：

```bash
docker compose exec app php artisan scribe:generate
```

- 每個 Controller 都有 `@group` 分組與範例 `@response`（涵蓋 201/400/403/409/429 等常見情境）
- 未登入即可呼叫的端點（`register`/`login`/Stripe webhook）已個別標註 `@unauthenticated`
- Body 參數自動從各 FormRequest 的驗證規則萃取

## 可觀測性（Telescope / Sentry）

不是寫完就丟著——本地開發用 Telescope 即時看請求/查詢/Job，正式環境用 Sentry 收集例外與慢查詢，兩者都是「加了才會動」，沒設定不會有任何開銷。

- **Laravel Telescope**（`laravel/telescope`，dev-only 依賴）：只在 `APP_ENV=local` 時才會註冊（見 `App\Providers\AppServiceProvider::register()`），用 `class_exists()` 額外防呆，確保正式環境用 `composer install --no-dev` 安裝時完全不會載入到這個類別。開發時開啟 `http://localhost:8000/telescope` 可看到每個請求的 SQL 查詢（含執行時間）、Queue Job、Exception、Cache 命中等
- 非 local 環境（若真的要開，需自行調整 `TELESCOPE_ENABLED`）存取 Telescope 需要通過 `viewTelescope` gate（`app/Providers/TelescopeServiceProvider.php`），目前設定為僅 `admin` 角色可看
- **Sentry**（`sentry/sentry-laravel`，一般依賴）：由 `SENTRY_LARAVEL_DSN` 環境變數控制，**留空時完全不啟用**（no-op），`.env.example` 預設空值，不會意外把測試環境的錯誤送到正式的 Sentry 專案
- 已在 `bootstrap/app.php` 的 `withExceptions()` 接上 `Sentry\Laravel\Integration::handles()`，未攔截的例外會自動回報
- `config/sentry.php` 預設開啟 SQL 查詢追蹤與慢查詢偵測（`sql_origin_threshold_ms`，預設 100ms），搭配 `SENTRY_TRACES_SAMPLE_RATE` 控制取樣率，避免正式環境流量大時全量追蹤造成效能負擔

## 靜態分析（Larastan / PHPStan）

```bash
docker compose exec app composer analyse
```

- 設定於 `phpstan.neon`，等級 5，掃描 `app/`、`database/`、`routes/`
- CI（`.github/workflows/tests.yml` 的 `static-analysis` job）強制執行，PR 有新的靜態分析錯誤會直接讓 CI 變紅——這是團隊協作場景下「別人看得到你有沒有守規矩」的訊號，不是本機測完就算了
- 專案內的 Eloquent 關聯全部補上泛型型別標註（例如 `@return BelongsTo<TimeSlot, $this>`），API Resource 類別補上 `@mixin` 指向底層 Model，讓 Larastan 能正確推導 Eloquent 的動態屬性/方法，而不是用 baseline/`@phpstan-ignore` 蓋掉錯誤

## 測試

```bash
docker compose exec app php artisan test
```

目前 40 個測試（39 通過、1 個在 sqlite 下自動跳過），聚焦在容易出錯、面試容易被追問的邏輯，而非覆蓋率數字：

- **並發搶位**：`tests/Feature/BookingTest.php` 模擬多個請求搶同一個只剩少量名額的時段，驗證成功筆數精確等於剩餘容量（不多不少），`booked_count` 與實際 `bookings` 筆數保持一致
- **真實 row lock 驗證**：`tests/Feature/BookingRowLockTest.php` 在 sqlite 下自動跳過（sqlite 沒有真正的 row-level lock 可驗證），改用兩條獨立的 MySQL 連線證明 `lockForUpdate()` 真的會讓第二個連線阻塞直到逾時（`innodb_lock_wait_timeout`）；在有 MySQL 的環境下執行：
  ```bash
  docker compose exec app bash -c "DB_CONNECTION=mysql php artisan test --filter=BookingRowLockTest"
  ```
- **Webhook 冪等性**：`tests/Feature/StripeWebhookTest.php` 送出同一個事件兩次，驗證訂單狀態與通知只處理一次
- **Rate Limiting**：驗證註冊/登入與預約提交超過限制後回傳 429
- **Webhook 簽章驗證**：手刻與 Stripe 官方演算法一致的簽章產生器，驗證偽造或缺少簽章一律被拒絕（400）
- **Arch 測試**：`tests/Arch/CodebaseTest.php` 用 Pest 的 architecture testing 確保沒有殘留的 `dd()`/`dump()`、Controller 繼承正確、Model/Service 不誤依賴 HTTP 層

> 完整測試套件已同時在 sqlite（預設）與真實 MySQL 下驗證過，兩邊皆全數通過，確認併發鎖與 JSON 欄位等邏輯不是 sqlite 特有行為造成的假象。

## 壓力測試：用數據證明併發控制真的有效

自動化測試只回答「邏輯對不對」，壓力測試回答「量大的時候還撐不撐得住」。用 [k6](https://k6.io) 對 `POST /api/v1/bookings` 做真正的併發壓測，並且做了「拿掉鎖」與「加鎖」的對照組：

- **300 併發請求搶 20 個名額**：拿掉 `lockForUpdate()` 後連續 3 次測試分別超賣到 42、45、44 筆；加回鎖之後連續 3 次都精準卡在 20 筆
- **放大到 1000 併發搶 50 個名額**：未加鎖超賣到 73 筆（超賣 46%），加鎖版本精準卡在 50 筆
- **吞吐量幾乎沒有差**：加鎖前後的 QPS 都落在 140～168 req/s 區間，代表這把鎖換來的正確性幾乎是「免費」的，因為同一列的資料庫寫入本來就無法真正平行處理

完整方法論、原始數據與如何重現，見 **[docs/load-test-report.md](docs/load-test-report.md)**（測試腳本在 `docs/load-test/`）。

## 資料庫設計

| 資料表 | 用途 |
|---|---|
| `users` | 使用者，含 `role`（user/admin） |
| `resources` | 可預約項目（課程／場地），`price` 以最小貨幣單位（分）儲存 |
| `time_slots` | 開放時段，`booked_count` 與 `capacity` 用於併發控制 |
| `bookings` | 預約紀錄，含 `status`／`payment_status`／`idempotency_key`／`payment_url` |
| `notification_logs` | LINE Notify 發送紀錄 |
| `payment_webhook_logs` | Stripe webhook 事件紀錄，`(provider, event_id)` 唯一索引做冪等性防護 |

## 開發階段進度

| 階段 | 內容 | 狀態 |
|---|---|---|
| Phase 1 | 專案初始化、Docker Compose 環境、`users`/`resources`/`time_slots` 遷移 | ✅ |
| Phase 2 | 認證（Sanctum）、資源與時段 CRUD、API 版本前綴與 Rate Limiting | ✅ |
| Phase 3 | 預約核心邏輯（併發鎖）、Queue 通知 | ✅ |
| Phase 4 | 金流 Webhook 整合（簽章驗證、冪等性） | ✅ |
| Phase 5 | Pest 測試（並發、冪等性、Rate Limiting、簽章驗證） | ✅ |
| Phase 6 | Scheduler 每日結算報表、API 文件 | ✅ |
| Phase 7（選做） | 部署到 EC2 作為 API 子網域 | 暫緩 |
| 額外強化 | 壓力測試報告、Larastan 靜態分析 + CI、Telescope/Sentry 可觀測性 | ✅ |

Phase 1–6（企劃書必要範圍）皆已完成並通過測試。Phase 7 屬選做的部署步驟，因涉及實際伺服器／網域等環境細節，暫不在此 repo 內處理。
