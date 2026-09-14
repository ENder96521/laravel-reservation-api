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

## 目前進度

- [x] Phase 1：專案初始化、Docker Compose 環境、`users` / `resources` / `time_slots` 資料表遷移
- [ ] Phase 2：認證（Sanctum）、資源與時段 CRUD、API 版本前綴與 Rate Limiting
- [ ] Phase 3：預約核心邏輯（併發鎖）、Queue 通知
- [ ] Phase 4：金流 Webhook 整合（簽章驗證、冪等性）
- [ ] Phase 5：Pest 測試
- [ ] Phase 6：排程結算報表、API 文件
- [ ] Phase 7（選做）：部署
