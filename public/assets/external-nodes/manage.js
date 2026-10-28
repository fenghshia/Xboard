const $ = (selector) => document.querySelector(selector);
const form = $('#node-form');
const dialog = $('#editor');
const status = $('#status');
let nodes = [], groups = [], editing = false, busy = false;
const defaults = {
  vless: {tls:1, network:'tcp', flow:'xtls-rprx-vision', tls_settings:{server_name:'', allow_insecure:false}},
  vmess: {tls:1, network:'tcp', tls_settings:{server_name:'', allow_insecure:false}},
  trojan: {tls:1, network:'tcp', tls_settings:{server_name:'', allow_insecure:false}},
  shadowsocks: {cipher:'aes-128-gcm'},
  hysteria: {version:2, tls:{server_name:'', allow_insecure:false}},
  anytls: {tls:{server_name:'', allow_insecure:false}},
  naive: {tls:1, tls_settings:{server_name:'', allow_insecure:false}},
  tuic: {version:5, tls:{server_name:'', allow_insecure:false}, alpn:['h3'], congestion_control:'cubic'},
  socks: {tls:0}, http: {tls:1, tls_settings:{server_name:'', allow_insecure:false}},
  mieru: {transport:'TCP', traffic_pattern:''},
};
function token() {
  try {
    const stored = JSON.parse(localStorage.getItem('XBOARD_ACCESS_TOKEN') || 'null');
    if (stored?.value && (!stored.expire || stored.expire > Date.now())) return stored.value;
  } catch {}
  throw new Error('请先返回节点管理，登录管理员账号后再打开此页面。');
}
async function api(path, data) {
  const response = await fetch(`/api/v2/${window.settings.secure_path}/server/${path}`, {
    method:data === undefined ? 'GET' : 'POST', credentials:'same-origin',
    headers:{'Accept':'application/json','Content-Type':'application/json','Authorization':token()},
    ...(data === undefined ? {} : {body:JSON.stringify(data)}),
  });
  const result = await response.json();
  if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat().join('\n') : result.message || '请求失败');
  return result.data;
}
function td(row, value) { const cell = row.insertCell(); cell.textContent = String(value ?? ''); return cell; }
function showError(error) { status.textContent = error.message; status.className = 'danger'; }
async function refresh() {
  nodes = await api('external/fetch');
  const body = $('#nodes'); body.replaceChildren();
  for (const node of nodes) {
    const row = body.insertRow();
    td(row,node.name); td(row,node.type); td(row,`${node.host}:${node.port}`);
    td(row,(node.group_ids || []).map(id=>groups.find(g=>String(g.id)===String(id))?.name || id).join('、'));
    td(row,node.show?'已开启':'已关闭');
    const actions = row.insertCell();
    const edit = document.createElement('button'); edit.className='secondary';edit.textContent='编辑';edit.onclick=()=>openEditor(node);actions.append(edit);
    const drop = document.createElement('button');drop.className='secondary danger';drop.textContent='删除';drop.onclick=async()=>{
      if (!confirm(`删除外部节点“${node.name}”？这只会移除面板中的配置。`)) return;
      drop.disabled=true;
      try { await api('external/drop',{id:node.id}); await refresh(); status.textContent='节点已移除。';status.className=''; } catch(error){showError(error);} finally{drop.disabled=false;}
    }; actions.append(drop);
  }
  $('#empty').hidden=nodes.length>0;
}
function credentialsFor(type) {
  return ['vless','vmess'].includes(type)?['uuid']:type==='tuic'?['uuid','password']:['naive','http','socks','mieru'].includes(type)?['username','password']:['password'];
}
function updateCredentials() {
  const fields=credentialsFor(form.elements.type.value);
  for (const key of ['uuid','username','password']) {
    const input=form.elements[key];
    input.closest('label').hidden=!fields.includes(key);
    input.required=fields.includes(key)&&!editing;
  }
}
function openEditor(node=null) {
  editing=!!node;form.reset();$('#form-error').textContent='';
  for(const key of ['id','name','host','port','sort','type']) form.elements[key].value=node?.[key] ?? ({port:443,sort:0,type:'vless'}[key] ?? '');
  form.elements.show.checked=node?.show ?? true;
  form.elements.tags.value=(node?.tags || []).join(',');
  form.elements.protocol_settings.value=JSON.stringify(node?.protocol_settings || defaults.vless,null,2);
  $('#editor-title').textContent=editing?'编辑外部节点':'添加外部节点';
  $('#credential-hint').textContent=editing?'认证信息不回显；留空保留原值。更换协议后必须重新填写凭据。':'填写服务器实际使用的固定凭据。';
  $('#groups').replaceChildren();
  for(const group of groups) {
    const label=document.createElement('label');label.className='check';
    const input=document.createElement('input');input.type='checkbox';input.name='group_ids';input.value=group.id;input.checked=(node?.group_ids||[]).map(String).includes(String(group.id));
    label.append(input,document.createTextNode(group.name));$('#groups').append(label);
  }
  updateCredentials();dialog.showModal();form.elements.name.focus();
}
form.elements.type.onchange=()=>{
  editing=false;
  for(const key of ['uuid','username','password'])form.elements[key].value='';
  form.elements.protocol_settings.value=JSON.stringify(defaults[form.elements.type.value],null,2);
  $('#credential-hint').textContent='协议已更换，请重新填写该协议的全部固定凭据。';updateCredentials();
};
$('#reset-settings').onclick=()=>{if(confirm('用所选协议的示例替换当前协议参数？'))form.elements.protocol_settings.value=JSON.stringify(defaults[form.elements.type.value],null,2);};
form.onsubmit=async(event)=>{
  event.preventDefault();if(busy)return;
  $('#form-error').textContent='';
  try {
    const selected=[...form.querySelectorAll('[name="group_ids"]:checked')].map(input=>input.value);
    if(!selected.length)throw new Error('请至少选择一个权限组。');
    const settings=JSON.parse(form.elements.protocol_settings.value);
    if(!settings || Array.isArray(settings) || typeof settings!=='object')throw new Error('协议参数必须是 JSON 对象。');
    const data={name:form.elements.name.value,type:form.elements.type.value,host:form.elements.host.value,port:Number(form.elements.port.value),sort:Number(form.elements.sort.value),group_ids:selected,show:form.elements.show.checked,tags:form.elements.tags.value.split(/[,，]/).map(s=>s.trim()).filter(Boolean),protocol_settings:settings,credentials:{}};
    if(form.elements.id.value)data.id=Number(form.elements.id.value);
    for(const key of credentialsFor(data.type))if(form.elements[key].value!=='')data.credentials[key]=form.elements[key].value;
    busy=true;$('#save-node').disabled=true;
    await api('external/save',data);dialog.close();await refresh();status.className='';status.textContent='已保存，更新客户端订阅即可获取配置。';
  }catch(error){if(dialog.open)$('#form-error').textContent=error instanceof SyntaxError?'协议参数 JSON 格式不正确。':error.message;else showError(error);}finally{busy=false;$('#save-node').disabled=false;}
};
$('#new-node').onclick=()=>openEditor();
for(const id of ['close-editor','cancel-editor'])$('#'+id).onclick=()=>{if(!busy)dialog.close();};
dialog.addEventListener('cancel',event=>{if(busy)event.preventDefault();});
try { groups=await api('group/fetch');await refresh(); }catch(error){showError(error);$('#new-node').disabled=true;}
