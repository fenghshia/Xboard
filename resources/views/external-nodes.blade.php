<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>手动外部节点 · {{ $title }}</title>
  <link rel="stylesheet" href="/assets/external-nodes/style.css?v={{ $version }}">
  <script>window.settings = @json(['secure_path' => $secure_path]);</script>
  <script type="module" src="/assets/external-nodes/manage.js?v={{ $version }}"></script>
</head>
<body>
  <main class="workspace">
    <header><div><a href="/{{ $secure_path }}#/server/manage">← 返回节点管理</a><h1>手动外部节点</h1><p>分发你自行部署的节点，使用节点自己的固定认证信息。</p></div><button id="new-node">添加节点</button></header>
    <aside>仅向所选权限组下发配置。同一节点凭据由获得配置的用户共用；不进行面板用户同步、流量计费或在线监控。</aside>
    <p id="status" role="status" aria-live="polite"></p>
    <section class="panel"><div class="table-scroll"><table><thead><tr><th>节点</th><th>协议</th><th>地址</th><th>权限组</th><th>下发</th><th>操作</th></tr></thead><tbody id="nodes"></tbody></table></div><p id="empty" hidden>还没有外部节点。点击“添加节点”，录入已有服务器的连接参数。</p></section>
  </main>
  <dialog id="editor" aria-labelledby="editor-title">
    <form id="node-form">
      <div class="dialog-heading"><h2 id="editor-title">添加外部节点</h2><button type="button" id="close-editor" class="secondary">关闭</button></div>
      <input type="hidden" name="id">
      <div class="grid">
        <label>节点名称<input name="name" required maxlength="128" placeholder="例如：个人 VPS · 东京"></label>
        <label>协议<select name="type"><option value="vless">VLESS</option><option value="vmess">VMess</option><option value="trojan">Trojan</option><option value="shadowsocks">Shadowsocks</option><option value="hysteria">Hysteria 2</option><option value="anytls">AnyTLS</option><option value="naive">NaïveProxy</option><option value="tuic">TUIC v5</option><option value="socks">SOCKS5</option><option value="http">HTTP / HTTPS</option><option value="mieru">Mieru</option></select></label>
        <label>连接地址<input name="host" required placeholder="域名、IPv4 或 IPv6，不含协议前缀"></label>
        <label>端口<input name="port" type="number" min="1" max="65535" value="443" required></label>
        <label data-credential="uuid">UUID<input name="uuid" type="password" autocomplete="new-password" placeholder="填写服务器实际使用的 UUID"></label>
        <label data-credential="username">用户名<input name="username" autocomplete="off" placeholder="填写服务器实际使用的用户名"></label>
        <label data-credential="password">密码 / 认证字符串<input name="password" type="password" autocomplete="new-password" placeholder="填写服务器实际使用的密码"></label>
        <label>排序<input name="sort" type="number" min="0" value="0"></label>
      </div>
      <p class="hint" id="credential-hint">固定凭据仅用于订阅下发。</p>
      <fieldset><legend>可获取节点的权限组</legend><div id="groups" class="groups"></div></fieldset>
      <label class="check"><input name="show" type="checkbox" checked> 启用订阅下发</label>
      <label>标签（用逗号分隔）<input name="tags" placeholder="个人节点,东京"></label>
      <div class="section-heading"><h3>协议参数</h3><button type="button" id="reset-settings" class="secondary">恢复该协议示例</button></div>
      <p class="hint">按实际部署填写 TLS、SNI、传输方式、路径及其他参数；下面是可编辑的 protocol_settings JSON。不要在这里填写 UUID 或用户密码。</p>
      <label><span class="sr-only">协议参数 JSON</span><textarea name="protocol_settings" rows="12" spellcheck="false" required></textarea></label>
      <p class="form-error" id="form-error" role="alert"></p>
      <footer><button type="button" id="cancel-editor" class="secondary">取消</button><button type="submit" id="save-node">保存节点</button></footer>
    </form>
  </dialog>
</body>
</html>
