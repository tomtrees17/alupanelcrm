# AluPanel CRM — 项目上下文摘要 / Handoff

> 本文档用于保存项目全貌，便于跨会话/上下文续接。最新进度以 `git log` 为准（已含：双语、打印模板、含税发票、库存预留防超卖、角色与多级权限、记录级权限、客户归属分配）。

## 1. 项目概述

- **名称**：AluPanel CRM（侧边栏 logo 显示 `AluPanelCRM`）
- **业务**：铝塑板（ACP）销售 CRM / 轻量 ERP，面向**印尼市场**
- **真实公司**：PT ALUPANEL MULIA INDONESIA
- **来源**：按规划原型 `crm-system_25.html`（NexusCRM）实现（该原型文件在早期的 Windows 开发机上，未进仓库）
- **仓库**：https://github.com/tomtrees17/alupanelcrm （main 分支，**private**，2026-08-28 由公开转私有）
- **本地路径**：`~/projects/alupanelcrm`（macOS；早期在 Windows `D:\AluPanelCRM`）

## 2. 技术栈

- 后端：**纯 PHP + PDO**（无框架、无 Composer）
- 数据库：**SQLite**（`data/crm.sqlite`，已 gitignore，首次访问自动建库+示例数据）。连接启用 **WAL + busy_timeout=5s + synchronous=NORMAL**，支持多人并发读写不锁库（`Database::connect`）。会生成 `crm.sqlite-wal/-shm` 临时文件（已 gitignore）
- 前端：服务端渲染 PHP 模板 + 原生 JS/CSS，无构建步骤
- 路由：前端控制器 `public/index.php?r=controller.action`
- 货币：印尼盾 IDR（Rp）
- 语言：中文 / 印尼语（id）可切换

## 3. 运行方式

**本地**（macOS，路径 `~/projects/alupanelcrm`）：
```
php -S localhost:8000 -t public
```
打开 http://localhost:8000 。PHP 不在 PATH 时用 `brew install php` 安装或用绝对路径调用。重置数据：删除 `data/crm.sqlite`（连同 `-wal/-shm`；被沙箱保护时用 PHP `unlink` 删）。

**服务器**（宝塔，已部署）：
- 域名/站点：`www.alupanel.cc`，路径 `/www/wwwroot/www.alupanel.cc`
- SSH：`ssh -p 22022 root@149.129.218.9`（**端口 22022，非默认 22**）
- PHP 8.2（已确认 `pdo_sqlite`/`sqlite3` 启用）
- **网站运行目录必须设为 `/public`**
- `data/` 目录需 `www` 用户可写：`chown -R www:www data && chmod -R 755 data`
- 更新代码：`cd /www/wwwroot/www.alupanel.cc && git pull`（发布流程见 CLAUDE.md 第 4 节：本地改 → push GitHub → 服务器 git pull）

## 4. 默认账号（初始密码均 `admin123`，**首次登录强制改密**，见 6c）

| 邮箱 | 角色 |
|---|---|
| admin@alupanel.local | 管理员 admin |
| mutiara@alupanel.local | 经理 manager |
| sari@alupanel.local | 主管 supervisor |
| ahmad@alupanel.local | 销售 sales |
| joko@alupanel.local | 仓库 warehouse |
| rina@alupanel.local | 人事 hr |

## 5. 模块（8 + 用户）

数据看板、客户管理、销售漏斗(deals)、任务提醒、财务管理(invoices)、订单审批(orders)、库存管理(products)、**行政审批(approvals)** + 用户管理。

## 6. 关键业务逻辑

**订单四级审批流**：销售→主管(supervisor)→经理(manager)→仓库(warehouse)。
- 状态：`draft / pending_sup / pending_mgr / pending_wh / approved`（旧 rejected 仅历史数据）
- 新建订单可**保存草稿**或**提交审批**（表单两按钮 do=draft|submit）。草稿/被驳回订单可由本人或 admin **编辑**(`order_editable()`)；草稿不占库存，提交时才校验可用库存并预留
- 仅对应角色（或 admin）能在该阶段审批（`order_action_role()`）。**驳回 = 退回草稿**：记录 `reject_note/by/date`、清空已有审批、释放预留，销售改后可重新提交
- 仓库「确认出货」(`fulfill_order`) 自动：扣库存(out_auto) + 生成送货单DO + 生成发票
- `save_order()` 统一处理建/改；`submit_order()` 草稿→pending_sup；`orders.reject_note/by/date` 字段由 ensureSchema 自动加

**税务（印尼 2025，价格含税）**：
- 订单输入单价为**含税价**；开票时反算 pre-tax = 含税价 / 1.11
- 发票：Subtotal(税前) + VAT12% = 含税总额（不再额外加税）
- DPP = Subtotal × 11/12，VAT12% = DPP × 12% = Subtotal × 11%，Total = Subtotal + VAT
- 发票号格式 `N - AMI - INV - MM - YY`
- 已与公司真实模板核对一致（235.000×3 含税 → 单价 211.711,71 / Subtotal 714.262 / VAT 78.569 / Total 792.831）

**库存预留防超卖**：
- `products.reserved` 列，**可用 = stock − reserved**
- 下单即预留(`recompute_reservations`，按所有 pending 订单求和)；驳回/删除释放；出货实扣并释放
- 新建订单按**可用库存**校验（客户端标红阻止提交 + 服务端拒绝）；每级审批再按物理库存校验
- `order_items.product_id` 已记录；线上旧库由 `Database::ensureSchema()` 自动加列+回填+重算

**打印**：发票(`print/invoice.php`，公司抬头+Bill To+DPP/VAT+双银行ICBC/BCA+签字+terbilang金额大写) 与送货单(`print/do.php`，SURAT JALAN)，A4 样式 `public/assets/css/print.css`。抬头用 **ALUSIGNPANEL logo 图片**：`views/print/invoice.php` 按 `logo-print.png → .svg → .jpg` 顺序查找 `public/assets/img/`，命中即用。真实 PNG（524×112）**原先只存在于服务器上、未进 git 也不在备份范围**，2026-08-28 已提交进仓库。打印时 `no_cjk()` 剔除所有中文（规格如 `4.0*0.15抗刮木纹` 印成 `4.0*0.15`，单位「张」→Unit,库内数据不动）。

**Word 导出（可手改再打）**：`finance.word` / `delivery.word` 生成可编辑 Word 文档（`app/Word.php`，MSO-HTML `.doc`，零依赖，Word/WPS 直接打开编辑）。权限 `can_word_export()`＝admin + 仓库(can_edit_inventory) + 财务(can_access('finance'))；发票 Word 还需过 finance 模块路由（warehouse 只能导送货单）。入口：发票详情/打印页、送货单列表/打印页的「导出 Word」按钮。内容同打印版（含 terbilang、双银行、签名位），同样零中文。

## 6b. 角色与访问控制（重点）

**角色**（`all_roles()`，共 9 个）：admin、manager(经理)、finance_manager(财务经理)、ops_supervisor(运营主管)、supervisor(主管)、sales(销售员)、warehouse(仓库/库存管理员)、hr(人力资源)、clerk(文员)。i18n 含中印双语标签。

**① 模块级权限**（`role_permissions` 表 + `can_access($module)`）
- bootstrap 载入 `$GLOBALS['permissions']`；前端控制器(`public/index.php`)拦截越权模块、导航按权限隐藏。
- 可配置模块：customers/pipeline/tasks/finance/orders/inventory；另有视图权限 `performance`(看板全员业绩) 和 `export`(导出 Excel)。
- 默认：sales/supervisor/warehouse/hr/clerk **无 finance**；`export` 默认仅 manager；admin 始终全权。
- 管理员在 **权限设置(roles.index)** 用「角色×权限」勾选矩阵实时配置。
- 看板财务卡片(营收/逾期)按 `can_access('finance')` 显示；全员业绩卡按 `can_access('performance')`，无此权限者改看「我的业绩」(仅本人 submitter 的订单)。

**② 记录级权限**（helpers：`can_edit_inventory()` / `sees_only_own()` / `own_name()`）
- **库存**：增删改产品/调库存仅 admin + warehouse；其他有库存权限者**只读**（控制器拦写操作 + 列表隐藏按钮）。
- **销售(sales)**：只看/改**自己的订单**(`orders.submitter == 本人姓名`，列表/详情/计数/看板最近订单均过滤、`find_order` 校验)与**自己的客户**(`customers.owner` 字段，列表/详情过滤，`find_customer` 校验)；下单时客户下拉也只列自己的客户。
- **客户归属**：`customers.owner` 在新建时记为创建者；管理员/经理可在客户表单的「负责销售」下拉**改派**，客户列表显示归属列；销售本人不可改派。

**④ Excel 导出**（`app/Export.php`，`can_export()`=`can_access('export')`）：库存(inventory.export)、财务报表(finance.export)、客户列表(customers.export) 三个列表页右上「导出 Excel」按钮（仅有 export 权限者可见，动作服务端二次校验）。无依赖生成真 `.xlsx`(ZipArchive 写 OOXML)；服务器无 zip 扩展时自动降级带 BOM 的 CSV。数字列写为数值型。

**③ 线上库自动升级**（`Database::ensureSchema()` + `app_meta` 标记一次性迁移）：自动建/补 role_permissions、加 customers.owner 并按历史订单回填、加 products.reserved、补 performance 与新角色默认权限——均不覆盖管理员后续手动调整。

## 6c. 安全加固（2026-06-17）

**① 会话 Cookie 加固**（`app/bootstrap.php`）：`session_start()` 前设置 HttpOnly + SameSite=Lax + Secure（自动识别 HTTPS / 反代 `X-Forwarded-Proto`）+ 命名 `ALUPANELSESS` + `use_strict_mode`/`use_only_cookies`。

**② 登录防爆破**（`app/domain.php` 的 `login_*` 助手 + `login_attempts` 表）：按客户端 IP 滑动窗口限速——15 分钟内失败 8 次即锁定（`LOGIN_MAX_ATTEMPTS`/`LOGIN_WINDOW_SECONDS`），提示剩余分钟数；登录成功清零、旧记录自动清理。登录页不再泄露默认账号/密码（移除 `admin123` 提示与预填邮箱）。

**③ 强制改默认密码**（`users.must_change_password` 列 + 前端控制器 `account` 模块）：仍用默认密码 `admin123` 的账号首次登录被强制跳转 `account.password` 改密（≥8 位且不同于旧密码），改完才放行其它页面（豁免 `account.*`/`auth.logout`/`lang.set`）。自助改密入口在侧边栏用户卡（全员可用）。线上库 `git pull` 后由 `ensureSchema` 自动加列，并把所有仍用 `admin123` 的账号标记为必须改密（一次性迁移 `pwd_policy_v1`；已本地验证迁移命中 6/6 且幂等、端到端 8 项全过）。

## 6c2. 行政审批（出差 / 报销 / 请假）

- **表** `admin_requests`，**模块键** `approvals`（进权限矩阵，默认所有角色可用；admin 始终全权）。控制器 `app/controllers/approvals.php`，视图 `views/approvals/{index,form,show}.php`。
- **类型** `request_types()`：trip(出差 BT-)、expense(报销 EX-)、leave(请假 LV-)、**payment(付款申请 PY-)**；单号 `next_request_no()` 格式 `BT-YYYY-NNN`。类别/假种/付款用途存 `category` 列（canonical 中文值，`tr_req_cat()` 翻译显示）。付款申请：`destination` 列复用为**收款方**，必填 收款方+金额；`ref_no` 列存**关联单号**（订单/发票/其他申请，表单 datalist 提示最近单号，详情页 `resolve_ref_link()` 自动解析为可点链接）。报销单也可填关联单号。
- **流程**：draft →（提交）→【出差/报销/请假 `request_needs_hr()`】`pending_hr`(**人事审批**,hr_note/approver/date) → `pending_mgr`(经理审批) →【报销&付款 `request_needs_finance()`】`pending_fin`(财务经理确认支付) → approved。付款申请不走人事,直接 pending_mgr(`request_first_stage()`)。**驳回=退回草稿**（记录 reject_note/by/date、清空已有审批含 hr_*），申请人改后重新提交。角色路由 `request_action_role()`：pending_hr→hr、pending_mgr→manager、pending_fin→finance_manager（admin 任意阶段可操作）。线上库 ensureSchema 自动补 hr_* 列;**线上需有 hr 角色用户**处理人事阶段(否则只能 admin 代批),种子已含 rina@alupanel.local。
- **职责分离**：非 admin **不能审批/驳回自己提交的申请**（`request_can_act()` 校验 applicant≠本人；否则经理/财务经理会给自己签批）。admin 作为最高权限例外可自签（小企业老板自己报销的兜底）。
- 已知低危：表单按类型切换字段依赖 JS（禁用隐藏区 input 防重名提交）；禁用 JS 时 `start_date/amount` 重名会串值，导致报销存不上。内部系统 JS 常开,暂未改；如需彻底修则给各类型字段起不同 name。
- **表单**：类型切换显隐字段（JS 同时 disable 隐藏 input 防重名提交）；草稿/提交两按钮 do=draft|submit；服务端按类型校验必填（trip:目的地+开始日；expense:金额>0+费用日期；leave:起止日期）。
- **记录级可见性** `approvals_sees_all()`：admin/manager/finance_manager/hr 看全部；其他角色只看/只能访问**自己的申请**（`find_request` 403+重定向）。草稿仅申请人/admin 可编辑/删除（admin 可删任意）。
- **附件**（`request_files` 表 + `data/uploads/req/<id>/`,在 web 根之外）：表单可多选上传 图片(jpg/png/webp)/PDF,单个 ≤8MB;**魔数检测**文件真实类型(`sniff_upload_mime()`,不依赖 fileinfo 扩展),伪装扩展名拒收;存随机文件名。下载走 `approvals.file&fid=`(经 `find_request` 可见性校验后 readfile 流式输出,外网无法直连文件)。草稿期申请人/admin 可删(`approvals.delfile`)。服务器 PHP `upload_max_filesize` 需 ≥8M(宝塔默认 50M,一般无需动)。
- **线上升级**：`ensureSchema` 自动建表 + 一次性给所有角色授 `approvals` 权限（app_meta `perm_approvals`）。侧边栏「行政审批」带待审批数徽标。
- 注意：pending_fin 需要有 `finance_manager` 角色用户处理（或 admin）；种子里没有该角色用户，线上请在用户管理里指派。

## 6d. 忘记密码 / 重置（运维）

服务器上用 CLI 工具重置任意账号密码（`tools/reset_password.php`，绕过登录直接改库）：
```bash
cd /www/wwwroot/www.alupanel.cc && git pull
/www/server/php/82/bin/php tools/reset_password.php                              # 不带参数 = 列出所有账号
/www/server/php/82/bin/php tools/reset_password.php admin@alupanel.local '新密码'   # ≥8 位
chown -R www:www data && chmod -R 755 data                                       # 修正属主（必做）
```
- 重置 `password_hash` 并清 `must_change_password`，新密码当场生效。
- 对 `must_change_password` 列做**存在性判断**，故线上库尚未经 web 迁移（刚 git pull、还没人访问网站）时也能用（否则会报 `no such column`）。
- **应急免 git pull 版**（只改密码、不依赖新列）：
  `/www/server/php/82/bin/php -r '$p=new PDO("sqlite:data/crm.sqlite");$p->prepare("UPDATE users SET password_hash=? WHERE email=?")->execute([password_hash("新密码",PASSWORD_DEFAULT),"admin@alupanel.local"]);echo "ok\n";'`
- 坑：宝塔 PHP CLI 路径随版本变（`ls /www/server/php/` 查实际版本号）；密码用单引号包住更安全；两条命令分行别粘成一行。

## 6e. 数据备份（运维）

`tools/backup_db.php`：用 `VACUUM INTO` 生成**一致快照**到 `backups/`（WAL 下也安全,不需要 wal/shm),自动滚动保留最近 **14** 份。`backups/` 已 gitignore（不进仓库,也不在 web 根 `public/` 下,外网访问不到）。
- 宝塔「计划任务 → Shell 脚本」每天执行(PHP 路径按版本调整):
  ```
  /www/server/php/82/bin/php /www/wwwroot/www.alupanel.cc/tools/backup_db.php
  ```
- 恢复:停站 → 用某份快照覆盖 `data/crm.sqlite`(并删掉 `-wal/-shm`)→ `chown -R www:www data`。
- 进阶(建议):把 `backups/` 定期同步到异地/对象存储,防整机故障。

## 6f. 服务器访问与安全审计（2026-08-28）

**仓库已转为 private**。服务器 git remote 相应从 HTTPS 改为 **SSH**（`git@github.com:tomtrees17/alupanelcrm.git`）——private 仓库走 HTTPS 会卡在认证上，**不要改回去**。认证用 `/root/.ssh/id_ed25519`，该公钥已注册在 GitHub 账号 `tomtrees17` 的 SSH keys 下（不是 deploy key，所以服务器实际拥有该账号**所有仓库**的访问权；想收紧就从账号 SSH keys 移除、改加为本仓库的 read-only deploy key）。

**SSH 访问**：`ssh -p 22022 root@149.129.218.9`，本机 `~/.ssh/id_ed25519`（`yuan-imac`）已在服务器 authorized_keys 中，免密。主机指纹 ED25519 `SHA256:Ub6Xr8wg7Bg1y6ilzPzVfQsVxU8kEOHL0GDkOxK3hkw`。**退路**：阿里云控制台 VNC 远程连接可用（已验证），SSH 配置改坏时靠它救场。

**审计结论：无入侵迹象。** authorized_keys 里两把钥匙——`SHA256:9GE/...`(服务器自己的 id_ed25519.pub，setup 时自我授权，无害) 和 `SHA256:ndFF...`(yuan-imac)，没有第三方公钥；可登录 shell 的账号只有 root；所有成功登录都来自本人 IP（`101.0.4.x` / `100.104.x.x` CGNAT 移动网络）。

**当前风险（截至 2026-08-28 未处理）**：
| 项 | 现状 | 风险 |
|---|---|---|
| SSH 密码认证 | `PasswordAuthentication yes` + `PermitRootLogin yes` | **高**——自 6/13 起 574,160 次失败登录（约 7,700 次/天），**fail2ban 未安装** |
| 宝塔面板 8888 / phpMyAdmin 888 | firewalld 放行，对公网开放 | 中高——宝塔面板历史有未授权访问漏洞 |
| MySQL 3306 | 监听 `0.0.0.0`（无 bind-address），但 **firewalld 未放行** | 低——外网连不到；且本项目用 SQLite，该 MySQL 疑似闲置 |
| FTP 20/21 | firewalld 放行 | 低——明文协议，未在用可关 |

firewalld 放行清单：`20, 21, 22, 80, 443, 888, 8888, 22022, 37775, 39000-40000`（`22/tcp` 是残留，sshd 只听 22022）。阿里云**安全组**规则是 firewalld 之外的第二道防火墙，服务器内部看不到，需在控制台确认。

**加固待办（关闭密码认证的操作步骤）**——改前务必先确认 VNC 能进：
```bash
cp -a /etc/ssh/sshd_config /root/sshd_config.bak.$(date +%Y%m%d)
sed -i 's/^PermitRootLogin yes$/PermitRootLogin prohibit-password/; s/^PasswordAuthentication yes$/PasswordAuthentication no/' /etc/ssh/sshd_config
sshd -t && systemctl reload sshd          # reload 不断开现有连接
sshd -T | grep -E 'permitrootlogin|passwordauthentication|kbdinteractive'
```
生效值应为 `permitrootlogin prohibit-password` / `passwordauthentication no` / `kbdinteractiveauthentication no`（**最后一项必须是 no**，否则 PAM 会绕过 `prohibit-password` 让密码登录复活）。**改完先别关当前会话**，另开终端验证 `ssh -p 22022 root@149.129.218.9 hostname` 能通再关。回滚：`cp -a /root/sshd_config.bak.<日期> /etc/ssh/sshd_config && systemctl reload sshd`。

配置生效点在 `/etc/ssh/sshd_config` 第 132/133 行（宝塔追加）；`Include /etc/ssh/sshd_config.d/*.conf` 在第 15 行，那两个 redhat 默认文件没覆盖这两项，也没有 `Match` 块。直接改主配置是为了和宝塔面板的 SSH 管理界面保持一致，避免「面板显示开着、实际关着」。

## 7. 目录结构

```
CLAUDE.md                   开发约定（技术栈/目录/部署流程/代码约定，给 Claude Code 和新人看）
public/index.php            前端控制器（路由）
public/assets/css/{app,print}.css
public/assets/img/           app-icon.svg(PWA) / logo-print.png + .svg(发票抬头，png 优先)
app/
  bootstrap.php             启动装配（session, config, i18n, helpers, domain, Database, Auth, Csrf）
  Database.php              连接+建表+种子+ensureSchema(线上升级)
  domain.php                业务逻辑：库存增减、预留重算、可用/库存校验、单号生成、发票状态、terbilang
  helpers.php               视图辅助：e/url/redirect/idr/num/各label与tr_*翻译
  i18n.php                  中印双语字典 + t() + current_lang()
  Export.php                无依赖 Excel 导出（.xlsx via ZipArchive，CSV 兜底）
  Auth.php / Csrf.php
  controllers/              dashboard customers pipeline tasks finance orders inventory approvals delivery users roles account auth lang
views/                      按模块分目录 + layout.php + print/ + errors/（account/password.php 改密页）
database/schema.sql         表结构
database/seed_products.sql  269 个产品（由 tools/gen_products.php 从原型抽取）
tools/reset_password.php    CLI 重置账号密码（运维，见 6d）
tools/backup_db.php         DB 快照 + 附件打包备份（运维，见 6e）
data/                       运行时 SQLite + uploads/（gitignore，在 web 根之外）
config.php                  应用与公司配置
```

**服务器上的未跟踪文件**（宝塔生成，别提交也别删）：`.htaccess`、`404.html`、`index.html`、`public/.user.ini`、`public/.well-known/`。除此之外 `git status` 应该是干净的——**服务器上永远不要直接改代码**。

## 8. config.php 可定制项

`company_full / company_addr / company_npwp / banks[ICBC,BCA] / signer_name / signer_title`（发票抬头）、`ppn_rate=11`、`currency=Rp`、`brand=AluPanel`。

## 9. 数据模型（表）

users(+must_change_password), customers, deals, tasks, products(+reserved), stock_txn, orders, order_items(+product_id), delivery_orders, invoices, invoice_items, payments, admin_requests(行政审批), role_permissions, app_meta, login_attempts。

## 10. 提交历史（main）

最近 30 条（共 64 条，完整历史用 `git log --oneline`）：

```
af4bc9f Docs: server pulls over SSH remote now that the repo is private
0a90600 Docs: real SSH endpoint and macOS local path in CLAUDE.md / PROJECT_STATUS
1a39556 Add CLAUDE.md with stack, layout, deploy flow and code conventions
beaf52c Finance: editable invoice number on the invoice page
28915e0 Invoice: ALUSIGNPANEL logo in the letterhead (print-only asset)
bfe2816 Invoice: drop company name from the letterhead (address box + title only)
b239ab6 Invoice signature block: title only (no company name, no signer name)
0c3a755 Backup: bundle DB snapshot + attachments (data/uploads) into a rotated zip
7e3cb15 Approvals: HR review stage for trip / expense / leave requests
168488d Approvals: file attachments (images/PDF) on requests
c031a63 Approvals: add payment-request type (PY-) with linked-document reference
ca77ca1 Add editable Word export for invoices & delivery orders (finance/warehouse)
bee5277 Strip Chinese from printed invoice & delivery order
ec04fe7 Order form picker: drop size from the product label
35d888d Order form: show full product designation in picker (bilingual name + size), searchable in Chinese
297b2d3 Mobile: make order-form product picker readable (wrap items, wider dropdown)
53683f4 Drop ALUSIGNPANEL logo image; sidebar uses the AluPanelCRM text mark
53ac76f Match logo colors to brand image: red A, blue LUSIGNPANE, black L
46397bf Add responsive mobile layout + installable PWA
9df2a80 Add rotated SQLite backup tool (tools/backup_db.php)
2436a4f Fix invoice overdue detection to use the real date
d28a7e1 Approvals: forbid self-approval (separation of duties)
d608637 Customers: add city/owner filters and potential-value sorting
84ec3c5 Dashboard sales trend: split into 已成交 (approved orders) & 已回款 (payments)
da101dc Dashboard: add monthly & weekly sales trend charts
3c97311 Clarify on the permissions page that inventory edits are admin/warehouse only
f971e9d Add administrative approvals module (business trip / expense / leave)
8aad2c8 Delivery order: drop logo, keep company name in letterhead
d62d1eb Invoice header: use company name instead of logo image
8bca646 Show ALUSIGNPANEL logo in sidebar + fix logo.svg color split
```

## 11. 用户偏好

中文交流；尽量少打断/少让用户授权，按合理默认自主推进。

## 12. 可能的后续

发票明细规格显示格式微调、库存"有预留"筛选、订单占用库存视图、预留超时自动释放、双语未覆盖的零散文案补全。（~~真实 logo.png 上传~~ 已完成，见 6 打印一节）

**安全/运维**：已完成——cookie 加固 / 强制改密 / 登录限速 / 审批职责分离 / **数据备份**(`tools/backup_db.php` 见 6e) / 修复财务逾期判定写死日期(`finance.php` 现用 `date('Y-m-d')`) / **响应式移动端布局 + PWA** / **仓库转 private + 服务器改走 SSH remote**(见 6f) / **服务器安全审计**(无入侵迹象，见 6f)。

**待办（按优先级）**：
1. **关闭 SSH 密码认证 + 装 fail2ban** —— 操作步骤见 6f，是当前最高的实际风险（7,700 次爆破/天）
2. **审计日志**（谁改了什么）—— 多角色 + 审批流 + 财务数据，出争议无从追溯
3. **金额改整数分** —— 现用 REAL 存，含税反算(÷1.11)有累积误差；涉及数据迁移，越晚成本越高
4. **列表分页** —— 订单/发票持续增长，目前全量渲染
5. **WhatsApp 一键联系 / 自动通知** —— 印尼市场刚需（审批到达、发票逾期）
6. 宝塔面板 8888 收口、停用闲置 MySQL/FTP（见 6f）
7. 备份异地同步、看板日期范围、自动化测试
8. GitHub 密钥收紧：服务器用的是账号级 SSH key（可访问所有仓库），可改为本仓库 read-only deploy key（见 6f）

**响应式 + PWA**：`app.css` 末尾 `@media (max-width:768px)` 把侧边栏变滑出抽屉(`layout.php` 顶栏加 `☰` `#navToggle` + `#navBackdrop` + 底部切换脚本;`.nav-toggle`/`.sidebar-backdrop` 默认隐藏),栅格(stats/grid-2/detail/form-row)、搜索栏、页头在窄屏自适应堆叠。PWA:`public/manifest.json`(display=standalone,theme #00a884)+ `public/assets/img/app-icon.svg`(青底白 A)+ `layout.php`/`login.php` head 里的 manifest/theme-color/apple-touch-icon,可「添加到主屏幕」。**未做 service worker**(避免缓存旧页;CRM 本就依赖服务器)。iOS 主屏图标想更精细可加 180×180 png 覆盖。
