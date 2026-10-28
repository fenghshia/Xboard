# 手动外部节点（v1.2.0）

用于将自行部署、没有订阅服务、没有接入面板后端 API 的节点，与面板托管节点一起下发。

## 入口与操作

1. 登录管理员后台，打开“节点管理”。
2. 点击页面右下角“手动外部节点”，进入独立管理页。
3. 点击“添加节点”，填写名称、协议、连接地址、端口和固定凭据，勾选有权获取的权限组。
4. 根据服务端配置修改“协议参数”JSON，例如 TLS 服务器名称、XHTTP/WS 路径、Reality 公钥或 Shadowsocks cipher。
5. 保存后，更新客户端原有订阅即可，无需在节点服务器上部署订阅程序。

也可直接访问 `/你的后台路径/external-nodes`，复用同一域名下已有的管理员登录状态。
此页面的所有数据接口沿用后台管理员认证。未登录时先返回主后台登录。

外部节点在此页单独维护，不会被普通托管节点的编辑、批量删除或机器分配操作修改。
“启用订阅下发”关闭后该节点不再出现在新生成的订阅中。它不会撤回已经导出的固定凭据。

## 凭据

| 协议 | 必填固定认证信息 |
| --- | --- |
| VLESS / VMess | UUID |
| Trojan / Shadowsocks / Hysteria / AnyTLS | 密码或认证字符串 |
| Naïve / HTTP / SOCKS / Mieru | 独立用户名、密码 |
| TUIC v5 | UUID、独立密码 |

凭据使用现有 APP_KEY 加密存储，不在节点列表或编辑表单回显，也不写入管理员审计日志。
编辑时留空保留原凭据，填写新值则替换；更换协议后需要填写新协议的凭据。
请保留原来的 `.env` / APP_KEY，随数据库一起备份。

General、Clash/ClashMeta、sing-box 以及已有其他导出器会使用节点凭据；各格式仍按原来的协议和版本兼容范围过滤。
不是所有客户端都支持所有协议，例如 Naive 仍需支持 Naive 的客户端，XHTTP 仍需对应核心支持。
本版本不提供分享链接导入、远程订阅拉取或合并第三方订阅。

## 连接参数示例

VLESS + XHTTP + TLS：

```json
{
  "tls": 1,
  "tls_settings": {"server_name": "node.example.com", "allow_insecure": false},
  "network": "xhttp",
  "flow": "",
  "network_settings": {"path": "/yourpath", "mode": "auto"}
}
```

Naive + HTTPS：

```json
{
  "tls": 1,
  "tls_settings": {"server_name": "node.example.com", "allow_insecure": false}
}
```

示例域名和路径必须替换为服务器的实际配置。这里只保存客户端连接参数，不会改动外部服务器。

## 统计与权限

- 只向所选权限组的可用面板用户下发，并沿用订阅的协议/关键词过滤。
- 固定凭据由获得节点配置的用户共用，不支持每个面板用户独立认证或撤销。
- 不向此节点同步面板用户、配置或机器信息；节点端 API 不接受该节点 ID。
- 不纳入面板用户流量扣费和设备限制，也没有后端在线监控；由你自己的服务端负责统计与限制。

## 从 v1.1.0 升级

先备份数据库和 `.env`，将 Compose 的镜像改为 `ghcr.io/fenghshia/xboard:1.2.0`，更新后执行：

```bash
docker compose pull xboard
docker compose up -d xboard
docker compose exec xboard php artisan xboard:update
docker compose restart xboard
```

本版本增加 `v2_server.is_external` 和 `external_credentials` 两列。迁移默认将原节点保留为托管节点。
应在更新后立即执行数据库迁移，迁移完成前不要使用新页面。
非 Docker 部署执行 `php artisan xboard:update` 后重启 PHP/Octane 服务。

## 验证

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/Protocols/NaiveSubscriptionTest.php tests/Unit/External
```

覆盖加密保存与脱敏、编辑保留凭据、11 种协议录入、固定 UUID/用户名/密码输出、TUIC 双凭据、
权限组/显示状态、输入校验、管理员限制、审计脱敏及节点同步隔离。
