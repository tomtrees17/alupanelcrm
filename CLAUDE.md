# CLAUDE.md — AluPanel CRM 开发指引

> 面向 Claude Code 的项目约定。项目全貌与历史进度见 [docs/PROJECT_STATUS.md](docs/PROJECT_STATUS.md)（**每次 session 结束必须更新**，见文末第 6 节）。

## 1. 项目概述

面向**印尼市场**的铝塑板（ACP）销售 CRM / 轻量 ERP，服务真实公司 **PT ALUPANEL MULIA INDONESIA**。

- 仓库：https://github.com/tomtrees17/alupanelcrm （`main` 分支）
- 线上：https://www.alupanel.cc
- 界面语言：中文 / 印尼语（id）双语切换；货币印尼盾 IDR（Rp）
- 交流语言：**中文**

## 2. 技术栈

**刻意保持极简，不要引入新依赖。**

| 层 | 选型 |
|---|---|
| 后端 | 纯 PHP 8（`declare(strict_types=1)`）+ PDO，**无框架、无 Composer、无 vendor/** |
| 数据库 | SQLite（`data/crm.sqlite`，已 gitignore，首次访问自动建库+种子） |
| 前端 | 服务端渲染 PHP 模板 + 原生 JS/CSS，**无构建步骤**（不要引入 npm / 打包器） |
| 路由 | 单一前端控制器 `public/index.php?r=controller.action` |

SQLite 连接启用 **WAL + busy_timeout=5s + synchronous=NORMAL**（见 `Database::connect`），支持多人并发读写。运行时会产生 `crm.sqlite-wal/-shm`（已 gitignore）。

导出功能均为零依赖自研：Excel 用 `ZipArchive` 直写 OOXML（`app/Export.php`，无 zip 扩展时降级 CSV）、Word 用 MSO-HTML `.doc`（`app/Word.php`）。**不要为此引入 PhpSpreadsheet 等库。**

## 3. 目录结构

```
CLAUDE.md                   本文件（开发约定）
README.md                   面向使用者的功能说明
config.php                  公司抬头 / 银行 / 税率 / 品牌等可定制项
docs/PROJECT_STATUS.md      项目全貌与进度（跨 session 交接文档）

public/
  index.php                 前端控制器：鉴权 → 强制改密 → 模块权限 → 分发到 controllers/
  manifest.json             PWA
  assets/css/{app,print}.css
  assets/img/               app-icon.svg / logo-print.svg

app/
  bootstrap.php             启动装配（session 加固 → config → i18n/helpers/domain → PDO → Auth）
  Database.php              连接 + 建表 + 种子 + ensureSchema()（线上库自动升级，见 5.3）
  domain.php                业务逻辑：审批流状态机、库存增减与预留重算、单号生成、发票状态、terbilang
  helpers.php               视图辅助：e/url/redirect/input/idr/num/no_cjk、权限判定、各 tr_* 翻译
  i18n.php                  中印双语字典 + t() + current_lang()
  Export.php                Excel 导出   Word.php  Word 导出
  Auth.php                  会话鉴权     Csrf.php  CSRF 令牌
  controllers/              dashboard customers pipeline tasks finance orders inventory
                            approvals delivery users roles account auth lang

views/                      按模块分目录 + layout.php + print/ + word/ + errors/
database/schema.sql         表结构
database/seed_products.sql  269 个产品（由 tools/gen_products.php 从原型抽取，一次性）
tools/
  reset_password.php        CLI 重置账号密码（运维）
  backup_db.php             VACUUM INTO 一致快照 + 附件打包，滚动保留 14 份
data/                       运行时 SQLite + uploads/（gitignore，**在 web 根之外**）
backups/                    备份产物（gitignore）
```

## 4. 开发 / 部署流程

### 4.1 本地运行

需 PHP 8.0+（自带 `pdo_sqlite`）。本地路径 `~/projects/alupanelcrm`（macOS），在项目根目录：

```bash
php -S localhost:8000 -t public
```

打开 http://localhost:8000 。首次访问自动建库并写入示例数据（269 个产品 + 示例客户/订单/发票）。

**PHP 不在 PATH 时**（macOS 系统自带 PHP 已移除）用 `brew install php` 安装，或用绝对路径调用 `php` 二进制。

重置数据库：删除 `data/crm.sqlite`（连同 `-wal/-shm`），下次访问自动重建。

默认账号密码均 `admin123`，**首次登录强制改密**；账号清单见 PROJECT_STATUS 第 4 节。

### 4.2 发布：本地改 → push GitHub → 服务器 git pull

**这是唯一的部署方式，不要在服务器上直接改代码**（否则下次 `git pull` 会冲突）。

1. 本地改完，自测通过：

   ```bash
   git add -A && git commit -m "Short imperative summary" && git push origin main
   ```

2. SSH 登录服务器（**端口 22022**，不是默认 22）：

   ```bash
   ssh -p 22022 root@149.129.218.9
   ```

3. 拉取并修正属主：

   ```bash
   cd /www/wwwroot/www.alupanel.cc && git pull && chown -R www:www data && chmod -R 755 data
   ```

4. 打开一次网站，让 `Database::ensureSchema()` 跑完自动迁移（见 5.3）。

**服务器环境要点**（宝塔面板）：站点 `/www/wwwroot/www.alupanel.cc`，PHP 8.2（`pdo_sqlite`/`sqlite3` 已启用），**网站运行目录必须设为 `/public`**，`data/` 需 `www` 用户可写。宝塔 PHP CLI 路径随版本变（`ls /www/server/php/` 查实际版本号），当前为 `/www/server/php/82/bin/php`。

### 4.3 运维常用

```bash
# 重置任意账号密码（不带参数 = 列出所有账号）
/www/server/php/82/bin/php tools/reset_password.php admin@alupanel.local '新密码'
# 数据备份（宝塔计划任务每天执行）
/www/server/php/82/bin/php /www/wwwroot/www.alupanel.cc/tools/backup_db.php
```

改完密码/备份后记得 `chown -R www:www data`。详见 PROJECT_STATUS 6d / 6e。

## 5. 代码约定

### 5.1 通用

- 每个 PHP 文件顶部 `declare(strict_types=1);`（视图模板除外）。
- 缩进 4 空格；类名 `PascalCase`，函数/变量 `snake_case`（`app/*.php` 里的类是 `Auth`/`Csrf`/`Database`/`Export`/`Word`，其余全是全局函数）。
- 注释用**英文**写在代码里（简短、解释「为什么」而非「是什么」）；面向用户的字符串走 i18n 或直接中文。
- **不新增依赖**。需要什么能力先看 `helpers.php` / `domain.php` 里有没有现成的。

### 5.2 各层写法

**控制器**（`app/controllers/<name>.php`）：不是类，是被 `require` 的脚本，顶部用 `/** @var PDO $pdo */` 之类声明可用变量，主体是 `switch ($action)`，`default` 分支返回 404。可用变量：`$action`、`$pdo`、`$auth`、`$config`。

**写操作三件套**：`Csrf::verify()` → 校验/写库 → `flash(...)` + `redirect(...)`。**任何 POST 动作都必须先 `Csrf::verify()`**，表单里必须有 `<?= Csrf::field() ?>`。

**SQL**：一律 `$pdo->prepare(...)->execute([...])` 参数绑定；只有无用户输入的常量查询才用 `$pdo->query()`。表结构改动同时写进 `database/schema.sql` **和** `Database::ensureSchema()`。

**视图**（`views/<module>/<action>.php`）：顶行用 `/** @var ... */` 声明入参，`view('tasks.index', [...])` 渲染，默认套 `layout.php`（打印页传 `false`）。

- 输出用户数据一律 `<?= e($value) ?>`。
- 链接用 `url('customers.edit', ['id' => 5])`，不要手拼 query string。
- 界面文案用 `t('key')`，新文案同时补 `app/i18n.php` 的 **zh 和 id** 两份字典。
- 金额用 `idr()` / `idr_short()`，纯数字用 `num()`。
- **打印件与 Word 导出必须零中文**：经 `no_cjk()` 过滤（印尼客户看的单据），库内数据不动。

### 5.3 线上库自动升级（重要）

线上 SQLite 不跑手工迁移脚本。**任何表结构变更都要在 `Database::ensureSchema()` 里写成幂等升级**：`PRAGMA table_info` 判断列是否存在 → `ALTER TABLE ADD COLUMN` → 必要时回填历史数据。一次性数据迁移（如批量授权、标记必须改密）用 `app_meta` 表打标记，保证只跑一次且**不覆盖管理员后续的手动调整**。

### 5.4 权限（改任何列表/详情页前先读）

两层，缺一不可：

1. **模块级** `can_access($module)` —— `role_permissions` 表 + 管理员在「权限设置」里的角色×权限矩阵；前端控制器已统一拦截，导航按权限隐藏。
2. **记录级** —— `sees_only_own()`（销售只看自己 `submitter` 的订单、自己 `owner` 的客户）、`can_edit_inventory()`（仅 admin+warehouse 可改库存）、`approvals_sees_all()`（审批可见性）。列表 SQL 要加过滤，`find_*()` 要校验并 403。

**权限必须服务端二次校验**，隐藏按钮只是 UI，不算防护。

### 5.5 业务不变量（改动前务必确认没破坏）

- **价格含税**：订单单价是含税价，开票反算 pre-tax = 含税价 / 1.11；DPP = Subtotal × 11/12，VAT12% = DPP × 12%。已与公司真实模板核对一致，**不要随手改税率计算**。
- **库存可用量** = `stock − reserved`。下单预留、驳回/删除释放、出货实扣并释放，统一走 `recompute_reservations()`。
- **驳回 = 退回草稿**（订单与行政审批都是）：记录 reject_note/by/date、清空已有审批、释放预留，申请人改后可重新提交。
- **职责分离**：非 admin 不能审批自己提交的行政申请。

## 6. Session 结束前：更新 docs/PROJECT_STATUS.md

**每次 session 做完实质改动后，收尾必须更新 [docs/PROJECT_STATUS.md](docs/PROJECT_STATUS.md)**，它是跨 session / 跨上下文的交接文档，下一次开工靠它恢复全貌。

要写的内容：

1. 新增或改动的功能 —— 补进对应章节（第 5/6 节按模块，安全运维进 6c 系列），说清**业务规则和为什么这么定**，不只是「加了个按钮」。
2. 新的数据表/字段 —— 更新第 9 节数据模型，并确认 `ensureSchema()` 里有对应的幂等升级。
3. 新的运维步骤（CLI 工具、计划任务、服务器配置）—— 补进 6d/6e 或新章节，命令要可直接复制执行。
4. 第 12 节「可能的后续」 —— 划掉已完成项，补上本次发现的新待办和已知问题（含低危问题，写清触发条件）。
5. 第 10 节提交历史 —— 用 `git log --oneline` 的实际输出刷新（**这一节最容易忘，目前就是过时的**）。

改动小到只是文案微调也要至少确认一遍状态文档没被写旧。文档更新和代码改动放同一个 commit，或紧跟其后单独一个 commit。

## 7. Git 约定

- 直接在 `main` 上开发并推送（单人项目，无 PR 流程）。
- Commit message 用**英文**、祈使句、单行概括，与现有历史保持一致（如 `Approvals: HR review stage for trip / expense / leave requests`、`Fix invoice overdue detection to use the real date`）。模块前缀（`Approvals:` / `Invoice:` / `Finance:`）在改动集中于单一模块时使用。
- 不要提交 `data/`、`backups/`、`.claude/`、`*.sqlite*`（`.gitignore` 已覆盖）。
