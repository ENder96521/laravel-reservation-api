# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_SANCTUM_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

透過 <code>POST /api/v1/register</code> 或 <code>POST /api/v1/login</code> 取得 Sanctum token，並帶在 <code>Authorization: Bearer {token}</code>。
