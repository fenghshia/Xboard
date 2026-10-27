本版本为 fenghshia/Xboard 的 NaïveProxy 订阅支持版本。

- General 通用 Base64 订阅新增 `naive+https` 链接，支持 IPv6、SNI 和凭据/名称编码。
- sing-box 1.13+ 新增 Naive 出站，自动加入 selector/urltest。
- 对旧版及核心版本未知的 sing-box 客户端过滤 Naive；保持其他节点下发。
- 不下发 Naive 不支持的 insecure、uTLS、ALPN，不额外开启并发连接。
- Actions 在测试通过后构建 linux/amd64、linux/arm64 Docker 镜像，成功后发布 Release。
- 镜像固定到触发构建的提交，面板版本为 1.1.0。

验证：17 项订阅回归测试通过，38 项断言；PHP 语法检查通过。

使用说明见 `docs/naive-subscription.md`。Naive 节点需要开启 TLS，节点程序需支持 Naive，
并使用面板用户 UUID 作为用户名与密码；本版本不包含节点后端的新增实现。

Docker 部署请使用 `ghcr.io/fenghshia/xboard:1.1.0`（也提供 `latest` 与 `v1.1.0` 标签）。
