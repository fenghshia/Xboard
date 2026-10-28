// Kept outside the compiled admin submodule so upstream frontend updates preserve this entry.
const link = document.createElement('a');
link.textContent = '手动外部节点';
link.href = `/${window.settings.secure_path}/external-nodes`;
link.style.cssText = 'position:fixed;right:24px;bottom:24px;z-index:30;background:#2563eb;color:white;padding:11px 16px;border-radius:8px;font:14px system-ui;box-shadow:0 3px 12px #0002;text-decoration:none';
link.hidden = true;
document.body.append(link);
function update() {
  const nodePage = location.hash.includes('/server/manage') || location.pathname.endsWith('/server/manage');
  let authenticated = false;
  try { const data = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || 'null'); authenticated = !!data?.value && (!data.expire || data.expire > Date.now()); } catch {}
  link.hidden = !nodePage || !authenticated;
}
window.addEventListener('hashchange', update);
window.addEventListener('popstate', update);
setInterval(update, 1500);
update();
