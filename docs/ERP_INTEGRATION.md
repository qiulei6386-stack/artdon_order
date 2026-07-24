# 新加坡商品站 ↔ 广州 ERP / CRM 接入方案

## 总体原则

新加坡服务器不能直接连接广州生产数据库。正确方式是：

```text
客户浏览与提交
    ↓
新加坡商品站本地事务保存
    ↓
生成唯一幂等键与同步任务
    ↓
签名 API 推送广州集成层
    ↓
CRM 客户匹配 / 暂存池
    ↓
报价系统审核
    ↓
报价 / PI / 正式订单状态回传
```

即使广州服务器暂时不可用，客户提交也必须成功保存在新加坡，并由队列自动重试。

## API 建议

### 产品拉取

```text
GET /integration/v1/products?updated_after=...
```

返回：产品主键、型号、系列、分类、参数、图片、尺寸图、可销售状态、更新时间和版本。

### 产品规则拉取

```text
GET /integration/v1/products/{source_id}/rules
```

返回：可选项、默认值、禁止组合、依赖组合、加价、加交期和人工审核规则。

### 库存拉取

```text
GET /integration/v1/inventory?updated_after=...
```

返回：仓库、型号 / 变体、现存量、预留量、可用量、批次、预计可发日期。

### 采购申请推送

```text
POST /integration/v1/procurement-requests
Idempotency-Key: shop:WO-20260722-0001
X-Artdon-Timestamp: ...
X-Artdon-Signature: HMAC-SHA256(...)
```

广州端根据幂等键只创建一次记录。

### 状态回传

```text
POST /webhooks/v1/quote-updated
POST /webhooks/v1/order-updated
POST /webhooks/v1/shipment-updated
```

新加坡站校验签名后更新客户中心。

## 客户匹配

匹配顺序建议：

1. CRM 客户 ID；
2. 已验证公司域名；
3. 联系人邮箱；
4. 标准化公司名称 + 国家；
5. 近似匹配进入暂存池，不自动合并。

## 两次快照

### 客户提交快照

保存产品名称、型号、图片、参数、配置、数量、备注、文件、提交时间。

### 业务确认快照

保存最终型号、单价、币种、数量、折扣、运费、税费、交期、付款、贸易条款和有效期。

任何产品、价格或 BOM 后续修改，不得改变历史快照。

## 同步队列

数据库 `sync_jobs` 已预留：

- `pending`：等待发送；
- `running`：正在处理；
- `success`：已成功；
- `failed`：等待重试；
- `dead`：超过重试上限，需要人工处理。

重试建议：1 分钟、5 分钟、15 分钟、1 小时、6 小时、24 小时。

## 安全

- 双方只开放 HTTPS API。
- 使用 HMAC 签名、时间戳和重放窗口。
- 每个请求带唯一幂等键。
- API 凭证按环境分开，定期轮换。
- 附件使用临时授权下载链接，不在接口里传公开永久地址。
- 记录请求、响应、耗时、结果和错误，但日志不保存明文密码或敏感支付信息。
