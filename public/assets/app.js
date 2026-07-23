(function () {
    const DOM = {
        sourceText: document.getElementById('sourceText'),
        parseBtn: document.getElementById('parseBtn'),
        saveEntryBtn: document.getElementById('saveEntryBtn'),
        parseStatus: document.getElementById('parseStatus'),
        resultArea: document.getElementById('resultArea'),
        parseResult: document.getElementById('parseResult'),
        statsBar: document.getElementById('statsBar'),
        roundsBody: document.getElementById('roundsBody'),
        roundsCount: document.getElementById('roundsCount'),
        codeBody: document.getElementById('codeBody'),
        codeCount: document.getElementById('codeCount'),
        todoBody: document.getElementById('todoBody'),
        todoCount: document.getElementById('todoCount'),
        hlBody: document.getElementById('hlBody'),
        hlCount: document.getElementById('hlCount'),
        loadSessionsBtn: document.getElementById('loadSessionsBtn'),
        sessionList: document.getElementById('sessionList'),
        sessionCount: document.getElementById('sessionCount'),
        sessionStatus: document.getElementById('sessionStatus'),
        loadDailiesBtn: document.getElementById('loadDailiesBtn'),
        generateDailyBtn: document.getElementById('generateDailyBtn'),
        dailyList: document.getElementById('dailyList'),
        dailyCount: document.getElementById('dailyCount'),
        dailyPanel: document.getElementById('dailyPanel'),
        dailyContent: document.getElementById('dailyContent'),
    };

    let lastParsed = null;
    let lastSource = 'manual';
    let lastSessionId = null;

    DOM.parseBtn.addEventListener('click', parse);
    DOM.saveEntryBtn.addEventListener('click', saveEntry);
    DOM.loadSessionsBtn.addEventListener('click', loadSessions);
    DOM.generateDailyBtn.addEventListener('click', generateDaily);
    DOM.loadDailiesBtn.addEventListener('click', loadDailyList);

    // ── Parse ──

    function parse(text) {
        var input = text || DOM.sourceText.value.trim();
        if (!input) { setStatus('请输入对话内容', 'error'); return; }
        setStatus('解析中...', '');
        DOM.parseBtn.disabled = true;

        fetch('/api/parse', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: input })
        }).then(function (r) { return r.json(); }).then(function (data) {
            DOM.parseBtn.disabled = false;
            if (data.error) { setStatus(data.error, 'error'); return; }
            setStatus('解析完成', 'success');
            lastParsed = data;
            renderResult(data);
            DOM.saveEntryBtn.style.display = 'inline-block';
        }).catch(function (err) {
            DOM.parseBtn.disabled = false;
            setStatus('请求失败: ' + err.message, 'error');
        });
    }

    // ── Save Entry ──

    function saveEntry() {
        if (!lastParsed) { setStatus('请先解析', 'error'); return; }
        setStatus('保存中...', '');
        fetch('/api/save-entry', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                date: new Date().toISOString().slice(0, 10),
                source: lastSource,
                parsed: lastParsed,
                session_id: lastSessionId,
            })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.error) { setStatus(data.error, 'error'); return; }
            setStatus('已保存', 'success');
            DOM.saveEntryBtn.style.display = 'none';
            loadDailyList();
        }).catch(function (err) {
            setStatus('保存失败: ' + err.message, 'error');
        });
    }

    // ── Sessions ──

    function loadSessions() {
        setSessStatus('加载中...', '');
        DOM.loadSessionsBtn.disabled = true;
        fetch('/api/sessions', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })
        .then(function (r) { return r.json(); }).then(function (data) {
            DOM.loadSessionsBtn.disabled = false;
            if (data.error) { setSessStatus(data.error, 'error'); return; }
            renderSessions(data.sessions || []);
            setSessStatus(data.sessions.length + ' 条记录', 'success');
        }).catch(function (err) {
            DOM.loadSessionsBtn.disabled = false;
            setSessStatus('加载失败: ' + err.message, 'error');
        });
    }

    function renderSessions(sessions) {
        DOM.sessionCount.textContent = sessions.length;
        if (sessions.length === 0) {
            DOM.sessionList.innerHTML = '<div class="session-empty">今日无对话记录</div>';
            return;
        }
        var html = '';
        sessions.forEach(function (s) {
            var time = s.time_created ? new Date(s.time_created / 1000).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' }) : '';
            var agent = s.agent || '';
            var title = s.title || '(无标题)';
            html += '<div class="session-item" data-id="' + s.id + '"><div class="session-title">' + escHtml(title) + '</div><div class="session-meta"><span>' + time + '</span><span class="session-agent">' + agent + '</span></div></div>';
        });
        DOM.sessionList.innerHTML = html;
        DOM.sessionList.querySelectorAll('.session-item').forEach(function (el) {
            el.addEventListener('click', function () {
                loadSession(this.getAttribute('data-id'));
            });
        });
    }

    function loadSession(sessionId) {
        lastSessionId = sessionId;
        lastSource = 'opencode';
        setSessStatus('导入中...', '');
        fetch('/api/load-session', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId })
        }).then(function (r) { return r.json(); }).then(function (data) {
            if (data.error) { setSessStatus(data.error, 'error'); return; }
            DOM.sourceText.value = data.conversation || '';
            setSessStatus('已导入，可编辑后解析', 'success');
            parse(data.conversation);
        }).catch(function (err) {
            setSessStatus('导入失败: ' + err.message, 'error');
        });
    }

    // ── Daily ──

    function loadDailyList() {
        fetch('/api/daily-list', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' })
        .then(function (r) { return r.json(); }).then(function (data) {
            renderDailyList(data.days || []);
        }).catch(function () {});
    }

    function renderDailyList(days) {
        DOM.dailyCount.textContent = days.length;
        if (days.length === 0) {
            DOM.dailyList.innerHTML = '<div class="session-empty">暂无日报</div>';
            return;
        }
        var html = '';
        days.forEach(function (d) {
            var status = d.generated ? '✅ 已生成' : '⏳ 有 ' + d.entries + ' 条待生成';
            html += '<div class="session-item" data-date="' + d.date + '"><div class="session-title">' + d.date + '</div><div class="session-meta"><span>' + status + '</span><span class="session-agent">' + d.entries + ' 条目</span></div></div>';
        });
        DOM.dailyList.innerHTML = html;
        DOM.dailyList.querySelectorAll('.session-item').forEach(function (el) {
            el.addEventListener('click', function () {
                viewDaily(this.getAttribute('data-date'));
            });
        });
    }

    function generateDaily() {
        DOM.generateDailyBtn.disabled = true;
        fetch('/api/generate-daily', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({}) })
        .then(function (r) { return r.json(); }).then(function (data) {
            DOM.generateDailyBtn.disabled = false;
            if (data.error) { alert(data.error); return; }
            loadDailyList();
            viewDaily(data.date);
        }).catch(function (err) {
            DOM.generateDailyBtn.disabled = false;
            alert('生成失败: ' + err.message);
        });
    }

    function viewDaily(date) {
        DOM.parseResult.style.display = 'none';
        DOM.dailyPanel.style.display = 'block';
        DOM.dailyContent.textContent = '加载中...';
        fetch('/api/get-daily', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ date: date }) })
        .then(function (r) { return r.json(); }).then(function (data) {
            if (data.error) { DOM.dailyContent.textContent = data.error; return; }
            DOM.dailyContent.textContent = data.content;
        }).catch(function (err) {
            DOM.dailyContent.textContent = '加载失败: ' + err.message;
        });
    }

    // ── Shared ──

    function setStatus(msg, type) {
        DOM.parseStatus.textContent = msg;
        DOM.parseStatus.className = 'status-text';
        if (type) DOM.parseStatus.classList.add(type);
    }

    function setSessStatus(msg, type) {
        DOM.sessionStatus.textContent = msg;
        DOM.sessionStatus.className = 'status-text';
        if (type) DOM.sessionStatus.classList.add(type);
    }

    function renderResult(data) {
        DOM.resultArea.style.display = 'none';
        DOM.parseResult.style.display = 'block';
        DOM.dailyPanel.style.display = 'none';

        var stats = data.stats || {};
        DOM.statsBar.innerHTML =
            '<div class="stat-item"><div class="stat-value">' + stats.rounds + '</div><div class="stat-label">对话轮次</div></div>' +
            '<div class="stat-item"><div class="stat-value">' + stats.code_blocks + '</div><div class="stat-label">代码块</div></div>' +
            '<div class="stat-item"><div class="stat-value">' + stats.todos + '</div><div class="stat-label">待办事项</div></div>' +
            '<div class="stat-item"><div class="stat-value">' + stats.highlights + '</div><div class="stat-label">关键句</div></div>';

        var html;

        var rounds = data.rounds || [];
        DOM.roundsCount.textContent = rounds.length;
        html = '';
        rounds.forEach(function (r) {
            var roleClass = r.role === 'user' ? 'user' : 'ai';
            var roleLabel = r.role === 'user' ? '🙋 我' : '🤖 AI';
            html += '<div class="round-card"><div class="round-role ' + roleClass + '">' + roleLabel + '</div><div class="round-content">' + escHtml(r.content) + '</div></div>';
        });
        DOM.roundsBody.innerHTML = html || '<div class="text-muted">未识别到对话轮次</div>';

        var codes = data.code_blocks || [];
        DOM.codeCount.textContent = codes.length;
        html = '';
        codes.forEach(function (c) {
            html += '<div class="code-block"><div class="code-header"><span>' + (c.lang || 'code') + '</span><button class="copy-btn" onclick="navigator.clipboard.writeText(' + JSON.stringify(c.code) + ')">复制</button></div><div class="code-content">' + escHtml(c.code) + '</div></div>';
        });
        DOM.codeBody.innerHTML = html || '<div class="text-muted">未识别到代码块</div>';

        var todos = data.todos || [];
        DOM.todoCount.textContent = todos.length;
        html = '';
        todos.forEach(function (t) {
            html += '<div class="todo-item"><input type="checkbox" class="todo-checkbox"' + (t.done ? ' checked' : '') + '><span class="todo-text' + (t.done ? ' todo-done' : '') + '">' + escHtml(t.text) + '</span></div>';
        });
        DOM.todoBody.innerHTML = html || '<div class="text-muted">未识别到待办事项</div>';

        var hls = data.highlights || [];
        DOM.hlCount.textContent = hls.length;
        html = '';
        hls.forEach(function (h) { html += '<div class="hl-item">' + escHtml(h) + '</div>'; });
        DOM.hlBody.innerHTML = html || '<div class="text-muted">未识别到关键句</div>';
    }

    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    loadDailyList();
})();
