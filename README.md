# AluPanel CRM

铝标识板（Alusignpanel）业务的轻量级 CRM 系统。后端为 **纯 PHP + PDO**，数据库为 **SQLite**，无需任何框架或 Composer 依赖。

## 功能模块

- **仪表盘** — 客户 / 产品 / 报价数量与进行中金额概览
- **客户 / 联系人** — 增删改查、搜索、客户报价历史
- **报价 / 订单** — 多行明细、自动计算小计/税额/合计、状态流转（草稿→已发送→已确认→已下单→已完成/已取消）、打印
- **产品目录** — 铝塑板 / 标牌等产品的 SKU、规格、单价
- **用户与登录** — 会话登录、角色（管理员 / 销售）、用户管理（仅管理员）
- 全站 CSRF 保护、密码哈希（`password_hash`）

## 运行要求

- PHP 8.0+（需启用 `pdo_sqlite` 扩展，PHP 默认自带）

## 启动

```bash
# 在项目根目录执行，使用 PHP 内置服务器
php -S localhost:8000 -t public
```

然后浏览器打开 <http://localhost:8000>

首次访问会自动创建并初始化 SQLite 数据库（`data/crm.sqlite`），并写入示例数据。

### 默认登录账号

| 邮箱 | 密码 |
| --- | --- |
| `admin@alupanel.local` | `admin123` |

> 上线前请登录后在「用户」中修改密码或新建账号。

## 目录结构

```
.
├── public/              # Web 根目录（仅此目录对外）
│   ├── index.php        # 前端控制器（路由：index.php?r=controller.action）
│   └── assets/css/app.css
├── app/
│   ├── bootstrap.php     # 启动：会话、配置、服务装配
│   ├── Database.php      # PDO 连接 + 建表 + 示例数据
│   ├── Auth.php          # 登录鉴权
│   ├── Csrf.php          # CSRF 令牌
│   ├── helpers.php       # 视图/URL/金额等辅助函数
│   └── controllers/      # 各模块控制器
├── views/                # 模板（按模块分目录）
├── database/schema.sql   # SQLite 表结构
├── data/                 # 运行时数据库（已 gitignore）
└── config.php            # 应用配置
```

## 重置数据库

删除 `data/crm.sqlite`，下次访问会重新初始化。
