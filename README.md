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

## 目前進度

- [x] Phase 1：專案初始化、Docker Compose 環境、`users` / `resources` / `time_slots` 資料表遷移
- [x] Phase 2：認證（Sanctum）、資源與時段 CRUD、API 版本前綴與 Rate Limiting
- [ ] Phase 3：預約核心邏輯（併發鎖）、Queue 通知
- [ ] Phase 4：金流 Webhook 整合（簽章驗證、冪等性）
- [ ] Phase 5：Pest 測試
- [ ] Phase 6：排程結算報表、API 文件
- [ ] Phase 7（選做）：部署
