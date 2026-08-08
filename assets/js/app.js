/* ============ xnzj 前台 SPA（无刷新） ============ */
(function () {
    'use strict';
    var B = window.BOOT || {};
    var state = { me: B.me || null, orderPlan: null, refresh: null };

    /* ---------- 工具 ---------- */
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
        var wrap = $('#toast');
        var t = document.createElement('div');
        t.className = 'toast' + (type === 'err' ? ' err' : '');
        t.textContent = msg;
        wrap.appendChild(t);
        setTimeout(function () { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; }, 2600);
        setTimeout(function () { t.remove(); }, 3000);
    }
    function confirmDlg(msg) { return window.confirm(msg); }
    function loading() { return '<div class="loading"><span class="spin"></span>加载中…</div>'; }
    function statusBadge(s) {
        var map = { pending: ['warn', '待支付'], paid: ['ok', '已支付'], cancelled: ['gray', '已取消'], open: ['warn', '待处理'], replied: ['info', '已回复'], closed: ['gray', '已关闭'] };
        var m = map[s] || ['gray', s];
        return '<span class="badge ' + m[0] + '">' + m[1] + '</span>';
    }
    function prodStatus(s) {
        var map = { 0: ['err', '开通失败'], 1: ['ok', '正常'], 2: ['warn', '已暂停'], 3: ['gray', '已删除'] };
        var m = map[s] || ['gray', '未知'];
        return '<span class="badge ' + m[0] + '">' + m[1] + '</span>';
    }
    function fmt(t) { return t ? String(t).replace(' ', ' ') : '-'; }
    function money(n) { return '¥' + Number(n).toFixed(2); }

    /* ---------- 模态 ---------- */
    function showModal(html, onClose) {
        var mask = $('#modal');
        $('#modalBox').innerHTML = html;
        mask.style.display = 'flex';
        mask.onclick = function (e) { if (e.target === mask) closeModal(); };
        if (onClose) { mask._onClose = onClose; }
    }
    function closeModal() {
        var mask = $('#modal');
        mask.style.display = 'none';
        if (mask._onClose) { mask._onClose(); mask._onClose = null; }
    }
    function modalTitle(title, bodyHtml) {
        return '<div class="modal-title">' + esc(title) + '<button class="modal-close" onclick="window.__closeModal()">&times;</button></div>' + bodyHtml;
    }
    window.__closeModal = closeModal;

    /* ---------- 路由 ---------- */
    function route() {
        var h = (location.hash || '#/').slice(2) || '';
        var parts = h.split('?');
        var path = parts[0];
        var q = new URLSearchParams(parts[1] || '');
        var seg = path.split('/').filter(Boolean);
        var navs = document.querySelectorAll('#nav a[data-nav]');
        navs.forEach(function (a) { a.classList.remove('active'); });

        if (q.get('paid') === '1' && seg[0] === 'product') {
            toast('支付成功');
            history.replaceState(null, '', '#/product/' + seg[1]);
            q.delete('paid');
        }
        if (q.get('payfail') === '1') {
            toast('支付未完成，如有疑问请到订单页查询', 'err');
            history.replaceState(null, '', '#/');
        }

        if (seg[0] === 'product' && seg[1]) { markNav('products'); return viewProduct(parseInt(seg[1], 10)); }
        switch (seg[0]) {
            case 'products': markNav('products'); return viewProducts();
            case 'tickets': markNav('tickets'); return viewTickets();
            case 'ticket': if (seg[1]) { markNav('tickets'); return viewTicket(parseInt(seg[1], 10)); }
            case 'profile': markNav('profile'); return viewProfile();
            case 'login': return viewAuth('login');
            case 'register': return viewAuth('register');
            case 'order': markNav('home'); return viewHome();
            default: markNav('home'); return viewHome();
        }
    }
    function markNav(name) {
        var a = document.querySelector('#nav a[data-nav="' + name + '"]');
        if (a) { a.classList.add('active'); }
    }
    function go(hash) { location.hash = hash; }
    function renderNavRight() {
        var el = $('#navRight');
        if (state.me) {
            el.innerHTML = '<span class="email" title="' + esc(state.me.email) + '">' + esc(state.me.email) + '</span>'
                + '<a href="javascript:;" class="btn sm ghost" id="btnLogout">退出</a>';
            $('#btnLogout').onclick = function () {
                api('logout').then(function () { state.me = null; renderNavRight(); go('/'); toast('已退出登录'); }).catch(function (e) { toast(e.message, 'err'); });
            };
        } else {
            el.innerHTML = '<a href="javascript:;" class="btn sm ghost" id="btnLogin">登录</a><a href="javascript:;" class="btn sm" id="btnReg">注册</a>';
            $('#btnLogin').onclick = function () { go('/login'); };
            $('#btnReg').onclick = function () { go('/register'); };
        }
    }

    /* ---------- 视图：首页 ---------- */
    function viewHome() {
        $('#view').innerHTML = '<div class="hero"><h1>' + esc(B.slogan || '') + '</h1><p>选择适合您的虚拟主机方案，下单支付后自动开通</p></div>' + loading();
        api('plans').then(function (plans) {
            if (!plans.length) {
                $('#view').innerHTML = '<div class="hero"><h1>' + esc(B.slogan || '') + '</h1><p>选择适合您的虚拟主机方案，下单支付后自动开通</p></div><div class="card empty">暂无在售方案，请稍后再来</div>';
                return;
            }
            var html = '<div class="hero"><h1>' + esc(B.slogan || '') + '</h1><p>选择适合您的虚拟主机方案，下单支付后自动开通</p></div><div class="grid grid-3">';
            plans.forEach(function (p) {
                var s = p.specs;
                html += '<div class="plan-card">'
                    + '<div class="plan-name">' + esc(p.name) + '</div>'
                    + '<div class="plan-price">' + money(p.price) + '<small> / 月</small></div>'
                    + '<div class="plan-specs">'
                    + '<span>网页空间：' + s.web + ' MB</span>'
                    + '<span>数据库：' + s.sql + ' MB</span>'
                    + '<span>绑定域名：' + (s.domain < 0 ? '不限' : s.domain + ' 个') + '</span>'
                    + '<span>月流量：' + (s.flow > 0 ? s.flow + ' GB' : '不限') + '</span>'
                    + '<span>端口：' + esc(s.ports || '80') + '</span>'
                    + (p.note ? '<span class="plan-note">' + esc(p.note) + '</span>' : '')
                    + '</div>'
                    + '<button class="btn block" data-plan="' + p.id + '">立即订购</button></div>';
            });
            html += '</div>';
            $('#view').innerHTML = html;
            $('#view').querySelectorAll('button[data-plan]').forEach(function (b) {
                b.onclick = function () { openOrder(parseInt(b.getAttribute('data-plan'), 10)); };
            });
        }).catch(function (e) { $('#view').innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; });
    }

    /* ---------- 订购流程 ---------- */
    function openOrder(planId) {
        if (!state.me) {
            state.orderPlan = planId;
            toast('请先登录', 'err');
            go('/login');
            return;
        }
        api('plans').then(function (plans) {
            var p = plans.filter(function (x) { return x.id === planId; })[0];
            if (!p) { toast('方案不存在', 'err'); return; }
            var cycles = [['month', '月付', 1], ['quarter', '季付', 3], ['halfyear', '半年付', 6], ['year', '年付', 10]];
            var price = Number(p.price);
            var html = modalTitle('订购 ' + p.name, '');
            html += '<div class="kv"><span class="k">方案</span><span class="v">' + esc(p.name) + '</span></div>';
            html += '<label>购买周期</label><div class="radio-group" id="cycleGroup">';
            cycles.forEach(function (c, i) {
                html += '<label class="' + (i === 0 ? 'on' : '') + '" data-cycle="' + c[0] + '"><input type="radio" name="cycle" value="' + c[0] + '"' + (i === 0 ? ' checked' : '') + '>' + c[1] + '<span class="sub">' + money((price * c[2]).toFixed(2)) + '</span></label>';
            });
            html += '</div>';
            html += '<label>支付方式</label><div class="radio-group" id="payGroup">'
                + '<label class="on" data-pay="alipay"><input type="radio" name="pay" value="alipay" checked>支付宝</label>'
                + '<label data-pay="wxpay"><input type="radio" name="pay" value="wxpay">微信支付</label></div>';
            html += '<div class="kv" style="margin-top:14px"><span class="k">应付金额</span><span class="v" style="color:var(--primary);font-size:18px;font-weight:700" id="orderAmount">' + money(price) + '</span></div>';
            html += '<div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">取消</button><button class="btn" id="btnPay">确认下单并支付</button></div>';
            showModal(html);
            var amountEl = $('#orderAmount');
            $('#cycleGroup').querySelectorAll('label').forEach(function (l) {
                l.onclick = function () {
                    $('#cycleGroup').querySelectorAll('label').forEach(function (x) { x.classList.remove('on'); });
                    l.classList.add('on');
                    var i = parseInt(l.getAttribute('data-cycle') === 'month' ? 0 : (l.getAttribute('data-cycle') === 'quarter' ? 1 : (l.getAttribute('data-cycle') === 'halfyear' ? 2 : 3)), 10);
                    amountEl.textContent = money((price * cycles[i][2]).toFixed(2));
                };
            });
            $('#payGroup').querySelectorAll('label').forEach(function (l) {
                l.onclick = function () {
                    $('#payGroup').querySelectorAll('label').forEach(function (x) { x.classList.remove('on'); });
                    l.classList.add('on');
                };
            });
            $('#btnPay').onclick = function () {
                var cycle = $('#cycleGroup input:checked').value;
                var payType = $('#payGroup input:checked').value;
                var btn = this; btn.disabled = true; btn.textContent = '正在下单…';
                api('order_create', { plan_id: planId, cycle: cycle, pay_type: payType })
                    .then(function (d) {
                        closeModal();
                        toast('订单已创建，正在跳转支付…');
                        var div = document.createElement('div');
                        div.innerHTML = d.pay_form;
                        document.body.appendChild(div);
                        var f = div.querySelector('form');
                        if (f) { f.submit(); }
                    })
                    .catch(function (e) { btn.disabled = false; btn.textContent = '确认下单并支付'; toast(e.message, 'err'); });
            };
        }).catch(function (e) { toast(e.message, 'err'); });
    }

    /* ---------- 视图：我的产品 ---------- */
    function viewProducts() {
        $('#view').innerHTML = '<div class="card-title">我的产品</div>' + loading();
        api('products').then(function (list) {
            if (!list.length) { $('#view').innerHTML = '<div class="card empty">暂无产品，去首页选购吧</div>'; return; }
            var html = '<div class="table-wrap"><table><thead><tr><th>ID</th><th>方案</th><th>主机账号</th><th>状态</th><th>开通时间</th><th>到期时间</th><th></th></tr></thead><tbody>';
            list.forEach(function (p) {
                html += '<tr><td>#' + p.id + '</td><td>' + esc(p.plan_name) + '</td><td>' + esc(p.username) + '</td><td>' + prodStatus(p.status) + '</td><td>' + fmt(p.activated_at) + '</td><td>' + fmt(p.expires_at) + '</td><td><a href="#/product/' + p.id + '">详情</a></td></tr>';
            });
            html += '</tbody></table></div>';
            $('#view').innerHTML = html;
        }).catch(function (e) { $('#view').innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; });
    }

    /* ---------- 视图：产品详情 ---------- */
    function viewProduct(id) {
        $('#view').innerHTML = loading();
        api('product_get', { id: id }).then(function (p) {
            var html = '<div class="card"><div class="card-title">' + esc(p.plan_name) + ' ' + prodStatus(p.status)
                + (p.status === 1 && p.panel_url ? '<a class="btn sm" target="_blank" rel="noopener" href="' + esc(p.panel_url) + '">进入控制面板</a>' : '')
                + (p.status === 1 && p.panel ? '<a class="btn sm" href="javascript:;" id="btnPanel">登录面板</a>' : '')
                + '</div>';
            html += '<div class="kv"><span class="k">主机账号</span><span class="v">' + esc(p.username) + '</span></div>';
            html += '<div class="kv"><span class="k">主机密码</span><span class="v"><span id="pwdText">••••••••</span> <a href="javascript:;" id="btnPwd">显示</a></span></div>';
            html += '<div class="kv"><span class="k">绑定域名</span><span class="v">' + esc(p.domain || '-') + '</span></div>';
            html += '<div class="kv"><span class="k">所属服务器</span><span class="v">' + esc(p.server_name || '-') + '</span></div>';
            html += '<div class="kv"><span class="k">开通时间</span><span class="v">' + fmt(p.activated_at) + '</span></div>';
            html += '<div class="kv"><span class="k">到期时间</span><span class="v">' + fmt(p.expires_at) + '</span></div>';
            html += '<div class="kv"><span class="k">网页空间</span><span class="v">' + p.specs.web + ' MB</span></div>';
            html += '<div class="kv"><span class="k">数据库</span><span class="v">' + p.specs.sql + ' MB</span></div>';
            html += '<div class="kv"><span class="k">绑定域名数</span><span class="v">' + (p.specs.domain < 0 ? '不限' : p.specs.domain + ' 个') + '</span></div>';
            html += '<div class="kv"><span class="k">月流量</span><span class="v">' + (p.specs.flow > 0 ? p.specs.flow + ' GB' : '不限') + '</span></div>';
            html += (p.note ? '<div class="kv"><span class="k">备注</span><span class="v">' + esc(p.note) + '</span></div>' : '');
            if (p.status === 0) { html += '<div class="msg err" style="display:block;margin-top:14px">主机开通失败，请联系管理员或提交工单处理</div>'; }
            if (p.status === 1) {
                html += '<div class="modal-actions" style="justify-content:flex-start">'
                    + '<button class="btn ghost sm" id="btnRepwd">修改密码</button>'
                    + '<button class="btn danger sm" id="btnTerm">退订删除</button></div>';
            }
            html += '</div>';
            $('#view').innerHTML = html;
            var shown = false;
            $('#btnPwd').onclick = function () {
                shown = !shown;
                $('#pwdText').textContent = shown ? p.password : '••••••••';
                this.textContent = shown ? '隐藏' : '显示';
            };
            // 魔方上游产品：登录面板（地址/账号密码从上游获取）
            if ($('#btnPanel')) {
                $('#btnPanel').onclick = function () {
                    var items = (p.panel && p.panel.items) || [];
                    if (items.length === 1) { window.open(items[0].url, '_blank'); return; }
                    var list = items.map(function (it) {
                        return '<div class="kv"><span class="k">' + esc(it.title) + '</span><span class="v"><a href="' + esc(it.url) + '" target="_blank" rel="noopener">打开</a></span></div>';
                    }).join('');
                    var cred = '';
                    if (p.panel.host) { cred += '<div class="kv"><span class="k">主机IP</span><span class="v">' + esc(p.panel.host) + '</span></div>'; }
                    if (p.panel.username) { cred += '<div class="kv"><span class="k">面板账号</span><span class="v">' + esc(p.panel.username) + '</span></div>'; }
                    if (p.panel.password) { cred += '<div class="kv"><span class="k">面板密码</span><span class="v">' + esc(p.panel.password) + '</span></div>'; }
                    showModal(modalTitle('登录面板', (list || '<div class="empty">上游未提供面板入口，请使用下方账号密码登录面板</div>') + cred + '<div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">关闭</button></div>'));
                };
            }
            if ($('#btnRepwd')) {
                $('#btnRepwd').onclick = function () {
                    showModal(modalTitle('修改主机密码', '<label>新密码 <span class="hint">(6-32位)</span></label><input type="text" id="newPwd" placeholder="请输入新密码"><div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">取消</button><button class="btn" id="btnRepwdOk">确认修改</button></div>'));
                    $('#btnRepwdOk').onclick = function () {
                        var pwd = $('#newPwd').value.trim();
                        if (pwd.length < 6) { toast('密码至少6位', 'err'); return; }
                        api('product_repwd', { id: id, pwd: pwd }).then(function () { closeModal(); toast('密码已修改'); }).catch(function (e) { toast(e.message, 'err'); });
                    };
                };
            }
            if ($('#btnTerm')) {
                $('#btnTerm').onclick = function () {
                    if (!confirmDlg('确定删除该主机吗？此操作会同步删除服务器上的站点，且不可恢复！')) { return; }
                    api('product_terminate', { id: id }).then(function () { toast('已退订删除'); go('/products'); }).catch(function (e) { toast(e.message, 'err'); });
                };
            }
        }).catch(function (e) { $('#view').innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; });
    }

    /* ---------- 视图：工单 ---------- */
    function viewTickets() {
        $('#view').innerHTML = '<div class="card-title">我的工单<button class="btn sm" id="btnNewTicket">新建工单</button></div>' + loading();
        api('tickets').then(function (list) {
            if (!list.length) {
                $('#view').innerHTML = '<div class="card-title">我的工单<button class="btn sm" id="btnNewTicket">新建工单</button></div><div class="card empty">暂无工单</div>';
            } else {
                var html = '<div class="table-wrap"><table><thead><tr><th>ID</th><th>标题</th><th>状态</th><th>最后更新</th><th></th></tr></thead><tbody>';
                list.forEach(function (t) {
                    html += '<tr><td>#' + t.id + '</td><td class="wrap">' + esc(t.subject) + '</td><td>' + statusBadge(t.status) + '</td><td>' + fmt(t.updated_at) + '</td><td><a href="#/ticket/' + t.id + '">查看</a></td></tr>';
                });
                html += '</tbody></table></div>';
                $('#view').innerHTML = '<div class="card-title">我的工单<button class="btn sm" id="btnNewTicket">新建工单</button></div>' + html;
            }
            $('#btnNewTicket').onclick = function () {
                showModal(modalTitle('新建工单', '<label>标题</label><input type="text" id="tkSubject" maxlength="80"><label>内容</label><textarea id="tkContent" maxlength="5000"></textarea><div class="modal-actions"><button class="btn ghost" onclick="window.__closeModal()">取消</button><button class="btn" id="btnTkOk">提交</button></div>'));
                $('#btnTkOk').onclick = function () {
                    var subject = $('#tkSubject').value.trim();
                    var content = $('#tkContent').value.trim();
                    if (subject.length < 2) { toast('标题至少2个字', 'err'); return; }
                    if (content.length < 2) { toast('内容至少2个字', 'err'); return; }
                    api('ticket_create', { subject: subject, content: content }).then(function (d) { closeModal(); go('/ticket/' + d.id); }).catch(function (e) { toast(e.message, 'err'); });
                };
            };
        }).catch(function (e) { $('#view').innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; });
    }

    function viewTicket(id) {
        $('#view').innerHTML = loading();
        api('ticket_get', { id: id }).then(function (d) {
            var t = d.ticket;
            var html = '<div class="card"><div class="card-title">工单 #' + t.id + ' · ' + esc(t.subject) + ' ' + statusBadge(t.status) + '</div>';
            html += '<div class="thread">';
            d.replies.forEach(function (r) {
                html += '<div class="msg-item ' + (r.admin ? 'admin' : 'user') + '"><div class="meta">' + (r.admin ? '客服' : '我') + ' · ' + fmt(r.created_at) + '</div>' + esc(r.content) + '</div>';
            });
            html += '</div>';
            if (t.status !== 'closed') {
                html += '<label style="margin-top:16px">回复</label><textarea id="replyContent" maxlength="5000"></textarea><div class="modal-actions"><button class="btn" id="btnReply">发送回复</button></div>';
            } else {
                html += '<div class="msg gray" style="margin-top:16px;color:var(--muted)">工单已关闭</div>';
            }
            html += '</div>';
            $('#view').innerHTML = html;
            if ($('#btnReply')) {
                $('#btnReply').onclick = function () {
                    var content = $('#replyContent').value.trim();
                    if (!content) { toast('请输入回复内容', 'err'); return; }
                    api('ticket_reply', { id: id, content: content }).then(function () { toast('已回复'); viewTicket(id); }).catch(function (e) { toast(e.message, 'err'); });
                };
            }
        }).catch(function (e) { $('#view').innerHTML = '<div class="card empty">' + esc(e.message) + '</div>'; });
    }

    /* ---------- 视图：个人中心 ---------- */
    function viewProfile() {
        if (!state.me) { go('/login'); return; }
        var html = '<div class="card" style="max-width:480px"><div class="card-title">个人中心</div>'
            + '<div class="kv"><span class="k">邮箱</span><span class="v">' + esc(state.me.email) + '</span></div>'
            + '<label>修改密码</label><input type="password" id="oldPwd" placeholder="原密码">'
            + '<label>新密码</label><input type="password" id="newPwd" placeholder="新密码（至少6位）">'
            + '<button class="btn block" id="btnChange" style="margin-top:16px">确认修改</button></div>';
        $('#view').innerHTML = html;
        $('#btnChange').onclick = function () {
            var oldP = $('#oldPwd').value;
            var newP = $('#newPwd').value;
            if (newP.length < 6) { toast('新密码至少6位', 'err'); return; }
            api('change_password', { old: oldP, new: newP }).then(function () { toast('密码修改成功'); $('#oldPwd').value = ''; $('#newPwd').value = ''; }).catch(function (e) { toast(e.message, 'err'); });
        };
    }

    /* ---------- 视图：登录 / 注册 ---------- */
    function viewAuth(mode) {
        var html = '<div class="auth-wrap"><div class="card">'
            + '<div class="auth-tabs"><a href="#/login" class="' + (mode === 'login' ? 'on' : '') + '">登录</a><a href="#/register" class="' + (mode === 'register' ? 'on' : '') + '">注册</a></div>';
        if (mode === 'login') {
            html += '<label>邮箱</label><input type="email" id="authEmail" autocomplete="username">'
                + '<label>密码</label><input type="password" id="authPass" autocomplete="current-password">'
                + '<div id="authMsg" class="msg"></div>'
                + '<button class="btn block" id="btnAuth" style="margin-top:18px">登 录</button>';
        } else {
            html += '<label>邮箱</label><input type="email" id="authEmail" autocomplete="email">'
                + '<label>验证码</label><div class="code-input"><input type="text" id="authCode" maxlength="6" placeholder="6位验证码"><button class="btn ghost" id="btnSend">发送验证码</button></div>'
                + '<label>密码</label><input type="password" id="authPass" autocomplete="new-password">'
                + '<label>确认密码</label><input type="password" id="authPass2" autocomplete="new-password">'
                + '<div id="authMsg" class="msg"></div>'
                + '<button class="btn block" id="btnAuth" style="margin-top:18px">注 册</button>';
        }
        html += '</div></div>';
        $('#view').innerHTML = html;
        var msgEl = $('#authMsg');
        function setMsg(ok, text) { msgEl.className = 'msg ' + (ok ? 'ok' : 'err'); msgEl.textContent = text; }
        if (mode === 'register') {
            $('#btnSend').onclick = function () {
                var email = $('#authEmail').value.trim();
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setMsg(false, '邮箱格式不正确'); return; }
                var btn = this; btn.disabled = true; btn.textContent = '发送中…';
                api('sendcode', { email: email }).then(function () {
                    setMsg(true, '验证码已发送到 ' + email);
                    var n = 60;
                    btn.textContent = n + 's 后重发';
                    var timer = setInterval(function () {
                        n--;
                        if (n <= 0) { clearInterval(timer); btn.disabled = false; btn.textContent = '发送验证码'; }
                        else { btn.textContent = n + 's 后重发'; }
                    }, 1000);
                }).catch(function (e) { btn.disabled = false; btn.textContent = '发送验证码'; setMsg(false, e.message); });
            };
        }
        $('#btnAuth').onclick = function () {
            var email = $('#authEmail').value.trim();
            var pass = $('#authPass').value;
            var code = mode === 'register' ? $('#authCode').value.trim() : '';
            if (mode === 'register') {
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setMsg(false, '邮箱格式不正确'); return; }
                if (!/^\d{6}$/.test(code)) { setMsg(false, '请输入6位验证码'); return; }
                if (pass.length < 6) { setMsg(false, '密码至少6位'); return; }
                if ($('#authPass2').value !== pass) { setMsg(false, '两次输入的密码不一致'); return; }
            }
            var btn = this; btn.disabled = true;
            api(mode, { email: email, password: pass, confirm: pass, code: code }).then(function (u) {
                state.me = { id: u.id, email: u.email };
                renderNavRight();
                toast(mode === 'register' ? '注册成功，欢迎！' : '登录成功');
                if (state.orderPlan) { var p = state.orderPlan; state.orderPlan = null; openOrder(p); }
                else { go('/'); }
            }).catch(function (e) { btn.disabled = false; setMsg(false, e.message); });
        };
    }

    /* ---------- 启动 ---------- */
    // 移动端汉堡菜单开关
    var navToggle = $('#navToggle');
    if (navToggle) {
        navToggle.onclick = function () {
            var nav = $('#nav');
            var open = nav.classList.toggle('open');
            navToggle.classList.toggle('open', open);
        };
        // 点击菜单项后自动收起（移动端）
        $('#nav').addEventListener('click', function (e) {
            if (e.target.tagName === 'A') {
                $('#nav').classList.remove('open');
                navToggle.classList.remove('open');
            }
        });
    }
    renderNavRight();
    window.addEventListener('hashchange', route);
    route();
})();
