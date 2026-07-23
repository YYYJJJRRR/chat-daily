# AI 对话日报工具

将每天与 AI（opencode）的对话记录自动整理为结构化 Markdown 日报，提取关键信息、压缩冗余、沉淀知识。

---

## 快速启动

```powershell
cd D:\tools\chat-daily
php -S 127.0.0.1:9091 -t public -c C:\php\php.ini
```

浏览器打开 `http://127.0.0.1:9091`

> 需指定 `-c C:\php\php.ini` 以加载 SQLite3 扩展（读取 opencode 数据库用）

---

## 功能

### 对话导入

| 方式 | 说明 |
|------|------|
| **手动粘贴** | 将 opencode 对话原始文本粘贴到左侧文本框，点「解析」 |
| **自动加载** | 点击「加载今日记录」自动读取 opencode.db 中的已完成会话 |
| **点击导入** | 在会话列表中点击某条记录，自动填入并解析 |

### 解析能力

| 解析项 | 说明 |
|--------|------|
| 对话轮次 | 按 `**我:**` / `**AI:**` 标记分段 |
| 代码块 | 提取 ``` 包裹的代码段，含语言标记 |
| 待办事项 | 提取 `- [ ]` / `- [x]` 格式的任务 |
| 关键句 | 自动识别含"结论/决定/注意/关键"等关键词的句子 |
| 礼貌用语过滤 | 自动移除纯礼貌性段落（谢谢、好的、请等） |

### 输出统计

解析后展示：对话轮次数、代码块数、待办事项数、关键句数。

---

## 数据来源

工具读取 opencode 的 SQLite 数据库：

```
C:\Users\Administrator\.local\share\opencode\opencode.db
```

| 表 | 用途 |
|----|------|
| `session` | 会话元数据（标题、时间、agent、model） |
| `message` | 消息记录（role、时间戳） |
| `part` | 消息内容（文本、代码、工具调用等） |

### 注意

- opencode 的会话在**结束后**才持久化到数据库
- 当天正在进行的会话需要手动粘贴
- 已完成的会话可通过「加载今日记录」自动导入

---

## 技术栈

| 层 | 选型 |
|----|------|
| 后端 | PHP 8.x + SQLite3 |
| 运行 | PHP 内置服务器 |
| 前端 | 原生 HTML + CSS + JS |
| 存储 | JSON 文件（`storage/entries/`）+ Markdown（`output/`）|

---

## 目录结构

```
chat-daily/
├── public/
│   ├── index.php              # 路由入口
│   ├── templates/main.html    # 页面
│   └── assets/
│       ├── app.css             # 样式
│       └── app.js              # 前端交互
├── src/
│   ├── index.php               # API 调度
│   ├── Parser.php              # 原始文本解析
│   └── OpencodeReader.php      # opencode.db 读取
├── storage/entries/            # 对话条目 JSON
├── output/                     # 日报 Markdown
└── README.md
```
