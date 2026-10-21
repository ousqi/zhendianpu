# 臻点铺 MCP 记账平台

臻点铺是一个基于 Laravel 13、Filament 5 和 Laravel MCP 构建的简易记账平台。店主可在后台查看与维护账目，也可将 MCP 服务连接到支持 MCP 的 AI 客户端，以自然语言完成日常记账、查询、汇总、修改和撤销。

> 本文中的 `https://mcp.example.com` 是占位域名。部署时必须替换为实际的 HTTPS 域名；不要在文档、聊天或代码仓库中填写真实令牌、密码或数据库凭据。

## 功能概览

- **收支记账**：记录已发生的收入和支出，金额精确到分，日期以北京时间为准。
- **多账本管理**：分别维护经营、个人或其他账本；账本内的数据、分类、账户和统计彼此隔离。
- **两级分类与多账户管理**：每个账本独立维护一级/二级收入与支出分类，以及现金、银行卡等账户、默认账户和期初余额。
- **账户间转账**：记录资金在账户之间的移动；转账不会被算作收入或支出。
- **退款记录**：退款独立留存审计历史，关联一笔原收支；按退款发生日冲减原分类的收入或支出，并影响所选退款到账账户。
- **查询与汇总**：按日期、分类、方向、账户和关键字筛选账目，查看收入、支出、期间结余和分类汇总。
- **可审计的更正**：账目和转账采用修订版本控制；撤销保留历史记录，不物理删除。
- **后台管理**：通过 `/admin` 登录，在「记账 → 账本管理」中创建、编辑、归档和切换当前账本，再管理该账本的账户、分类、收支和转账。
- **MCP 服务**：提供受 OAuth 保护的「臻点铺」记账服务和「平台状态」查询服务。

## 系统要求

- Linux 服务器（推荐 Ubuntu LTS）
- PHP 8.4 及扩展：`bcmath`、`ctype`、`curl`、`dom`、`fileinfo`、`mbstring`、`openssl`、`pdo_mysql`、`tokenizer`、`xml`
- Composer 2、Node.js 22 LTS（用于构建前端资源）
- MySQL 8.0+ 或 MariaDB 10.6+
- Nginx（或等效 HTTPS Web 服务器）
- 一个已配置 DNS 的公网 HTTPS 域名

生产环境应使用专用的低权限数据库账号，并让 Web 根目录指向项目的 `public/` 目录。

## 首次部署

以下命令以项目安装目录 `/var/www/zhendianpu`、运行用户 `www-data`、域名 `mcp.example.com` 为例。请按实际环境替换。

### 1. 获取代码并安装依赖

```bash
git clone <仓库地址> /var/www/zhendianpu
cd /var/www/zhendianpu

composer install --no-dev --optimize-autoloader --no-interaction
npm install
npm run build
```

不要提交或复制开发环境的 `.env` 文件。请从 `.env.example` 创建生产配置：

```bash
cp .env.example .env
php artisan key:generate --force
```

### 2. 配置生产环境变量

在 `.env` 中至少设置以下值：

```dotenv
APP_NAME="臻点铺"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mcp.example.com

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=zhendianpu
DB_USERNAME=zhendianpu
DB_PASSWORD=<使用强随机密码>

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
```

`APP_URL` 必须与用户访问和 MCP 授权时使用的公开 HTTPS 地址完全一致（包含协议，且不带末尾斜线）。本项目的 MCP OAuth 元数据和授权服务器地址以此值生成。

### 3. 建立数据库并执行迁移

先在 MySQL 中创建数据库及仅具备该数据库权限的账号，然后执行：

```bash
php artisan migrate --force
```

这会创建应用、会话、缓存、队列、OAuth 和记账所需的数据表。上线已有历史数据的环境前，请先完成可恢复备份并在预发布环境演练；不要使用 `migrate:fresh`，它会删除数据。

### 4. 生成并保护 Passport OAuth 密钥

MCP 授权依赖 Laravel Passport 的 RSA 密钥。首次部署、且 `storage/oauth-private.key` 与 `storage/oauth-public.key` 都不存在时生成一次：

```bash
php artisan passport:keys
```

使用 Docker Compose 的生产环境应在 `app` 容器内执行（以下容器内项目路径仅为示例）：

```bash
docker compose exec -T -w /var/www/html/zhendianpu app php artisan passport:keys --no-interaction
```

这两个密钥必须作为部署密钥持久化保存，并在每次发布时保留同一对密钥；不要提交到仓库，也不要将其写入镜像。应用的 PHP 运行用户必须可读取它们。若密钥丢失或重新生成，所有现有的 MCP OAuth Access Token 和 Refresh Token 都会失效，客户端必须重新授权。

密钥已存在时，**不要**使用 `passport:keys --force`。只有确认旧密钥无法恢复并已接受令牌失效影响时，才可生成新密钥。

### 5. 初始化店主、权限和默认分类

首次部署需创建店主账号并授予服务访问权：

```bash
php artisan platform:initialize \
  --email=owner@example.com \
  --username=owner \
  --name='店主'
```

该命令会交互式要求设置密码，并创建默认收支分类。非交互自动化时可改用 `--generate-password`，并立即通过受控密码管理渠道保存命令输出的初始密码：

```bash
php artisan platform:initialize \
  --email=owner@example.com \
  --username=owner \
  --name='店主' \
  --generate-password \
  --no-interaction
```

重复执行不会覆盖已有账号密码或业务数据。首次登录后请立即修改初始密码，并在后台创建实际使用的账户及默认账户。

### 6. 配置目录权限和 Nginx

Laravel 必须能写入 `storage/` 和 `bootstrap/cache/`：

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```

以下 Nginx 配置仅作参考；证书路径按证书工具实际生成的位置调整：

```nginx
server {
    listen 80;
    server_name mcp.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name mcp.example.com;

    root /var/www/zhendianpu/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/mcp.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/mcp.example.com/privkey.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

启用站点、通过 `nginx -t` 检查配置后重载 Nginx。请使用受信任 CA 签发的证书；MCP OAuth 不应部署在 HTTP 或自签名证书的公网端点上。

### 7. 缓存、队列与定时任务

```bash
php artisan optimize
php artisan queue:restart
```

若应用产生队列任务，请用 systemd、Supervisor 或同类进程管理器常驻运行：

```ini
# /etc/systemd/system/zhendianpu-queue.service（示例）
[Service]
User=www-data
WorkingDirectory=/var/www/zhendianpu
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
```

配置并启动后，确认队列进程状态。若以后添加了 Laravel 定时任务，再额外配置每分钟执行一次 `php artisan schedule:run` 的系统 cron。

### 8. 上线检查

```bash
php artisan about
php artisan route:list --path=mcp
curl -I https://mcp.example.com/
```

应能访问后台登录页（`https://mcp.example.com/admin`），并在路由列表中看到 `/mcp/zhendianpu` 与 `/mcp/system-status`。不要以 `curl` 或日志输出的方式泄露 OAuth Token。

## 后续发布流程

每次发布前先备份数据库，并在预发布环境验证。账本改造迁移会保留原有记账数据并将其关联到「经营账本」，但属于前向迁移；不要使用 `migrate:rollback` 作为回退手段，出现问题应使用已验证的数据库备份恢复或进行前向修复。

应用目录下执行：

```bash
git fetch --all --prune
git checkout <已验证的标签或提交>
composer install --no-dev --optimize-autoloader --no-interaction
npm install
npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
```

部署后检查后台登录、「记账 → 账本管理」、经营账历史数据、关键账目查询和 MCP 授权流程。若迁移涉及历史业务表，请先完成完整、可恢复的备份和变更评审；切勿为了回滚而直接删除生产表。

## MCP 连接信息

在 MCP 客户端的「服务器 URL」或「MCP 链接」输入框中，填写：

```text
https://<你的生产域名>/mcp/zhendianpu
```

例如生产域名为 `mcp.example.com` 时，填写 `https://mcp.example.com/mcp/zhendianpu`。不要填写 `/oauth/authorize`、`/oauth/token` 或后台 `/admin` 地址；客户端会根据 MCP 地址自动发现并发起 OAuth 授权。

| 服务 | MCP 地址 | 用途 | 权限 |
|---|---|---|---|
| 臻点铺 | `https://mcp.example.com/mcp/zhendianpu` | 收支、账户与转账记账 | `bookkeeping.read`、`bookkeeping.write` |
| 平台状态 | `https://mcp.example.com/mcp/system-status` | 查询服务状态和北京时间 | `status.read` |

两个端点均使用 Streamable HTTP MCP 与 OAuth 授权。客户端会通过动态注册和授权流程获取仅用于 MCP 的访问令牌；令牌不可复制到聊天内容、截图或工单中。

### 在支持 MCP 的客户端中连接

1. 在客户端的 MCP/连接器设置中添加自定义 MCP 服务。
2. 填入「臻点铺」的完整 MCP 地址，例如 `https://mcp.example.com/mcp/zhendianpu`。
3. 选择连接并在浏览器中使用臻点铺账号登录，完成 OAuth 授权。
4. 回到客户端，确认可以看到记账工具；首次使用先要求 AI 获取记账上下文。
5. 如工具没有出现、权限被拒绝或域名变更，断开后重新连接并重新授权。不要尝试手工粘贴 Access Token。

可连接的客户端和界面名称会随其产品版本与工作区策略变化。客户端必须支持远程 Streamable HTTP MCP 和 OAuth；连接前也应确认其允许自定义 MCP 服务。

## 日常使用指南

### 多账本使用

后台用户在「记账 → 账本管理」中点击账本行的「切换到此账本」设定当前账本；收支概览、明细、分类设置和账户设置均只显示当前账本的数据。归档账本仍可查看历史记录，但不能再写入。

MCP 客户端应先调用 `list_ledgers` 或不带参数的 `get_bookkeeping_context` 获取账本列表，再在后续所有分类、账户、收支、转账和汇总工具请求中传入对应的 `ledger_id`。不同账本间不能使用普通账户转账。

### 使用原则

- **先选择账本并读取上下文**：每次新的会话先调用 `list_ledgers` 或 `get_bookkeeping_context` 获取账本，再带 `ledger_id` 调用 `get_bookkeeping_context` 获取该账本的日期、分类、账户、默认账户和权限。
- **只记已发生事项**：报价、计划购买、未支付欠款和意向交易都不应记账；应在实际收款或付款后记录。
- **收入与支出分开**：转账只用于账户之间移动资金，不生成收入或支出。
- **金额使用人民币元**：例如 `28.50`；最多两位小数且必须大于零。
- **日期以北京时间为准**：省略日期时系统使用北京时间当天；未来日期不能记账。
- **写入需确认成功**：只有工具结果中的 `ok=true` 才表示记录成功。
- **修正而非重录**：修改或撤销前先查询记录，使用返回的 `id` 和 `revision`，避免覆盖他人已修改的内容。

### 可用工具

| 工具 | 类型 | 说明 |
|---|---|---|
| `get_bookkeeping_context` | 读取 | 不带参数时列出可用账本；带 `ledger_id` 时返回该账本的上下文。 |
| `list_ledgers` | 读取 | 列出可用账本及状态。 |
| `create_ledger` / `get_ledger` / `update_ledger` / `archive_ledger` | 管理 | 创建、查看、编辑或归档账本；创建时初始化分类和默认账户模板。 |
| `list_accounts` | 读取 | 列出指定账本的账户与余额，包括已停用账户。 |
| `create_account` / `update_account` / `toggle_account` / `set_default_account` | 管理 | 管理指定账本内的账户。 |
| `list_categories` | 读取 | 列出指定账本的收入和支出分类（包括已停用分类），用于分类管理和查询分类 ID。 |
| `add_category` | 写入 | 在指定账本新增一级或二级收入、支出分类；需要记账写入权限。 |
| `update_category` | 写入 | 修改指定账本内的分类名称、父级、排序或方向；已有收支记录的分类不能改变方向。 |
| `toggle_category` | 写入 | 启用或停用分类；停用后不能用于新增收支。 |
| `add_entries` | 写入、幂等 | 原子新增 1–50 条已发生收支。 |
| `add_transfer` | 写入、幂等 | 新增一笔已发生的账户间转账。 |
| `add_refund` / `list_refunds` / `update_refund` / `void_refund` | 写入/读取 | 新增、查询、修改或撤销指定账本的退款记录；退款需关联有效原收支，方向自动推断。 |
| `list_entries` | 读取 | 按日期、方向、分类、账户、关键字、作废状态或 `has_refunds`（是否有有效退款）查询账目；每条记录会返回有效退款累计 `refunded_amount` 和净额 `net_amount`。 |
| `get_bookkeeping_summary` | 读取 | 返回收入、支出、期间结余和分类汇总。 |
| `update_entry` | 写入、幂等 | 基于账目 ID 和修订版本修改账目。 |
| `update_transfer` | 写入、幂等 | 基于转账 ID 和修订版本修改转账。 |
| `void_entry` | 写入、幂等 | 撤销账目，保留审计历史。 |
| `void_transfer` | 写入、幂等 | 撤销转账，保留审计历史。 |
| `get_status` | 读取 | 返回平台状态、版本、北京时间和请求编号，不读取业务数据。 |

除账本列表和未选账本的上下文请求外，所有记账业务工具均必须提供 `ledger_id`。所有收支和转账写入工具都使用新的 ULID 格式 `event_id` 防止重复写入；网络重试同一请求时，必须保留**同一个** `event_id` 且参数完全一致；不同业务操作必须使用新的 `event_id`。

退款仅可关联当前活动账本中未撤销的原收支；退款账户也必须启用且属于同一账本。有效退款累计不得超过原金额；有有效退款的原收支不能撤销或把金额调低至累计退款以下。原支出退款降低支出并增加退款账户余额；原收入退款降低收入并减少退款账户余额。报表和趋势按退款实际发生日、并归入原收支分类计算净额；可用时响应还会提供 `income_refunds` 与 `expense_refunds`。

查询有退款的消费时，使用 `list_entries` 并传入 `direction: "expense"` 与 `has_refunds: true`；响应中的 `refunded_amount` 为累计有效退款，`net_amount` 为扣除退款后的实际净额。

分类管理工具不使用 `event_id`。执行新增、修改或启停前，应先调用 `list_categories` 确认分类 ID 和当前状态；分类管理权限与记账写入权限一致。

### 常用对话示例

在已完成 MCP 连接的 AI 客户端中，可以直接使用如下自然语言：

```text
先列出我的账本，选择经营账本并查看其记账上下文，然后记录今天现金收到营业收入 560 元，支付餐饮食材 128.50 元。
```

AI 应先读取上下文，确认分类与账户后，以一个原子写入同时添加两条记录。

```text
把今天从现金转 1,000 元到招商银行卡，这不是收入或支出。
```

AI 应创建转账，而不是创建两笔收支。

```text
查询本月所有餐饮食材支出，并告诉我本月收入、支出和期间结余。
```

AI 应先列表查询（需要明细时）并调用汇总工具。**期间结余不等于账户余额，也不等于利润。**

```text
把刚才那笔食材支出改成 118.50 元；如果找不到记录先问我。
```

AI 应先查询目标记录，带上该记录的 `id`、最新 `revision` 和新的 `event_id` 才能修改。若版本已变化，应重新查询，而不是强行覆盖。

```text
撤销那笔重复记的支出，原因是“重复录入”。
```

AI 应先确认记录，再执行撤销。撤销是保留历史的业务操作，不是物理删除。

## 安全与运维

- 仅通过 HTTPS 对外提供后台和 MCP 端点，并长期维护 PHP、系统包和 TLS 证书。
- `APP_DEBUG` 在生产环境必须为 `false`；`.env`、日志、备份和数据库凭据不得进入仓库。
- 按最小权限授予用户和 MCP 服务访问权；离职或不再使用的人员应及时停用。
- 定期执行并验证数据库备份恢复；至少保留一次应用发布前的可恢复备份。
- 关注 `storage/logs/laravel.log`、Web 服务器错误日志、PHP-FPM 日志和队列进程状态。
- 配置变更后运行 `php artisan optimize`；排障或变更环境变量时可运行 `php artisan optimize:clear` 后再重新缓存。
- 将部署账号、数据库账号和 Web 运行账号分离；不要以 root 身份运行队列进程。

## 本地开发

本项目的 PHP、Composer 和 Artisan 命令在 Docker Compose 的 `app` 服务中执行。项目目录在容器内为 `/var/www/html/develop_mcp`：

```bash
docker compose ps
docker compose exec -T -w /var/www/html/develop_mcp app composer install --no-interaction
docker compose exec -T -w /var/www/html/develop_mcp app php artisan about
docker compose exec -T -w /var/www/html/develop_mcp app php artisan test --compact
```

前端资源在本机或对应的 Node 环境中按项目现有方式构建：

```bash
npm install
npm run dev
```

若 `app` PHP 容器未安装 Node.js/npm，不要在容器内运行 `npm ci`。可在项目根目录使用一次性的 Node 容器生成生产资源：

```bash
docker run --rm \
  --user "$(id -u):$(id -g)" \
  -v "$PWD":/app \
  -w /app \
  node:22-bookworm \
  sh -lc 'npm install && npm run build'

ls -la public/build
```

`--rm` 仅在构建结束后删除临时 Node 容器；`public/build/` 写入的是宿主机项目目录，因此普通的 `docker compose down` 不会删除它。即使执行 `docker compose down -v`，宿主机目录中的构建产物通常仍会保留，但 Docker 命名卷中的数据会被删除。生产环境应将项目目录持久化在宿主机或镜像内，并确认它已挂载到 PHP 容器使用的项目路径；不要只把代码和构建产物保存在可删除的容器文件系统中。

不要在生产数据库上运行测试、`migrate:fresh`、`db:wipe` 或其他会删除/重建数据的命令。

## 许可证

本项目基于 Laravel 生态构建。请根据实际发布策略补充项目许可证信息。
