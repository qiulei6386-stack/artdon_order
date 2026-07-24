# Artdon Procurement Platform（V1.0）

这是一个可直接部署到 PHP 服务器的 **商业照明采购网站原型**。它不是普通“公司介绍官网”，而是以采购任务为首页核心：现货、样品、询价、批量订单、项目包、AI 选型、产品配置、技术资料和客户中心。

## 当前已经完成

- 完整一级导航：Home、Ready Stock、Products、Solutions、Projects、Resources、AI Assistant、About、Support、Contact、Account、Cart。
- Blog / Learn 收入 Resources 大菜单，不额外占一级导航。
- Procurement 通过顶部快捷入口、首页核心模块及独立页面呈现，不挤占一级导航。
- 数据驱动生成 126 个栏目/内容路由、12 个产品详情页与 12 个独立配置页，当前合计 154 个可访问页面，避免复制大量 PHP 文件。
- 采购型首页：产品搜索、上传 BOQ、样品入口、快速询价、项目包、现货、产品系统、Solutions、AI、Resources。
- 产品列表：关键词筛选、侧栏筛选、库存信息、价格、交期、收藏、对比、加入购物车、加入 RFQ。
- 产品详情与配置分离：产品详情页负责展示、规格、资料与 SEO；独立配置页负责功率、色温、角度、颜色、电源、调光、附件、数量、组合规则、动态型号和估算价格。
- 购物车 / Order Basket：多产品、多配置、数量调整、订单申请表。
- AI 工具：Product Finder、照明数量估算、光束角估算、Driver 容量估算、产品对比、AI Consultant 界面。
- 采购表单：Quick RFQ、Sample Order、OEM、ODM、Bulk Order、Project Package、Procurement Service。
- 客户中心：Dashboard、Orders、Quotes、Wishlist、Compare、Downloads、Address、Settings 模板。
- 表单安全基础：CSRF、幂等提交令牌、蜜罐、频率限制、字段验证、附件扩展名/内容/大小限制、随机存储文件名。
- 表单提交先写入 `storage/submissions.jsonl`，便于未接数据库前验证闭环。
- MySQL / MariaDB 正式版基础表结构：产品、选项、组合规则、库存、客户、询价、报价、订单、文件、同步队列、日志。
- 自带 Nginx、Apache、robots、sitemap、健康检查及部署说明。

## 重要说明

当前包是 **可运行的前台和流程原型**，示例库存、价格、产品和账号均为演示数据。它还没有连接：

- Artdon 命名系统产品主数据；
- 广州 ERP / CRM / 报价数据库；
- 真实库存；
- SMTP 邮件服务；
- 客户登录认证；
- 正式后台 CMS；
- 在线支付。

因此不要把演示价格和库存直接作为正式数据上线。建议先部署到测试域名验收视觉与采购流程，再接真实接口。

## 推荐服务器环境

- Nginx 1.22+
- PHP 8.1–8.3，推荐 PHP 8.2
- PHP 扩展：`json`、`session`、`openssl`；推荐启用 `mbstring`、`fileinfo`
- MySQL 8.0 或 MariaDB 10.6+（接正式数据时）
- HTTPS

## 宝塔 / 腾讯云部署

假设网站目录为：

```text
/www/wwwroot/shop.artdonlighting.com/
```

### 1. 上传文件

把本包内所有文件上传到该目录，确认 `index.php` 位于网站根目录。

### 2. 设置 PHP

宝塔网站 → PHP 版本 → 选择 PHP 8.2。

### 3. 配置伪静态

复制根目录 `nginx.conf.example` 中的关键规则，至少保留：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

location ~ ^/(config|includes|templates|storage|database|docs|preview|tools)(?:/|$) {
    deny all;
    return 404;
}
```

PHP-FPM socket 以宝塔实际配置为准，例如：

```nginx
fastcgi_pass unix:/tmp/php-cgi-82.sock;
```

### 4. 设置写入权限

```bash
cd /www/wwwroot/shop.artdonlighting.com
chown -R www:www storage
chmod -R 750 storage
```

如服务器 PHP 用户不是 `www`，请换成实际 PHP-FPM 用户。

### 5. SSL 与域名

- 域名：`shop.artdonlighting.com`
- A / CNAME 解析指向新加坡服务器。
- 宝塔申请并部署 SSL。
- 强制 HTTPS。

### 6. 健康检查

打开：

```text
https://shop.artdonlighting.com/api/health.php
```

正常应返回：

```json
{"status":"ok","service":"Artdon Procurement Platform","version":"V1.0"}
```

### 7. 测试表单

提交 Contact 或 Quick RFQ 后，服务器会生成：

```text
storage/submissions.jsonl
```

上传文件会保存到：

```text
storage/uploads/YYYY/MM/
```

`storage` 必须禁止浏览器直接访问。

## 环境变量

参考 `.env.example`。此项目不会自动读取 `.env`，应在 PHP-FPM 或服务器环境中配置：

```text
APP_BASE_PATH=
CONTACT_EMAIL=sales@artdonlighting.com
ORDER_EMAIL=orders@artdonlighting.com
CONTACT_PHONE=
WHATSAPP_NUMBER=
CONTACT_ADDRESS=Hong Kong sales · Zhongshan manufacturing
ENABLE_PHP_MAIL=false
```

正式邮件建议接 SMTP / 邮件服务 API，不建议长期依赖 PHP `mail()`。

## 项目目录

```text
assets/        CSS、JavaScript、本地 SVG 图片
api/           表单、目录和健康检查接口
config/        网站、栏目和演示产品数据
database/      正式版数据库基础表结构
includes/      路由、组件、页头和页脚
templates/     各类共享页面模板
storage/       表单和附件存储，必须禁止公开访问
docs/          页面地图、部署和 ERP 接入说明
index.php      前端路由入口
```

## 正式接入顺序

1. **产品主数据**：命名系统只保留一套型号主数据，商品站通过签名 API 拉取。
2. **媒体资料**：官网、商品站、资料中心读取同一个媒体库和版本记录。
3. **库存**：由 ERP / 仓库接口同步，不让前端直接访问广州数据库。
4. **价格规则**：按客户等级、数量、配置、国家、币种、贸易条款计算。
5. **组合规则**：建立允许、禁止、依赖、推荐、加价、加交期和人工审核规则。
6. **询价与订单**：新加坡本地先保存，再进入同步队列推送广州 CRM / 报价系统。
7. **客户中心**：统一账号、公司多人联系人、历史报价、订单、地址和下载记录。
8. **邮件**：提交、内部审核、报价发出、客户确认、订单版本变更分别使用模板邮件。

## 验收重点

- 首页是否首先解决采购任务，而不是公司介绍。
- 一级导航是否保持用户确认的结构。
- Driver、Track Rail、Accessories、Spare Parts 是否作为正式产品类别。
- 产品配置是否能禁用无法生产的组合。
- 客户提交后是否是“待审核申请”，而不是自动正式订单。
- 商品修改后，历史申请、报价和订单快照是否不变。
- 同一订单重复点击或同步重试是否不会重复建单。
- 新加坡服务器断开广州连接时，客户提交是否仍可成功保存。

## 本地运行

```bash
cd artdon_procurement_v1
php -S 127.0.0.1:8080 router.php
```

然后打开：

```text
http://127.0.0.1:8080/
```

PHP 内置服务器不能完全模拟 Nginx 的安全规则，仅用于本地预览。
