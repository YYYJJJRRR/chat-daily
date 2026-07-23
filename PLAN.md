# 对话日报工具 — 开发计划

## 当前状态

已完成：
- ✅ 手动粘贴 + 解析对话
- ✅ 从 opencode.db 自动导入会话
- ✅ 解析：轮次分段、代码块提取、待办识别、关键句提取、礼貌过滤

---

## Phase 1：保存 + 日报生成（核心闭环）

### 目标

```
导入 → 解析 → 保存 JSON → 合并当日条目 → 生成 Markdown 日报
```

### 涉及文件

| 文件 | 新增/修改 | 用途 |
|------|----------|------|
| `src/Storage.php` | 新增 | 条目 JSON 的增删查 |
| `src/Generator.php` | 新增 | 日报 Markdown 生成 |
| `src/index.php` | 修改 | 新增 `/api/save-entry`、`/api/generate-daily`、`/api/daily-list` |
| `public/index.php` | 修改 | 新增路由注册 |
| `public/templates/main.html` | 修改 | 新增日报面板 |
| `public/assets/app.js` | 修改 | 新增日报生成交互 |
| `public/assets/app.css` | 修改 | 日报表样式 |

### Entry JSON 格式

```json
{
    "id": "uuid",
    "date": "2026-07-23",
    "source": "opencode|manual",
    "session_id": "ses_xxx",
    "title": "会话标题",
    "parsed_at": "2026-07-23T18:00:00+08:00",
    "highlights": ["关键句1", "关键句2"],
    "todos": [
        {"text": "待办项", "done": false}
    ],
    "code_snippets": [
        {"lang": "php", "code": "<?php ..."}
    ],
    "key_decisions": ["技术决策1"],
    "stats": {
        "rounds": 5,
        "code_blocks": 2,
        "todos": 3,
        "highlights": 4
    }
}
```

### 日报 Markdown 格式

```markdown
# 2026-07-23 日报

## 📌 今日要点
- 实现了文件批量改名功能

## 🧩 关键代码
```php
$hash = md5_file($fullPath);
```

## 📝 技术决策
- 放弃 RecursiveDirectoryIterator，改用 DirectoryIterator

## ✅ 待办
- [ ] 正则重命名

## 💬 对话摘要（4 轮）
...
```

### Generator 逻辑

1. 读取当天所有 entry JSON
2. 合并所有 highlights（去重）
3. 合并所有 code_snippets（去重，相同代码只保留一次）
4. 合并所有 todos
5. 合并所有对话轮次
6. 按优先级排序：highlights > code > decisions > todos > 原始对话
7. 输出 Markdown 到 `output/{date}.md`

### API 接口

| 接口 | 方法 | 参数 | 返回 |
|------|------|------|------|
| `/api/save-entry` | POST | `{date, source, parsed}` | `{id}` |
| `/api/generate-daily` | POST | `{date}` | `{markdown, path}` |
| `/api/daily-list` | POST | - | `{dailies: [{date, path}]}` |

---

## Phase 2：日报预览 + 编辑

- 在右侧展示已生成的日报列表
- 点击可预览 Markdown 渲染效果
- 支持手动调整条目内容（编辑关键句、增删待办）

---

## Phase 3：冗余压缩增强

- 相邻段落语义相似度合并
- 跨会话代码块去重
- 空段落/纯表情行移除

---

## Phase 4：周报/月报汇总

- 合并多日日报生成周报（周一 → 周日）
- 按月生成月报
- 统计趋势（代码量、轮次数、待办完成率）

---

## 执行顺序

| 阶段 | 内容 | 工作量 |
|------|------|--------|
| **Phase 1** | Storage + Generator + 日报面板 | 2 天 |
| Phase 2 | 日报预览 + 编辑 | 1 天 |
| Phase 3 | 压缩增强 | 1 天 |
| Phase 4 | 周报/月报 | 1-2 天 |

---

先做 **Phase 1**，完成后整个链路闭环：导入对话 → 解析 → 保存 → 生成日报。
