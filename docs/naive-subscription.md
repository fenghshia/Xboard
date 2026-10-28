# NaïveProxy 订阅下发

本修改基于 `cedar2025/Xboard` 的 `935522d28bbeb666824facd8bdd674db8ab37df1`。
仓库已有 `naive` 节点模型、后台校验与节点端配置接口；本修改补齐用户订阅输出。

## 支持范围

| 输出 | 行为 |
| --- | --- |
| 通用 Base64 订阅（`flag=general`） | 加入 `naive+https://用户名:密码@地址:端口?sni=域名#名称` |
| Shadowrocket（自动识别 UA 或 `flag=shadowrocket`） | 加入 `http2://Base64(用户名:密码@地址:端口)?padding=1&peer=SNI#名称`，显式开启 Naive padding |
| sing-box JSON（`flag=sing-box/1.13.0`） | 加入 `type: naive` 出站，并纳入模板中的 selector/urltest 节点列表 |
| sing-box 1.12 及更早版本、核心版本未知 | 不下发 Naive，保留其他兼容节点 |
| Hiddify/SFM 等包装客户端 | 只有 UA 明确提供 `sing-box/1.13.0` 或更高核心版本时才下发；不把 GUI 版本当核心版本 |
| Clash/ClashMeta、Surge 等其他格式 | 保持原有行为，不向它们强行加入未适配的 Naive 字段 |

这次提供 HTTPS/HTTP2 方式，不新增 QUIC 开关。Naive 节点必须设置 `protocol_settings.tls = 1`；
TLS 关闭、缺失或设置为 REALITY 的节点不会被伪装成 HTTPS 导出。

## 后台节点与认证

1. 节点类型选择 `naive`，开启 TLS，使用有效的网站证书。
2. 填写节点连接地址、端口、TLS 服务器名称（SNI）及对应的权限组。
3. 用户订阅中用户名与密码均采用该用户的节点凭据。当前仓库对 Naive 生成的凭据为用户 UUID，
   与已有 HTTP/SOCKS 订阅的双凭据约定一致。
   手动外部节点则保留管理员填写的独立用户名与密码，具体见 `docs/manual-external-nodes.md`。
4. **节点程序必须实际支持 Naive，并将面板用户 UUID 映射为上述用户名和密码。**
   面板能生成订阅，并不表示 Xray 本体或任意节点后端就能提供 Naive 服务。
   若你手动部署 Caddy/forwardproxy，需要另外完成用户同步、流量统计等对接；本修改不包含这些功能。

## 导入订阅

在已有订阅链接后加参数（保留原 token）：

```text
https://你的面板/api/v1/client/subscribe?token=原token&flag=general&types=naive
https://你的面板/api/v1/client/subscribe?token=原token&flag=sing-box/1.13.0&types=naive
https://你的面板/api/v1/client/subscribe?token=原token&flag=shadowrocket&types=naive
```

第一种输出 Base64 编码的分享链接，适用于支持 `naive+https` 的客户端。
链接中的 `sni` 是客户端扩展，支持情况取决于客户端；若需要固定 IP 连接并使用不同的证书域名，
优先使用 sing-box JSON，它分别保留 `server` 与 `tls.server_name`。

第二种仅应在你确认本机实际使用 sing-box 1.13+ 且该构建包含 Naive/Cronet 支持时使用。
手动添加 `flag` 不会升级客户端，也不会安装 Cronet。
原版 `naive` 命令行客户端不直接读取这些订阅；它需要自己的 `listen`/`proxy` JSON 配置。

Shadowrocket 应使用第三种或直接用原订阅链接自动识别。它使用专用的 `http2://` 分享格式，
而非 General 的 `naive+https://`。`http2` 在此表示 TLS 上的 HTTP/2 代理，`padding=1` 开启 Naive 填充，
`peer` 保留证书域名/SNI；不输出 HTTP/3、UoT 或跳过证书验证。
用户名/密码保留手动外部节点固定凭据；托管节点仍使用用户节点凭据。
更新面板并重启后，在 Shadowrocket 刷新订阅；如果订阅 URL 已有 `flag=general`，改为 `flag=shadowrocket`。
建议使用最新版 Shadowrocket（2.2.80 更新记录包含 Naive padding 与旧版 HTTPS 代理兼容性修复）。
订阅格式参考：[fscarmen/sing-box 的 Shadowrocket Naive 导出](https://github.com/fscarmen/sing-box/blob/main/sing-box.sh)。
自动化测试验证订阅生成、UA 路由及参数保留；尚需实际 iOS 设备验证导入和连通性。

sing-box 示例结构（凭据为演示值）：

```json
{
  "type": "naive",
  "tag": "Naive 示例",
  "server": "proxy.example.com",
  "server_port": 443,
  "username": "用户UUID",
  "password": "用户UUID",
  "tls": {
    "enabled": true,
    "server_name": "proxy.example.com"
  }
}
```

不输出 Naive 不支持的 `insecure`、uTLS、ALPN，也不启用额外并发连接。
面板中的 `allow_insecure` 不会关闭 Naive 的证书验证。sing-box 的公开 ECH 配置沿用现有转换，
不下发服务端私钥；通用分享链接不携带 ECH 配置。

## 应用修改

先备份当前代码，并确认本地修改没有冲突。在对应 Xboard 源码根目录执行：

```bash
git apply --check /path/to/xboard-naive-subscription.patch
git apply /path/to/xboard-naive-subscription.patch
```

无需数据库迁移，也无需修改 `composer.json`。如果使用 Docker 预构建镜像，需要用修改后的源码重新构建镜像，
或采用你现有的源码挂载部署方式；仅修改宿主机未挂载的文件不会改变容器内代码。
完成后按现有部署方式重启面板的 PHP/Octane 服务，再刷新客户端订阅。

## 验证

安装项目依赖后运行：

```bash
vendor/bin/phpunit --bootstrap vendor/autoload.php tests/Unit/Protocols/NaiveSubscriptionTest.php
```

回归覆盖通用订阅与原有节点共存、IPv6、凭据/中文名称编码、SNI、TLS 状态、
Naive JSON、selector/urltest 引用、旧版/未知核心过滤、ECH 私钥不泄露，以及 ClashMeta 的排除行为。
这些是订阅生成测试；实际认证、证书链、转发和计费仍需在你的节点后端上做端到端验证。

参考：[sing-box Naive 出站](https://sing-box.sagernet.org/configuration/outbound/naive/)、
[Naive 分享链接约定](https://github.com/klzgrad/naiveproxy/issues/86)。
