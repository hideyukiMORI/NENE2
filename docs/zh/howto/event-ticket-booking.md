# 操作指南：活动门票预订

演示带每用户购票功能的活动容量管理。
字段试验：FT196（`../NENE2-FT/ticketlog/`）。包含 ATK-01~12 黑客攻击测试。

## 模式概述

| 关注点 | 方法 |
|--------|------|
| 容量追踪 | `remaining = capacity - COUNT(tickets)` 在读取时计算 |
| 售罄 | `remaining <= 0` 时返回 409 Conflict |
| 重复购买 | `UNIQUE(event_id, user_id)` → 捕获并发重复购买 |
| IDOR 取消 | `user_id` 所有权检查 → 不匹配时返回 403 |
| 管理员密钥 | `hash_equals()` 失败安全 |

## ATK-01~12 结果：全部通过
