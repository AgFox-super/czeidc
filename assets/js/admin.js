/* ============ xnzj 后台 SPA ============ */
(function () {
    'use strict';
    var B = window.BOOT || {};
    var me = B.me;
    var state = { ordersFilter: '', ticketsFilter: '' };

    function $(sel) { return document.querySelector(sel); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function api(action, data) {
        data = data || {};
        var body = new URLSearchParams();
        body.append('action', action);
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF': B.csrf },
            body: body
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.ok) { throw new Error(j.msg || '操作失败'); }
            return j.data;
        });
    }
    function toast(msg, type) {
        var t = document.createElement('div');
        t.className = 'toast' + (type === 'err' ? ' err' : '');
        t.textContent = msg;
        $('#toast').appendChild(t);
        setTimeout(function () { t.remove(); }, 2800);
    }
    function confirmDlg(msg) { return window.confirm(msg); }
    function loading() { return '<div class="loading"><span class="spin"></span>加载中…</div>'; }
    function fmt(t) { return t ? String(t).replace(' ', ' ') : '-'; }
    function money(n) { return '¥' + Number(n).toFixed(2); }
    function badge(s, map) {
        var m = map[s] || ['gray', s];
        return '<span class="badge ' + m[0] + '">' + m[1] + '</span>';
    }
    var orderBadge = { pending: ['warn', '待支付'], paid: ['ok', '已支付'], cancelled: ['gray', '已取消'] };
    var prodBadge = { 0: ['err', '开通失败'], 1: ['ok', '正常'], 2: ['warn', '已暂停'], 3: ['gray', '已删除'] };
    var tkBadge = { open: ['warn', '待处理'], replied: ['info', '已回复'], closed: ['gray', '已关闭'] };

    function showModal(html) {
        $('#modalBox').innerHTML = html;
        $('#modal').style.display = 'flex';
    }
    function closeModal() { $('#modal').style.display = 'none'; }
    window.__closeModal = closeModal;
    function modalTitle(title, body) {
        return '<div class="modal-title">' + esc(title) + '<button class="modal-close" onclick="window.__closeModal()">&times;</button></div>' + body;
    }

    /* ---------- 登录 ---------- */
    function showLogin() {
        $('#adminLayout').style.display = 'none';
        $('#loginView').style.display = 'flex';
        $('#btnLogin').onclick = function () {
            var u = $('#loginUser').value.trim(), p = $('#loginPass').value;
            if (!u || !p) { $('#loginMsg').style.display = 'block'; $('#loginMsg').className = 'msg err'; $('#loginMsg').textContent = '请输入账号和密码'; return; }
            api('login', { username: u, password: p }).then(function (a) {
                me = { id: a.id, username: a.username };
                $('#loginView').style.display = 'none';
                $('#adminLayout').style.display = 'flex';
                $('#adminUserName').textContent = a.username;
                bootAdmin();
            }).catch(function (e) {
                $('#loginMsg').style.display = 'block'; $('#loginMsg').className = 'msg err'; $('#loginMsg').textContent = e.message;
            });
        };
    }

    /* ---------- 移动端侧边栏抽屉 ---------- */
    function closeSidebar() {
        $('#sidebar').classList.remove('open');
        $('#sidebarBackdrop').classList.remove('open');
        $('#adminNavToggle').classList.remove('open');
    }
    function toggleSidebar() {
        var open = $('#sidebar').classList.toggle('open');
        $('#sidebarBackdrop').classList.toggle('open', open);
        $('#adminNavToggle').classList.toggle('open', open);
    }
    function bindSidebar() {
        $('#adminNavToggle').onclick = toggleSidebar;
        $('#sidebarBackdrop').onclick = closeSidebar;
        // 点击导航项后自动收起（移动端）
        document.querySelectorAll('#adminNav a').forEach(function (a) {
            a.addEventListener('click', closeSidebar);
        });
    }

    /* ---------- 修改管理员密码 ---------- */
    function showChgPwd() {
        showModal(modalTitle('修改管理员密码', '')
            + '<label>原密码</label><input type="password" id="aOldPwd" autocomplete="current-password">'
            + '<label>新密码 <span class="hint">(至少6位)</span></label><input type="password" id="aNewPwd" autocomplete="new-password">'
            + '<label>确认新密码</label><input type="password" id="aNewPwd2" autocomplete="new-password">'
            + '<div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">取消</button><button class="btn" id="btnChgPwdOk">确认修改</button></div>');
        $('#btnChgPwdOk').onclick = function () {
            var oldP = $('#aOldPwd').value;
            var newP = $('#aNewPwd').value;
            var newP2 = $('#aNewPwd2').value;
            if (!oldP || !newP) { toast('请填写原密码和新密码', 'err'); return; }
            if (newP.length < 6) { toast('新密码至少6位', 'err'); return; }
            if (newP !== newP2) { toast('两次输入的新密码不一致', 'err'); return; }
            api('admin_changepwd', { old: oldP, new: newP }).then(function () {
                closeModal();
                toast('密码已修改');
                $('#aOldPwd').value = ''; $('#aNewPwd').value = ''; $('#aNewPwd2').value = '';
            }).catch(function (e) { toast(e.message, 'err'); });
        };
    }

    /* ---------- 路由 ---------- */
    var titles = { dashboard: '仪表盘', servers: '服务器', plans: '产品方案', zjmf: '魔方上游', orders: '订单', products: '产品', users: '用户', tickets: '工单', settings: '系统设置' };
    function route() {
        var h = (location.hash || '#/dashboard').slice(2) || 'dashboard';
        var seg = h.split('/').filter(Boolean);
        var page = seg[0] || 'dashboard';
        $('#adminPageTitle').textContent = titles[page] || page;
        document.querySelectorAll('#adminNav a').forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('data-nav') === page);
        });
        if (views[page]) { views[page](); } else { views.dashboard(); }
    }
    function go(h) { location.hash = h; }

    /* ---------- 视图 ---------- */
    var views = {};

    views.dashboard = function () {
        $('#view').innerHTML = loading();
        api('stats').then(function (s) {
            $('#view').innerHTML = '<div class="stat-grid">'
                + '<div class="stat"><div class="num">' + s.users + '</div><div class="lbl">注册用户</div></div>'
                + '<div class="stat"><div class="num">' + s.orders + '</div><div class="lbl">订单总数</div></div>'
                + '<div class="stat"><div class="num">' + s.paidOrders + '</div><div class="lbl">已支付订单</div></div>'
                + '<div class="stat"><div class="num">' + money(s.income) + '</div><div class="lbl">累计收入</div></div>'
                + '<div class="stat"><div class="num">' + s.activeProducts + '</div><div class="lbl">运行中主机</div></div>'
                + '<div class="stat"><div class="num">' + s.openTickets + '</div><div class="lbl">待处理工单</div></div>'
                + '</div><div class="card"><div class="card-title">最近订单</div>'
                + (s.recentOrders.length ? '<div class="table-wrap"><table><thead><tr><th>订单号</th><th>方案</th><th>金额</th><th>状态</th><th>时间</th></tr></thead><tbody>'
                    + s.recentOrders.map(function (o) {
                        return '<tr><td>' + esc(o.order_no) + '</td><td>' + esc(o.plan_name) + '</td><td>' + money(o.amount) + '</td><td>' + badge(o.status, orderBadge) + '</td><td>' + fmt(o.created_at) + '</td></tr>';
                    }).join('') + '</tbody></table></div>' : '<div class="empty">暂无订单</div>') + '</div>';
        }).catch(function (e) { $('#view').innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; });
    };

    views.servers = function () {
        $('#view').innerHTML = '<div class="card-title">服务器<button class="btn sm" id="btnAddServer">+ 添加服务器</button></div>' + loading();
        api('servers_list').then(function (list) {
            $('#view').innerHTML = '<div class="card-title">服务器<button class="btn sm" id="btnAddServer">+ 添加服务器</button></div>'
                + (list.length ? '<div class="table-wrap"><table><thead><tr><th>ID</th><th>名称</th><th>类型</th><th>面板地址</th><th>服务器IP</th><th>端口</th><th>状态</th><th>操作</th></tr></thead><tbody>'
                    + list.map(function (s) {
                        return '<tr><td>' + s.id + '</td><td>' + esc(s.name) + '</td><td>' + (s.type === 'ep' ? '<span class="badge info">EP面板</span>' : '<span class="badge ok">宝塔原生</span>') + '</td><td>' + esc((s.https ? 'https://' : 'http://') + s.host) + '</td><td>' + esc(s.ip) + '</td><td>' + (s.type === 'ep' ? '3312' : s.port) + '</td><td>' + badge(s.status, { 1: ['ok', '启用'], 0: ['gray', '停用'] }) + '</td>'
                            + '<td><a href="javascript:;" data-act="test" data-id="' + s.id + '">测试</a> <a href="javascript:;" data-act="edit" data-id="' + s.id + '">编辑</a> <a href="javascript:;" data-act="del" data-id="' + s.id + '" style="color:var(--danger)">删除</a></td></tr>';
                    }).join('') + '</tbody></table></div>' : '<div class="card empty">暂无服务器</div>');
            $('#btnAddServer').onclick = function () { serverForm(0); };
            $('#view').querySelectorAll('a[data-act]').forEach(function (a) {
                a.onclick = function () {
                    var act = a.getAttribute('data-act'), id = parseInt(a.getAttribute('data-id'), 10);
                    if (act === 'test') {
                        toast('正在测试连接…');
                        api('server_test', { id: id }).then(function (d) { toast(d.msg); }).catch(function (e) { toast(e.message, 'err'); });
                    } else if (act === 'edit') { serverForm(id); }
                    else if (act === 'del') {
                        if (!confirmDlg('确定删除该服务器？')) { return; }
                        api('server_delete', { id: id }).then(function () { toast('已删除'); views.servers(); }).catch(function (e) { toast(e.message, 'err'); });
                    }
                };
            });
        }).catch(function (e) { $('#view').innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; });
    };
    function serverForm(id) {
        var editing = id > 0;
        if (editing) {
            api('servers_list').then(function (list) {
                var s = list.filter(function (x) { return x.id === id; })[0];
                if (!s) { toast('服务器不存在', 'err'); return; }
                showModal(modalTitle('编辑服务器', '') + serverFormHtml(s));
                bindServerForm(true);
            }).catch(function (e) { toast(e.message, 'err'); });
        } else {
            showModal(modalTitle('添加服务器', '') + serverFormHtml(null));
            bindServerForm(false);
        }
    }
    function serverTInfo(type) {
        var T = {
            btn: { hostHint: '(宝塔面板域名或IP，不带协议)', portHint: '(宝塔面板端口，如8888)', userLbl: '面板登录账号 <span class="hint">(面板设置里的登录用户名)</span>', secretHint: '(面板设置→安全→API接口 开启后生成的密钥)', moduleLbl: 'PHP版本', moduleShow: true, portDef: '8888' },
            ep: { hostHint: '(EP面板IP或域名，API固定端口3312)', portHint: '(EP固定3312)', userLbl: '面板账号 <span class="hint">(EP可不填)</span>', secretHint: '(EP面板：服务器设置里的通信安全码)', moduleLbl: 'EP语言模板', moduleShow: true, portDef: '3312' }
        };
        return T[type] || T.btn;
    }
    function serverFormHtml(s) {
        s = s || {};
        var type = s.type || 'btn';
        var ti = serverTInfo(type);
        var moduleVal = s.ep_module || (type === 'ep' ? 'php' : '74');
        return '<label>名称</label><input type="text" id="s_name" value="' + esc(s.name || '') + '" placeholder="例如 香港节点">'
            + '<label>面板类型</label><select id="s_type">'
            + '<option value="btn"' + (type === 'btn' ? ' selected' : '') + '>宝塔(原生API)</option>'
            + '<option value="ep"' + (type === 'ep' ? ' selected' : '') + '>EP面板 (Easypanel)</option></select>'
            + '<label>面板地址 <span class="hint" id="s_host_hint">' + ti.hostHint + '</span></label><input type="text" id="s_host" value="' + esc(s.host || '') + '">'
            + '<label>端口 <span class="hint" id="s_port_hint">' + ti.portHint + '</span></label><input type="number" id="s_port" value="' + (s.port || ti.portDef) + '"' + (type === 'ep' ? ' disabled' : '') + '>'
            + '<label id="s_user_lbl">' + ti.userLbl + '</label><input type="text" id="s_username" value="' + esc(s.username || '') + '">'
            + '<label>API密钥 <span class="hint" id="s_secret_hint">' + ti.secretHint + '</span></label><input type="text" id="s_secret" value="' + esc(s.secret || '') + '">'
            + '<label id="s_module_lbl"' + (ti.moduleShow ? '' : ' style="display:none"') + '>' + ti.moduleLbl + '</label><input type="text" id="s_ep_module"' + (ti.moduleShow ? '' : ' style="display:none"') + ' value="' + esc(moduleVal) + '" placeholder="' + (type === 'ep' ? 'php / iis' : '74') + '">'
            + '<label>服务器IP <span class="hint">(面板 API 白名单校验用)</span></label><input type="text" id="s_ip" value="' + esc(s.ip || '') + '">'
            + '<div class="radio-group" style="margin-top:10px"><label class="' + (s.https ? 'on' : '') + '" id="lbl_https"><input type="checkbox" id="s_https" ' + (s.https ? 'checked' : '') + '>HTTPS</label>'
            + '<label class="' + (s.status !== 0 ? 'on' : '') + '" id="lbl_status"><input type="checkbox" id="s_status" ' + (s.status !== 0 ? 'checked' : '') + '>启用</label></div>'
            + '<label>备注</label><input type="text" id="s_note" value="' + esc(s.note || '') + '">'
            + '<div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">取消</button><button class="btn" id="btnSaveServer">保存</button></div>';
    }
    function bindServerForm(editing) {
        $('#btnSaveServer').onclick = function () {
            api('server_save', {
                id: editing, name: $('#s_name').value.trim(), host: $('#s_host').value.trim(),
                username: $('#s_username').value.trim(), secret: $('#s_secret').value.trim(),
                ip: $('#s_ip').value.trim(), https: $('#s_https').checked ? 1 : 0,
                status: $('#s_status').checked ? 1 : 0, note: $('#s_note').value.trim(),
                type: $('#s_type').value, ep_module: $('#s_ep_module').value.trim() || 'php',
                port: $('#s_port').value
            }).then(function () { closeModal(); toast('已保存'); views.servers(); }).catch(function (e) { toast(e.message, 'err'); });
        };
        $('#s_type').onchange = function () {
            var ti = serverTInfo(this.value);
            $('#s_host_hint').innerHTML = ti.hostHint;
            $('#s_port_hint').innerHTML = ti.portHint;
            $('#s_user_lbl').innerHTML = ti.userLbl;
            $('#s_secret_hint').innerHTML = ti.secretHint;
            $('#s_module_lbl').innerHTML = ti.moduleLbl;
            $('#s_module_lbl').style.display = ti.moduleShow ? '' : 'none';
            $('#s_ep_module').style.display = ti.moduleShow ? '' : 'none';
            $('#s_port').disabled = (this.value === 'ep');
            $('#s_port').value = ti.portDef;
        };
        $('#s_https').onchange = function () { $('#lbl_https').classList.toggle('on', this.checked); };
        $('#s_status').onchange = function () { $('#lbl_status').classList.toggle('on', this.checked); };
    }

    views.plans = function () {
        $('#view').innerHTML = '<div class="card-title">产品方案<button class="btn sm" id="btnAddPlan">+ 添加方案</button></div>' + loading();
        api('plans_list').then(function (list) {
            $('#view').innerHTML = '<div class="card-title">产品方案<button class="btn sm" id="btnAddPlan">+ 添加方案</button></div>'
                + (list.length ? '<div class="table-wrap"><table><thead><tr><th>ID</th><th>名称</th><th>服务器</th><th>月付价</th><th>空间</th><th>数据库</th><th>流量</th><th>排序</th><th>状态</th><th>操作</th></tr></thead><tbody>'
                    + list.map(function (p) {
                        return '<tr><td>' + p.id + '</td><td>' + esc(p.name) + '</td><td>' + esc(p.server_name || '-') + '</td><td>' + money(p.price) + '</td><td>' + p.a1 + ' MB</td><td>' + p.a2 + ' MB</td><td>' + p.a5 + ' GB</td><td>' + p.sort + '</td><td>' + badge(p.status, { 1: ['ok', '上架'], 0: ['gray', '下架'] }) + '</td>'
                            + '<td><a href="javascript:;" data-act="edit" data-id="' + p.id + '">编辑</a> <a href="javascript:;" data-act="del" data-id="' + p.id + '" style="color:var(--danger)">删除</a></td></tr>';
                    }).join('') + '</tbody></table></div>' : '<div class="card empty">暂无方案</div>');
            $('#btnAddPlan').onclick = function () { planForm(0); };
            $('#view').querySelectorAll('a[data-act]').forEach(function (a) {
                a.onclick = function () {
                    var act = a.getAttribute('data-act'), id = parseInt(a.getAttribute('data-id'), 10);
                    if (act === 'edit') { planForm(id); }
                    else if (act === 'del') {
                        if (!confirmDlg('确定删除该方案？')) { return; }
                        api('plan_delete', { id: id }).then(function () { toast('已删除'); views.plans(); }).catch(function (e) { toast(e.message, 'err'); });
                    }
                };
            });
        }).catch(function (e) { $('#view').innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; });
    };
    function planForm(id) {
        var editing = id > 0;
        Promise.all([api('servers_list'), api('zjmf_list'), editing ? api('plans_list') : Promise.resolve([])]).then(function (res) {
            var servers = res[0], apis = res[1];
            var p = editing ? res[2].filter(function (x) { return x.id === id; })[0] : null;
            var isUp = p && p.zjmf_api_id > 0;
            var sOpts = servers.map(function (s) { return '<option value="' + s.id + '"' + (p && !isUp && p.server_id == s.id ? ' selected' : '') + '>' + esc(s.name) + '</option>'; }).join('');
            var apiOpts = apis.map(function (a) { return '<option value="' + a.id + '"' + (p && isUp && p.zjmf_api_id == a.id ? ' selected' : '') + '>' + esc(a.name) + (a.status == 0 ? ' (停用)' : '') + '</option>'; }).join('');
            var html = modalTitle(editing ? '编辑方案' : '添加方案', '')
                + '<label>方案名称</label><input type="text" id="p_name" value="' + esc(p ? p.name : '') + '">'
                + '<label>开通方式</label><select id="p_prov">'
                + '<option value="local"' + (!isUp ? ' selected' : '') + '>本地面板（宝塔/EP）</option>'
                + '<option value="up"' + (isUp ? ' selected' : '') + '>魔方财务上游（API下单）</option></select>'
                + '<div id="p_local_block"' + (isUp ? ' style="display:none"' : '') + '>'
                + '<label>所属服务器</label><select id="p_server">' + (sOpts || '<option value="">请先添加服务器</option>') + '</select>'
                + '</div>'
                + '<div id="p_up_block"' + (isUp ? '' : ' style="display:none"') + '>'
                + '<label>上游接口</label><select id="p_api">' + (apiOpts || '<option value="">请先添加上游接口</option>') + '</select>'
                + '<label>上游商品 <span class="hint" id="p_up_hint">' + (p && isUp && p.upstream_pid > 0 ? '当前绑定：' + esc(p.upstream_name || ('商品#' + p.upstream_pid)) : '选择上游接口后自动加载') + '</span></label>'
                + '<select id="p_up_pid"><option value="0">-- 选择上游接口后加载 --</option></select>'
                + '<input type="hidden" id="p_up_name" value="' + esc(p && isUp ? (p.upstream_name || '') : '') + '">'
                + '</div>'
                + '<label>月付价格(元)</label><input type="number" id="p_price" step="0.01" min="0" value="' + (p ? p.price : '') + '">'
                + '<div class="grid" style="grid-template-columns:1fr 1fr;gap:0 12px">'
                + '<div><label>Web空间(MB)</label><input type="number" id="p_a1" value="' + (p ? p.a1 : '') + '"></div>'
                + '<div><label>SQL空间(MB)</label><input type="number" id="p_a2" value="' + (p ? p.a2 : '') + '"></div>'
                + '<div><label>绑定域名数 <span class="hint">(-1不限)</span></label><input type="number" id="p_a3" value="' + (p ? p.a3 : -1) + '"></div>'
                + '<div><label>子目录数 <span class="hint">(0不限)</span></label><input type="number" id="p_a4" value="' + (p ? p.a4 : 0) + '"></div>'
                + '<div><label>流量(GB/月)</label><input type="number" id="p_a5" value="' + (p ? p.a5 : 0) + '"></div>'
                + '<div><label>产品类型</label><select id="p_a6"><option value="0" ' + (!p || p.a6 == 0 ? 'selected' : '') + '>虚拟主机</option><option value="1" ' + (p && p.a6 == 1 ? 'selected' : '') + '>CDN</option></select></div>'
                + '<div><label>端口 <span class="hint">(ssl加s)</span></label><input type="text" id="p_a7" value="' + esc(p ? p.a7 : '80,443s') + '"></div>'
                + '<div><label>Web备份数</label><input type="number" id="p_a8" value="' + (p ? p.a8 : 0) + '"></div>'
                + '<div><label>SQL备份数</label><input type="number" id="p_a9" value="' + (p ? p.a9 : 0) + '"></div>'
                + '<div><label>允许子目录</label><select id="p_a10"><option value="1" ' + (!p || p.a10 == 1 ? 'selected' : '') + '>允许</option><option value="0" ' + (p && p.a10 == 0 ? 'selected' : '') + '>禁止</option></select></div>'
                + '</div>'
                + '<label>备注 <span class="hint">(前台展示)</span></label><input type="text" id="p_note" value="' + esc(p ? p.note : '') + '">'
                + '<div class="radio-group" style="margin-top:10px"><label class="' + (!p || p.status == 1 ? 'on' : '') + '" id="lbl_status"><input type="checkbox" id="p_status" ' + (!p || p.status == 1 ? 'checked' : '') + '>上架</label></div>'
                + '<div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">取消</button><button class="btn" id="btnSavePlan">保存</button></div>';
            showModal(html);
            $('#p_status').onchange = function () { $('#lbl_status').classList.toggle('on', this.checked); };
            function toggleProv() {
                var up = $('#p_prov').value === 'up';
                $('#p_local_block').style.display = up ? 'none' : '';
                $('#p_up_block').style.display = up ? '' : 'none';
            }
            $('#p_prov').onchange = toggleProv;
            // 加载上游商品下拉（选中指定商品可选）
            function loadUpProducts(selectedPid) {
                var aid = parseInt($('#p_api').value, 10);
                var sel = $('#p_up_pid');
                var hint = $('#p_up_hint');
                if (!aid) { sel.innerHTML = '<option value="0">-- 请先选择上游接口 --</option>'; return; }
                sel.innerHTML = '<option value="0">加载中…</option>';
                api('zjmf_products', { id: aid }).then(function (d) {
                    var opts = '<option value="0">-- 请选择上游商品 --</option>';
                    var found = false;
                    (d.list || []).forEach(function (g) {
                        var selFlag = selectedPid && g.id === selectedPid ? ' selected' : '';
                        if (selectedPid && g.id === selectedPid) { found = true; }
                        opts += '<option value="' + g.id + '" data-name="' + esc(g.name) + '"' + selFlag + '>' + esc(g.name) + '（' + (g.price !== '' ? esc(g.price) + ' ' + esc(g.cycle) : esc(g.cycle || g.type)) + '）</option>';
                    });
                    sel.innerHTML = opts;
                    hint.textContent = '共 ' + d.count + ' 个上游商品' + (selectedPid && !found ? '（当前绑定商品#' + selectedPid + ' 不在上游在售列表）' : '');
                    var opt = sel.options[sel.selectedIndex];
                    if (opt && opt.getAttribute('data-name')) { $('#p_up_name').value = opt.getAttribute('data-name'); }
                }).catch(function (e) {
                    sel.innerHTML = '<option value="0">加载失败</option>';
                    toast(e.message, 'err');
                });
            }
            $('#p_api').onchange = function () { loadUpProducts(0); };
            // 编辑已有上游方案：接口预选不会触发 onchange，需自动加载并选中当前绑定商品
            if (isUp && p.zjmf_api_id > 0) { loadUpProducts(parseInt(p.upstream_pid, 10)); }
            $('#p_up_pid').onchange = function () {
                var opt = this.options[this.selectedIndex];
                $('#p_up_name').value = opt ? opt.getAttribute('data-name') || '' : '';
            };
            $('#btnSavePlan').onclick = function () {
                var up = $('#p_prov').value === 'up';
                var apiId = up ? parseInt($('#p_api').value, 10) : 0;
                var upPid = up ? parseInt($('#p_up_pid').value, 10) : 0;
                if (up && (!apiId || !upPid)) { toast('请选择上游接口和上游商品', 'err'); return; }
                api('plan_save', {
                    id: editing ? id : 0, server_id: up ? 0 : $('#p_server').value, name: $('#p_name').value.trim(),
                    price: $('#p_price').value, a1: $('#p_a1').value, a2: $('#p_a2').value, a3: $('#p_a3').value,
                    a4: $('#p_a4').value, a5: $('#p_a5').value, a6: $('#p_a6').value, a7: $('#p_a7').value.trim(),
                    a8: $('#p_a8').value, a9: $('#p_a9').value, a10: $('#p_a10').value,
                    note: $('#p_note').value.trim(), status: $('#p_status').checked ? 1 : 0,
                    zjmf_api_id: apiId, upstream_pid: upPid, upstream_name: $('#p_up_name').value
                }).then(function () { closeModal(); toast('已保存'); views.plans(); }).catch(function (e) { toast(e.message, 'err'); });
            };
        });
    }

    /* ---------- 魔方财务上游 ---------- */
    views.zjmf = function () {
        $('#view').innerHTML = '<div class="card-title">魔方财务上游<button class="btn sm" id="btnAddZjmf">+ 添加上游接口</button></div>' + loading();
        api('zjmf_list').then(function (list) {
            $('#view').innerHTML = '<div class="card-title">魔方财务上游<button class="btn sm" id="btnAddZjmf">+ 添加上游接口</button></div>'
                + '<div class="hint" style="margin-bottom:12px">对接上游魔方财务系统：拉取上游商品导入为方案 → 用户购买支付后自动在上游下单开通（扣上游余额）。上游需开启「API总开关」，您需在上游用户中心申请API密钥并配置IP白名单。</div>'
                + (list.length ? '<div class="table-wrap"><table><thead><tr><th>ID</th><th>名称</th><th>接口地址</th><th>用户名</th><th>状态</th><th>备注</th><th>操作</th></tr></thead><tbody>'
                    + list.map(function (a) {
                        return '<tr><td>' + a.id + '</td><td>' + esc(a.name) + '</td><td>' + esc(a.hostname) + '</td><td>' + esc(a.username) + '</td><td>' + badge(a.status, { 1: ['ok', '启用'], 0: ['gray', '停用'] }) + '</td><td>' + esc(a.note) + '</td>'
                            + '<td><a href="javascript:;" data-act="test" data-id="' + a.id + '">测试</a> <a href="javascript:;" data-act="prod" data-id="' + a.id + '">拉取商品</a> <a href="javascript:;" data-act="edit" data-id="' + a.id + '">编辑</a> <a href="javascript:;" data-act="del" data-id="' + a.id + '" style="color:var(--danger)">删除</a></td></tr>';
                    }).join('') + '</tbody></table></div>' : '<div class="card empty">暂无上游接口</div>');
            $('#btnAddZjmf').onclick = function () { zjmfForm(0); };
            $('#view').querySelectorAll('a[data-act]').forEach(function (a) {
                a.onclick = function () {
                    var act = a.getAttribute('data-act'), id = parseInt(a.getAttribute('data-id'), 10);
                    if (act === 'test') {
                        toast('正在测试连接…');
                        api('zjmf_test', { id: id }).then(function (d) { toast(d.msg); }).catch(function (e) { toast(e.message, 'err'); });
                    } else if (act === 'prod') { zjmfProducts(id); }
                    else if (act === 'edit') { zjmfForm(id); }
                    else if (act === 'del') {
                        if (!confirmDlg('确定删除该上游接口？')) { return; }
                        api('zjmf_delete', { id: id }).then(function () { toast('已删除'); views.zjmf(); }).catch(function (e) { toast(e.message, 'err'); });
                    }
                };
            });
        }).catch(function (e) { $('#view').innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; });
    };
    function zjmfForm(id) {
        var editing = id > 0;
        var fill = function (a) {
            var html = modalTitle(editing ? '编辑上游接口' : '添加上游接口', '')
                + '<label>名称</label><input type="text" id="z_name" value="' + esc(a ? a.name : '') + '" placeholder="例如 沧舟云上游">'
                + '<label>接口地址 <span class="hint">(上游魔方财务域名，如 https://idc.mcedm.top)</span></label><input type="text" id="z_hostname" value="' + esc(a ? a.hostname : '') + '" placeholder="https://">'
                + '<label>用户名 <span class="hint">(您在上游注册的账号，手机/邮箱)</span></label><input type="text" id="z_username" value="' + esc(a ? a.username : '') + '">'
                + '<label>API密钥 <span class="hint">' + (editing ? '(不修改请留空)' : '(上游用户中心→API管理 获取)') + '</span></label><input type="password" id="z_password" autocomplete="new-password">'
                + '<label>备注</label><input type="text" id="z_note" value="' + esc(a ? a.note : '') + '">'
                + '<div class="radio-group" style="margin-top:10px"><label class="' + (!a || a.status == 1 ? 'on' : '') + '" id="lbl_status"><input type="checkbox" id="z_status" ' + (!a || a.status == 1 ? 'checked' : '') + '>启用</label></div>'
                + '<div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">取消</button><button class="btn" id="btnSaveZjmf">保存</button></div>';
            showModal(html);
            $('#z_status').onchange = function () { $('#lbl_status').classList.toggle('on', this.checked); };
            $('#btnSaveZjmf').onclick = function () {
                api('zjmf_save', {
                    id: editing ? id : 0, name: $('#z_name').value.trim(), hostname: $('#z_hostname').value.trim(),
                    username: $('#z_username').value.trim(), password: $('#z_password').value,
                    note: $('#z_note').value.trim(), status: $('#z_status').checked ? 1 : 0
                }).then(function () { closeModal(); toast('已保存'); views.zjmf(); }).catch(function (e) { toast(e.message, 'err'); });
            };
        };
        if (editing) {
            api('zjmf_list').then(function (list) {
                var a = list.filter(function (x) { return x.id === id; })[0];
                if (!a) { toast('接口不存在', 'err'); return; }
                fill(a);
            }).catch(function (e) { toast(e.message, 'err'); });
        } else { fill(null); }
    }
    function zjmfProducts(apiId) {
        showModal(modalTitle('拉取上游商品', '<div class="loading"><span class="spin"></span>加载中…</div>'));
        api('zjmf_products', { id: apiId }).then(function (d) {
            var html = modalTitle('拉取上游商品 <span class="hint">(共 ' + d.count + ' 个)</span>', '')
                + (d.list.length ? '<div class="table-wrap"><table><thead><tr><th>ID</th><th>名称</th><th>类型</th><th>价格</th><th>周期</th><th>操作</th></tr></thead><tbody>'
                    + d.list.map(function (g) {
                        return '<tr><td>' + g.id + '</td><td class="wrap">' + esc(g.name) + '</td><td>' + esc(g.type) + '</td><td>' + esc(g.price) + '</td><td>' + esc(g.cycle) + '</td>'
                            + '<td><a href="javascript:;" data-act="imp" data-id="' + g.id + '" data-name="' + esc(g.name) + '" data-price="' + esc(g.price) + '">导入为方案</a></td></tr>';
                    }).join('') + '</tbody></table></div>' : '<div class="empty">上游没有可售商品</div>')
                + '<div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">关闭</button></div>';
            $('#modalBox').innerHTML = html;
            $('#modalBox').querySelectorAll('a[data-act]').forEach(function (a) {
                a.onclick = function () {
                    zjmfImport(apiId, parseInt(a.getAttribute('data-id'), 10), a.getAttribute('data-name'), a.getAttribute('data-price'));
                };
            });
        }).catch(function (e) {
            $('#modalBox').innerHTML = modalTitle('拉取上游商品', '<div class="empty">' + esc(e.message) + '</div><div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">关闭</button></div>');
        });
    }
    function zjmfImport(apiId, pid, name, price) {
        showModal(modalTitle('导入为方案', '')
            + '<label>方案名称</label><input type="text" id="i_name" value="' + esc(name) + '">'
            + '<label>月付售价(元) <span class="hint">(上游扣款按上游定价，此价为您的售价)</span></label><input type="number" id="i_price" step="0.01" min="0" value="' + esc(price || '') + '">'
            + '<label>备注 <span class="hint">(前台展示)</span></label><input type="text" id="i_note" value="上游商品#' + pid + '">'
            + '<div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">取消</button><button class="btn" id="btnImport">导入</button></div>');
        $('#btnImport').onclick = function () {
            api('zjmf_import', { api_id: apiId, pid: pid, name: $('#i_name').value.trim(), price: $('#i_price').value, note: $('#i_note').value.trim(), status: 1 })
                .then(function () { closeModal(); toast('已导入，可在「产品方案」中查看'); }).catch(function (e) { toast(e.message, 'err'); });
        };
    }

    views.orders = function () {
        $('#view').innerHTML = '<div class="card-title">订单</div><div class="card" style="padding:12px 16px;display:flex;gap:8px;flex-wrap:wrap">'
            + ['', 'pending', 'paid', 'cancelled'].map(function (s) {
                var label = { '': '全部', pending: '待支付', paid: '已支付', cancelled: '已取消' }[s];
                return '<button class="btn sm ' + (state.ordersFilter === s ? '' : 'ghost') + '" data-f="' + s + '">' + label + '</button>';
            }).join('')
            + '<input type="text" id="orderKw" placeholder="搜索订单号/方案" style="width:200px;margin-left:auto">'
            + '<button class="btn sm ghost" id="btnOrderSearch">搜索</button></div>' + loading();
        bindOrderFilters();
        loadOrders();
    };
    function bindOrderFilters() {
        $('#view').querySelectorAll('button[data-f]').forEach(function (b) {
            b.onclick = function () {
                state.ordersFilter = b.getAttribute('data-f');
                views.orders();
            };
        });
        $('#btnOrderSearch').onclick = loadOrders;
        $('#orderKw').addEventListener('keydown', function (e) { if (e.key === 'Enter') { loadOrders(); } });
    }
    function loadOrders() {
        api('orders_list', { status: state.ordersFilter, kw: $('#orderKw') ? $('#orderKw').value.trim() : '' }).then(function (list) {
            var box = $('#view').querySelector('.card-title').parentElement;
            var target = box.querySelector('.table-wrap, .empty, .loading');
            if (!list.length) {
                if (target) { target.outerHTML = '<div class="card empty">暂无订单</div>'; }
                else { box.insertAdjacentHTML('beforeend', '<div class="card empty">暂无订单</div>'); }
                return;
            }
            var html = '<div class="table-wrap"><table><thead><tr><th>订单号</th><th>用户</th><th>方案</th><th>周期</th><th>金额</th><th>支付</th><th>状态</th><th>时间</th><th>操作</th></tr></thead><tbody>';
            list.forEach(function (o) {
                html += '<tr><td>' + esc(o.order_no) + '</td><td>' + esc(o.email || o.user_id) + '</td><td>' + esc(o.plan_name) + '</td><td>' + esc(o.cycle_name) + '</td><td>' + money(o.amount) + '</td><td>' + esc(o.pay_type || '-') + '</td><td>' + badge(o.status, orderBadge) + '</td><td>' + fmt(o.created_at) + '</td>'
                    + '<td><a href="javascript:;" data-act="view" data-id="' + o.id + '">详情</a>'
                    + (o.status === 'pending' ? ' <a href="javascript:;" data-act="pay" data-id="' + o.id + '">标记已付</a> <a href="javascript:;" data-act="cancel" data-id="' + o.id + '" style="color:var(--danger)">取消</a>' : '')
                    + (o.status === 'paid' && (!o.product || o.product.status == 0) ? ' <a href="javascript:;" data-act="prov" data-id="' + o.id + '">开通</a>' : '')
                    + '</td></tr>';
            });
            html += '</tbody></table></div>';
            if (target) { target.outerHTML = html; }
            else { box.insertAdjacentHTML('beforeend', html); }
            box.querySelectorAll('a[data-act]').forEach(function (a) {
                a.onclick = function () {
                    var act = a.getAttribute('data-act'), id = parseInt(a.getAttribute('data-id'), 10);
                    if (act === 'view') { orderDetail(id); }
                    else if (act === 'pay') {
                        if (!confirmDlg('确认手动标记该订单为已支付并开通主机？')) { return; }
                        api('order_pay', { id: id }).then(function (d) { toast(d.msg); loadOrders(); }).catch(function (e) { toast(e.message, 'err'); });
                    }
                    else if (act === 'cancel') {
                        if (!confirmDlg('确认取消该订单？')) { return; }
                        api('order_cancel', { id: id }).then(function () { toast('已取消'); loadOrders(); }).catch(function (e) { toast(e.message, 'err'); });
                    }
                    else if (act === 'prov') {
                        api('order_provision', { id: id }).then(function (d) { toast(d.msg); loadOrders(); }).catch(function (e) { toast(e.message, 'err'); });
                    }
                };
            });
        }).catch(function (e) { toast(e.message, 'err'); });
    }
    function orderDetail(id) {
        api('order_get', { id: id }).then(function (o) {
            var html = modalTitle('订单 ' + o.order_no, '')
                + '<div class="kv"><span class="k">用户</span><span class="v">' + esc(o.email) + '</span></div>'
                + '<div class="kv"><span class="k">方案</span><span class="v">' + esc(o.plan_name) + '</span></div>'
                + '<div class="kv"><span class="k">周期</span><span class="v">' + esc(o.cycle_name) + '</span></div>'
                + '<div class="kv"><span class="k">金额</span><span class="v">' + money(o.amount) + '</span></div>'
                + '<div class="kv"><span class="k">支付方式</span><span class="v">' + esc(o.pay_type || '-') + '</span></div>'
                + '<div class="kv"><span class="k">绑定域名</span><span class="v">' + esc(o.domain || '-') + '</span></div>'
                + '<div class="kv"><span class="k">状态</span><span class="v">' + badge(o.status, orderBadge) + '</span></div>'
                + '<div class="kv"><span class="k">平台流水号</span><span class="v">' + esc(o.trade_no || '-') + '</span></div>'
                + '<div class="kv"><span class="k">支付时间</span><span class="v">' + fmt(o.paid_at) + '</span></div>'
                + '<div class="kv"><span class="k">下单时间</span><span class="v">' + fmt(o.created_at) + '</span></div>'
                + (o.product ? '<div class="kv"><span class="k">主机</span><span class="v">' + esc(o.product.username) + ' ' + badge(o.product.status, prodBadge) + '</span></div>' : '')
                + '<div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">关闭</button></div>';
            showModal(html);
        }).catch(function (e) { toast(e.message, 'err'); });
    }

    views.products = function () {
        $('#view').innerHTML = '<div class="card-title">产品</div><div class="card" style="padding:12px 16px;display:flex;gap:8px">'
            + '<input type="text" id="prodKw" placeholder="搜索主机账号/域名/邮箱" style="width:240px">'
            + '<button class="btn sm ghost" id="btnProdSearch">搜索</button></div>' + loading();
        loadProducts();
        $('#btnProdSearch').onclick = loadProducts;
        $('#prodKw').addEventListener('keydown', function (e) { if (e.key === 'Enter') { loadProducts(); } });
    };
    function loadProducts() {
        api('products_list', { kw: $('#prodKw') ? $('#prodKw').value.trim() : '' }).then(function (list) {
            var box = $('#view');
            var target = box.querySelector('.table-wrap, .empty, .loading');
            if (!list.length) {
                if (target) { target.outerHTML = '<div class="card empty">暂无产品</div>'; }
                return;
            }
            var html = '<div class="table-wrap"><table><thead><tr><th>ID</th><th>用户</th><th>方案</th><th>主机账号</th><th>域名</th><th>服务器</th><th>状态</th><th>到期</th><th>操作</th></tr></thead><tbody>';
            list.forEach(function (p) {
                var isUp = p.upstream_hostid > 0;
                html += '<tr><td>' + p.id + '</td><td>' + esc(p.email || p.user_id) + '</td><td>' + esc(p.plan_name) + '</td><td>' + esc(p.username) + '</td><td>' + esc(p.domain || '-') + '</td><td>' + esc(p.server_name || (isUp ? '魔方上游' : '-')) + '</td><td>' + badge(p.status, prodBadge) + '</td><td>' + fmt(p.expires_at) + '</td>'
                    + '<td><a href="javascript:;" data-act="pwd" data-id="' + p.id + '">改密</a>'
                    + (isUp ? ' <a href="javascript:;" data-act="sync" data-id="' + p.id + '">同步</a> <a href="javascript:;" data-act="renew" data-id="' + p.id + '">续费</a>' : '')
                    + (p.status == 1 ? ' <a href="javascript:;" data-act="sus" data-id="' + p.id + '">暂停</a>' : '')
                    + (p.status == 2 ? ' <a href="javascript:;" data-act="unsus" data-id="' + p.id + '">恢复</a>' : '')
                    + (p.status != 3 ? ' <a href="javascript:;" data-act="del" data-id="' + p.id + '" style="color:var(--danger)">删除</a>' : '')
                    + '</td></tr>';
            });
            html += '</tbody></table></div>';
            if (target) { target.outerHTML = html; }
            box.querySelectorAll('a[data-act]').forEach(function (a) {
                a.onclick = function () {
                    var act = a.getAttribute('data-act'), id = parseInt(a.getAttribute('data-id'), 10);
                    if (act === 'pwd') {
                        showModal(modalTitle('修改主机密码', '<label>新密码 <span class="hint">(6-32位)</span></label><input type="text" id="apwd"><div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">取消</button><button class="btn" id="btnPwdOk">确认</button></div>'));
                        $('#btnPwdOk').onclick = function () {
                            var v = $('#apwd').value.trim();
                            if (v.length < 6) { toast('密码至少6位', 'err'); return; }
                            api('product_action', { id: id, act: 'repwd', pwd: v }).then(function () { closeModal(); toast('已修改'); loadProducts(); }).catch(function (e) { toast(e.message, 'err'); });
                        };
                    } else if (act === 'sus' || act === 'unsus') {
                        api('product_action', { id: id, act: act === 'sus' ? 'suspend' : 'unsuspend' }).then(function () { toast('操作成功'); loadProducts(); }).catch(function (e) { toast(e.message, 'err'); });
                    } else if (act === 'renew') {
                        if (!confirmDlg('确认续费该产品？上游将按当前周期扣款。')) { return; }
                        api('product_action', { id: id, act: 'renew' }).then(function (d) { toast(d.msg); loadProducts(); }).catch(function (e) { toast(e.message, 'err'); });
                    } else if (act === 'sync') {
                        toast('正在同步上游主机信息…');
                        api('product_action', { id: id, act: 'sync' }).then(function (d) { toast(d.msg); loadProducts(); }).catch(function (e) { toast(e.message, 'err'); });
                    } else if (act === 'del') {
                        if (!confirmDlg('确定删除该主机？服务器上的站点也会被删除！')) { return; }
                        api('product_action', { id: id, act: 'terminate' }).then(function () { toast('已删除'); loadProducts(); }).catch(function (e) { toast(e.message, 'err'); });
                    }
                };
            });
        }).catch(function (e) { toast(e.message, 'err'); });
    }

    views.users = function () {
        $('#view').innerHTML = '<div class="card-title">用户</div><div class="card" style="padding:12px 16px;display:flex;gap:8px">'
            + '<input type="text" id="userKw" placeholder="搜索邮箱" style="width:240px"><button class="btn sm ghost" id="btnUserSearch">搜索</button></div>' + loading();
        loadUsers();
        $('#btnUserSearch').onclick = loadUsers;
        $('#userKw').addEventListener('keydown', function (e) { if (e.key === 'Enter') { loadUsers(); } });
    };
    function loadUsers() {
        api('users_list', { kw: $('#userKw') ? $('#userKw').value.trim() : '' }).then(function (list) {
            var box = $('#view');
            var target = box.querySelector('.table-wrap, .empty, .loading');
            if (!list.length) {
                if (target) { target.outerHTML = '<div class="card empty">暂无用户</div>'; }
                return;
            }
            var html = '<div class="table-wrap"><table><thead><tr><th>ID</th><th>邮箱</th><th>状态</th><th>注册时间</th><th>操作</th></tr></thead><tbody>';
            list.forEach(function (u) {
                html += '<tr><td>' + u.id + '</td><td>' + esc(u.email) + '</td><td>' + badge(u.status, { 1: ['ok', '正常'], 0: ['err', '禁用'] }) + '</td><td>' + fmt(u.created_at) + '</td>'
                    + '<td><a href="javascript:;" data-act="login" data-id="' + u.id + '">以客户登陆</a> | <a href="javascript:;" data-act="pwd" data-id="' + u.id + '" data-email="' + esc(u.email) + '">改密</a> | <a href="javascript:;" data-id="' + u.id + '" data-s="' + u.status + '" style="' + (u.status == 1 ? 'color:var(--danger)' : '') + '">' + (u.status == 1 ? '禁用' : '启用') + '</a></td></tr>';
            });
            html += '</tbody></table></div>';
            if (target) { target.outerHTML = html; }
            box.querySelectorAll('a[data-act]').forEach(function (a) {
                a.onclick = function () {
                    var act = a.getAttribute('data-act');
                    var id = parseInt(a.getAttribute('data-id'), 10);
                    if (act === 'login') {
                        api('user_login_as', { id: id }).then(function (d) {
                            window.open(d.url, '_blank');
                            toast('已以 ' + d.email + ' 的身份打开前台（新标签页）');
                        }).catch(function (e) { toast(e.message, 'err'); });
                    } else if (act === 'pwd') {
                        showModal(modalTitle('强制修改用户密码', '')
                            + '<div class="hint" style="margin-bottom:6px">为用户 <b>' + esc(a.getAttribute('data-email') || ('#' + id)) + '</b> 设置新密码，保存后该用户需用新密码登录</div>'
                            + '<label>新密码 <span class="hint">(6-32位)</span></label><input type="text" id="upwd" autocomplete="new-password" placeholder="请输入新密码">'
                            + '<div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">取消</button><button class="btn" id="btnUpwdOk">确认修改</button></div>');
                        $('#btnUpwdOk').onclick = function () {
                            var v = $('#upwd').value.trim();
                            if (v.length < 6 || v.length > 32) { toast('密码长度需在6-32位', 'err'); return; }
                            api('user_changepwd', { id: id, pwd: v }).then(function (d) {
                                closeModal();
                                toast('已为用户 ' + d.email + ' 重置密码');
                            }).catch(function (e) { toast(e.message, 'err'); });
                        };
                    }
                };
            });
            box.querySelectorAll('a[data-id]').forEach(function (a) {
                a.onclick = function () {
                    var id = parseInt(a.getAttribute('data-id'), 10);
                    var s = a.getAttribute('data-s') == '1' ? '禁用' : '启用';
                    if (!confirmDlg('确定' + s + '该用户？')) { return; }
                    api('user_toggle', { id: id }).then(function () { toast('已' + s); loadUsers(); }).catch(function (e) { toast(e.message, 'err'); });
                };
            });
        }).catch(function (e) { toast(e.message, 'err'); });
    }

    views.tickets = function () {
        $('#view').innerHTML = '<div class="card-title">工单</div><div class="card" style="padding:12px 16px;display:flex;gap:8px;flex-wrap:wrap">'
            + ['', 'open', 'replied', 'closed'].map(function (s) {
                var label = { '': '全部', open: '待处理', replied: '已回复', closed: '已关闭' }[s];
                return '<button class="btn sm ' + (state.ticketsFilter === s ? '' : 'ghost') + '" data-f="' + s + '">' + label + '</button>';
            }).join('') + '</div>' + loading();
        $('#view').querySelectorAll('button[data-f]').forEach(function (b) {
            b.onclick = function () { state.ticketsFilter = b.getAttribute('data-f'); views.tickets(); };
        });
        api('tickets_list', { status: state.ticketsFilter }).then(function (list) {
            var box = $('#view');
            var target = box.querySelector('.table-wrap, .empty, .loading');
            if (!list.length) {
                if (target) { target.outerHTML = '<div class="card empty">暂无工单</div>'; }
                return;
            }
            var html = '<div class="table-wrap"><table><thead><tr><th>ID</th><th>用户</th><th>标题</th><th>状态</th><th>最后更新</th><th></th></tr></thead><tbody>';
            list.forEach(function (t) {
                html += '<tr><td>' + t.id + '</td><td>' + esc(t.email || t.user_id) + '</td><td class="wrap">' + esc(t.subject) + '</td><td>' + badge(t.status, tkBadge) + '</td><td>' + fmt(t.updated_at) + '</td><td><a href="javascript:;" data-id="' + t.id + '">处理</a></td></tr>';
            });
            html += '</tbody></table></div>';
            if (target) { target.outerHTML = html; }
            box.querySelectorAll('a[data-id]').forEach(function (a) {
                a.onclick = function () { adminTicket(parseInt(a.getAttribute('data-id'), 10)); };
            });
        }).catch(function (e) { toast(e.message, 'err'); });
    };
    function adminTicket(id) {
        api('ticket_get', { id: id }).then(function (d) {
            var t = d.ticket;
            var html = modalTitle('工单 #' + t.id + ' · ' + t.subject, '')
                + '<div class="kv"><span class="k">用户</span><span class="v">' + esc(t.email) + '</span></div>'
                + '<div class="kv"><span class="k">状态</span><span class="v">' + badge(t.status, tkBadge) + '</span></div>'
                + '<div class="thread" style="margin-top:12px">'
                + d.replies.map(function (r) {
                    return '<div class="msg-item ' + (r.admin ? 'admin' : 'user') + '"><div class="meta">' + (r.admin ? '客服' : '用户') + ' · ' + fmt(r.created_at) + '</div>' + esc(r.content) + '</div>';
                }).join('')
                + '</div>'
                + '<label style="margin-top:14px">回复</label><textarea id="aReply" maxlength="5000"></textarea>'
                + '<div class="modal-actions">'
                + (t.status !== 'closed' ? '<button class="btn ghost" id="btnClose">关闭工单</button>' : '<button class="btn ghost" id="btnReopen">重新打开</button>')
                + '<button class="btn" id="btnAReply">发送回复</button></div>';
            showModal(html);
            $('#btnAReply').onclick = function () {
                var c = $('#aReply').value.trim();
                if (!c) { toast('请输入回复内容', 'err'); return; }
                api('ticket_reply', { id: id, content: c }).then(function () { toast('已回复'); closeModal(); views.tickets(); }).catch(function (e) { toast(e.message, 'err'); });
            };
            var closeBtn = $('#btnClose'), openBtn = $('#btnReopen');
            if (closeBtn) {
                closeBtn.onclick = function () {
                    api('ticket_status', { id: id, status: 'closed' }).then(function () { toast('已关闭'); closeModal(); views.tickets(); }).catch(function (e) { toast(e.message, 'err'); });
                };
            }
            if (openBtn) {
                openBtn.onclick = function () {
                    api('ticket_status', { id: id, status: 'open' }).then(function () { toast('已打开'); closeModal(); views.tickets(); }).catch(function (e) { toast(e.message, 'err'); });
                };
            }
        }).catch(function (e) { toast(e.message, 'err'); });
    }

    views.settings = function () {
        $('#view').innerHTML = loading();
        api('settings_get').then(function (s) {
            var html = '<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr));align-items:start">';

            /* 站点 */
            html += '<div class="card"><div class="card-title">站点设置</div>'
                + '<label>站点名称</label><input type="text" id="set_site_name" value="' + esc(s.site.site_name) + '">'
                + '<label>站点标语</label><input type="text" id="set_site_slogan" value="' + esc(s.site.site_slogan) + '">'
                + '<label>Logo 地址</label><input type="text" id="set_logo" value="' + esc(s.site.logo) + '">'
                + '<label>主机名前缀 <span class="hint">(开通时自动生成主机名用，仅限小写字母1-6位，默认 ep)</span></label><input type="text" id="set_host_prefix" value="' + esc(s.site.host_prefix) + '" maxlength="6" placeholder="ep">'
                + '<button class="btn block" id="btnSaveSite" style="margin-top:16px">保存</button></div>';

            /* SMTP */
            html += '<div class="card"><div class="card-title">SMTP 邮件设置</div>'
                + '<div class="grid" style="grid-template-columns:1fr 110px;gap:0 12px">'
                + '<div><label>SMTP 服务器</label><input type="text" id="set_smtp_host" value="' + esc(s.smtp.smtp_host) + '"></div>'
                + '<div><label>端口</label><input type="number" id="set_smtp_port" value="' + esc(s.smtp.smtp_port) + '"></div></div>'
                + '<label>加密方式</label><select id="set_smtp_secure">'
                + '<option value="ssl"' + (s.smtp.smtp_secure === 'ssl' ? ' selected' : '') + '>SSL (465)</option>'
                + '<option value="tls"' + (s.smtp.smtp_secure === 'tls' ? ' selected' : '') + '>TLS/STARTTLS (587)</option>'
                + '<option value="none"' + (s.smtp.smtp_secure === 'none' ? ' selected' : '') + '>无加密</option></select>'
                + '<label>账号</label><input type="text" id="set_smtp_user" value="' + esc(s.smtp.smtp_user) + '">'
                + '<label>密码/授权码</label><input type="password" id="set_smtp_pass" value="' + esc(s.smtp.smtp_pass) + '">'
                + '<label>发件人邮箱</label><input type="text" id="set_smtp_from" value="' + esc(s.smtp.smtp_from) + '">'
                + '<label>发件人名称</label><input type="text" id="set_smtp_from_name" value="' + esc(s.smtp.smtp_from_name) + '">'
                + '<div class="modal-actions" style="justify-content:space-between">'
                + '<button class="btn ghost" id="btnSmtpTest">发送测试邮件</button>'
                + '<button class="btn" id="btnSaveSmtp">保存</button></div></div>';

            /* 支付 */
            html += '<div class="card"><div class="card-title">易支付设置</div>'
                + '<label>接口地址</label><input type="text" id="set_pay_api" value="' + esc(s.pay.pay_api) + '" placeholder="https://pay.xicheny.com">'
                + '<label>商户ID (pid)</label><input type="text" id="set_pay_pid" value="' + esc(s.pay.pay_pid) + '">'
                + '<label>商户私钥</label><textarea id="set_pay_private_key" rows="4" placeholder="-----BEGIN RSA PRIVATE KEY-----">' + esc(s.pay.pay_private_key) + '</textarea>'
                + '<label>平台公钥</label><textarea id="set_pay_public_key" rows="4" placeholder="-----BEGIN PUBLIC KEY-----">' + esc(s.pay.pay_public_key) + '</textarea>'
                + '<div class="hint">密钥在支付平台「商户后台 → 个人资料 → API信息」中生成。通知地址：</div>'
                + '<div class="hint" style="word-break:break-all">异步：' + esc(s.pay.notify_url) + '<br>同步：' + esc(s.pay.return_url) + '</div>'
                + '<button class="btn block" id="btnSavePay" style="margin-top:16px">保存</button></div>';

            html += '</div>';
            $('#view').innerHTML = html;

            $('#btnSaveSite').onclick = function () {
                var prefix = $('#set_host_prefix').value.trim().toLowerCase();
                if (prefix && !/^[a-z]{1,6}$/.test(prefix)) { toast('主机名前缀仅限1-6位小写字母', 'err'); return; }
                api('settings_save', { section: 'site', site_name: $('#set_site_name').value.trim(), site_slogan: $('#set_site_slogan').value.trim(), logo: $('#set_logo').value.trim(), host_prefix: prefix })
                    .then(function () { toast('站点设置已保存'); }).catch(function (e) { toast(e.message, 'err'); });
            };
            $('#btnSaveSmtp').onclick = function () {
                api('settings_save', { section: 'smtp', smtp_host: $('#set_smtp_host').value.trim(), smtp_port: $('#set_smtp_port').value, smtp_user: $('#set_smtp_user').value.trim(), smtp_pass: $('#set_smtp_pass').value, smtp_from: $('#set_smtp_from').value.trim(), smtp_from_name: $('#set_smtp_from_name').value.trim(), smtp_secure: $('#set_smtp_secure').value })
                    .then(function () { toast('SMTP 设置已保存'); }).catch(function (e) { toast(e.message, 'err'); });
            };
            $('#btnSmtpTest').onclick = function () {
                var to = window.prompt('输入测试收件邮箱：');
                if (!to) { return; }
                api('smtp_test', { to: to }).then(function (d) { toast(d.msg); }).catch(function (e) { toast(e.message, 'err'); });
            };
            $('#btnSavePay').onclick = function () {
                api('settings_save', { section: 'pay', pay_api: $('#set_pay_api').value.trim(), pay_pid: $('#set_pay_pid').value.trim(), pay_private_key: $('#set_pay_private_key').value, pay_public_key: $('#set_pay_public_key').value })
                    .then(function () { toast('支付设置已保存'); }).catch(function (e) { toast(e.message, 'err'); });
            };
        }).catch(function (e) { $('#view').innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; });
    };

    /* ---------- 启动 ---------- */
    function bootAdmin() {
        $('#adminUserName').textContent = me.username;
        $('#btnLogout').onclick = function () {
            api('logout').then(function () { location.reload(); });
        };
        $('#btnChgPwd').onclick = showChgPwd;
        bindSidebar();
        window.addEventListener('hashchange', route);
        route();
    }
    $('#btnLogin').onclick = function () {
        var u = $('#loginUser').value.trim(), p = $('#loginPass').value;
        if (!u || !p) { $('#loginMsg').style.display = 'block'; $('#loginMsg').className = 'msg err'; $('#loginMsg').textContent = '请输入账号和密码'; return; }
        api('login', { username: u, password: p }).then(function (a) {
            me = { id: a.id, username: a.username };
            $('#loginView').style.display = 'none';
            $('#adminLayout').style.display = 'flex';
            bootAdmin();
        }).catch(function (e) {
            $('#loginMsg').style.display = 'block'; $('#loginMsg').className = 'msg err'; $('#loginMsg').textContent = e.message;
        });
    };
    $('#loginPass').addEventListener('keydown', function (e) { if (e.key === 'Enter') { $('#btnLogin').click(); } });

    if (me) {
        $('#loginView').style.display = 'none';
        $('#adminLayout').style.display = 'flex';
        bootAdmin();
    } else {
        showLogin();
    }
})();
