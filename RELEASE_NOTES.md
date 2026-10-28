手动外部节点支持版本。

- 在后台“节点管理”右下角增加“手动外部节点”入口，提供新增、编辑、删除和权限组配置。
- 支持 UUID、独立用户名/密码，以及协议参数 JSON，无需外部节点提供订阅服务或接入节点 API。
- General、Clash/ClashMeta、sing-box 等订阅使用外部节点固定凭据；TUIC UUID 和密码独立。
- 固定凭据使用 APP_KEY 加密保存，列表和编辑页不回显，编辑留空保留原值，审计日志脱敏。
- 外部节点与机器分配、面板用户同步及流量扣费隔离。
- 保留 v1.1.0 的 Naïve 订阅支持，并修复固定密码包含特殊字符时的 URI 编码。

验证：51 项 PHP 回归测试、113 项断言通过；后台页面的新增、编辑、删除、错误提示和手机窄屏交互检查通过。

**升级需迁移数据库**：备份数据库及 `.env`，更新镜像后运行 `docker compose exec xboard php artisan xboard:update`，再重启面板。

完整使用说明：`docs/manual-external-nodes.md`。

外部固定凭据由获得节点配置的用户共用，节点实际流量与限制由外部服务器负责。已有客户端缓存中的凭据不会因隐藏节点而自动失效。

Docker 镜像：`ghcr.io/fenghshia/xboard:1.2.0`，提供 linux/amd64 和 linux/arm64。
