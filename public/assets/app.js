(function () {
    const DOM = {
        sourceText: document.getElementById('sourceText'),
        parseBtn: document.getElementById('parseBtn'),
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
    };

    DOM.parseBtn.addEventListener('click', parse);
    DOM.loadSessionsBtn.addEventListener('click', loadSessions);

    function parse(text) {
        var input = text || DOM.sourceText.value.trim();
        if (!input) {
            setStatus('请输入对话内容', 'error');
            return;
        }
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
            renderResult(data);
        }).catch(function (err) {
            DOM.parseBtn.disabled = false;
            setStatus('请求失败: ' + err.message, 'error');
        });
    }

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

        var stats = data.stats || {};
        DOM.statsBar.innerHTML =
            '<div class="stat-item"><div class="stat-value">' + stats.rounds + '</div><div class="stat-label">对话轮次</div></div>' +
            '<div class="stat-item"><div class="stat-value">' + stats.code_blocks + '</div><div class="stat-label">代码块</div></div>' +
            '<div class="stat-item"><div class="stat-value">' + stats.todos + '</div><div class="stat-label">待办事项</div></div>' +
            '<div class="stat-item"><div class="stat-value">' + stats.highlights + '</div><div class="stat-label">关键句</div></div>';

        var html;

        // Rounds
        var rounds = data.rounds || [];
        DOM.roundsCount.textContent = rounds.length;
        html = '';
        rounds.forEach(function (r) {
            var roleClass = r.role === 'user' ? 'user' : 'ai';
            var roleLabel = r.role === 'user' ? '🙋 我' : '🤖 AI';
            html += '<div class="round-card"><div class="round-role ' + roleClass + '">' + roleLabel + '</div><div class="round-content">' + escHtml(r.content) + '</div></div>';
        });
        DOM.roundsBody.innerHTML = html || '<div class="text-muted">未识别到对话轮次</div>';

        // Code blocks
        var codes = data.code_blocks || [];
        DOM.codeCount.textContent = codes.length;
        html = '';
        codes.forEach(function (c) {
            html += '<div class="code-block"><div class="code-header"><span>' + (c.lang || 'code') + '</span><button class="copy-btn" onclick="navigator.clipboard.writeText(' + JSON.stringify(c.code) + ')">复制</button></div><div class="code-content">' + escHtml(c.code) + '</div></div>';
        });
        DOM.codeBody.innerHTML = html || '<div class="text-muted">未识别到代码块</div>';

        // Todos
        var todos = data.todos || [];
        DOM.todoCount.textContent = todos.length;
        html = '';
        todos.forEach(function (t) {
            html += '<div class="todo-item"><input type="checkbox" class="todo-checkbox"' + (t.done ? ' checked' : '') + '><span class="todo-text' + (t.done ? ' todo-done' : '') + '">' + escHtml(t.text) + '</span></div>';
        });
        DOM.todoBody.innerHTML = html || '<div class="text-muted">未识别到待办事项</div>';

        // Highlights
        var hls = data.highlights || [];
        DOM.hlCount.textContent = hls.length;
        html = '';
        hls.forEach(function (h) { html += '<div class="hl-item">' + escHtml(h) + '</div>'; });
        DOM.hlBody.innerHTML = html || '<div class="text-muted">未识别到关键句</div>';
    }

    function loadSessions() {
        setSessStatus('加载中...', '');
        DOM.loadSessionsBtn.disabled = true;

        fetch('/api/sessions', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        }).then(function (r) { return r.json(); }).then(function (data) {
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
        setSessStatus('导入中...', '');
        fetch('/api/load-session', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
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

    function escHtml(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
})();
