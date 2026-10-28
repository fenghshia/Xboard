修复 Shadowrocket 订阅中缺失 Naïve 节点的问题。

- Shadowrocket 专用出口加入 Naïve 白名单和导出分支，自动识别 UA 或 `flag=shadowrocket` 均可下发。
- 使用 Shadowrocket 的 `http2://Base64(用户名:密码@地址:端口)?padding=1&peer=SNI#名称` 格式，开启 Naïve padding。
- 保留手动外部节点的独立用户名、密码、IPv6、端口和 SNI；托管节点使用原有节点凭据。
- 不将无 TLS 或 REALITY 节点伪装成 Naïve TLS 节点，不下发跳过证书验证、HTTP/3 或 UoT。
- 保留 General、sing-box 及其他协议的现有订阅行为。

更新并重启面板后，在 Shadowrocket 刷新原订阅；如果 URL 带有 `flag=general`，改为 `flag=shadowrocket`。
从 v1.2.0 升级无需新增数据库迁移；更早版本仍需执行 v1.2.0 的外部节点迁移。

验证：56 项回归测试、136 项断言通过，包含订阅实际输出、UA 自动识别、显式 flag、原有节点共存、凭据与 IPv6、padding/SNI、非 TLS 排除等回归测试。尚未在实际 iOS 设备上验证导入与连通性。

完整说明：`docs/naive-subscription.md`。
Docker 镜像：`ghcr.io/fenghshia/xboard:1.2.1`，提供 linux/amd64 和 linux/arm64。
