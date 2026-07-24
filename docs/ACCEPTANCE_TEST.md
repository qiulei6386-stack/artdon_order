# Artdon Procurement Platform V1.0 — 本地验收记录

验收日期：2026-07-22（Asia/Singapore）

## 页面与路由

- 数据驱动栏目/内容路由：126
- 示例产品详情页：12
- 独立产品配置页：12
- 首页、Contact、Cart、Sitemap：4
- 合计：154 个可访问页面
- 全路由 HTTP 检查：154 / 154 返回 200，失败 0

## 关键页面

- `/`：采购型首页正常
- `/ready-stock`：现货入口正常
- `/products/track-lighting/track-spot`：三级产品分类正常
- `/solutions/retail`：Solution SEO 模板正常
- `/product/AL1010`：产品详情、规格、资料、应用和配置入口正常
- `/configure/AL1010`：独立配置、动态型号、价格估算和加入订单正常
- `/ai/lighting-calculator`：AI 工具模板正常
- `/procurement/quick-rfq`：采购表单正常
- `/account/dashboard`：客户中心模板正常
- `/cart`：订单篮正常

## 浏览器交互

在 Chromium 中以桌面和手机视口执行：

- 产品初始动态型号正常生成
- 20W 自动排除 15°，并切换至 24°
- DALI-2 自动排除不兼容的 Lifud 演示组合，并切换至 Tridonic
- 60° 自动排除 Honeycomb 演示组合
- 产品缩略图可切换主图
- 配置产品按 MOQ 加入订单篮，数量正确
- 产品详情页可进入独立配置页
- 手机菜单可展开
- 首页采购搜索 Tab 可切换
- JavaScript 页面错误：0
- 产品列表库存、清仓、光束角筛选及价格排序正常

## 表单与文件

- 合法 RFQ / Sample 表单：HTTP 200，生成唯一参考编号
- 同一提交令牌重复发送：只写入 1 条记录，返回同一参考编号
- 合法附件：可写入随机文件名目录
- 无效 CSRF：HTTP 419
- 伪装成 JPG 的 PHP 内容：HTTP 422
- `storage`、`config`、`includes`、`templates`、`database`、`docs`、`preview` 等受保护路径：HTTP 404

## 代码检查

- 所有 PHP 文件通过 `php -l`
- `assets/js/app.js` 通过 `node --check`
- 健康检查 `/api/health.php` 返回 V1.0 和 Asia/Singapore 时间
- Catalog API 与 Sitemap 返回 HTTP 200

## 上线前仍需完成

本记录验证的是 V1.0 可运行原型。正式上线前仍需连接真实产品主数据、库存、价格规则、SMTP、CRM/报价接口、客户认证、后台 CMS，并由公司确认最终联系方式、贸易条款、隐私政策和法律文本。
